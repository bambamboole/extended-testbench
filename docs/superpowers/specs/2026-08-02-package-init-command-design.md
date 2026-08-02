# extended-testbench: `package:init` scaffolding command

**Date:** 2026-08-02
**Status:** Approved
**Package:** `bambamboole/extended-testbench`

## Problem

Starting a Laravel package means re-creating the same dev setup by hand every time: Pest with a
Testbench `TestCase`, a `phpunit.xml.dist` with an in-memory database, `testbench.yaml`, and the
usual quality tooling (PHPStan/Larastan, Rector, Pint) with their config files and composer
scripts. It is thirty minutes of copy-paste from the last package, and the copies drift.

This package already owns the "Laravel package development under Testbench" niche and is installed
as a dev dependency before any of that setup exists. It is the natural place to put a one-shot
scaffolder.

## Solution

A single interactive command, `vendor/bin/testbench package:init` (or `php artisan package:init`
through the artisan symlink this package creates), that installs dev dependencies and writes the
matching config files into the *package root*, asking before each optional section and before
overwriting anything.

Pest 5 is not optional — it is the baseline the command exists to set up. Everything else is a
prompt.

## Constraints

- **PHP `^8.4` only** (8.4 and 8.5). This package bumps `require.php` from `^8.2` to `^8.4`.
  Pest 5 requires PHP `^8.4` and PHPUnit `^13`, so the baseline the command installs cannot run
  anywhere else.
- Consequently `package:init` only produces a working setup on `orchestra/testbench ^10.10|^11`
  (earlier versions do not allow PHPUnit 13). No preflight check is written: `composer require`
  fails with its own conflict message, which is clear enough. Deliberately skipped, add only if
  the solver output turns out to confuse people.
- `pestphp/pest-plugin-laravel` is **not** installed — v5 pins `laravel/framework ^13.23`, which
  would lock every consumer to Laravel 13. Testbench already provides the Laravel test case.

## Architecture

### Registration

`src/Commands/InitCommand.php`. The provider registers it *outside* the existing boost/mcp gate,
so the gate logic and its test stay untouched:

```php
public function register(): void
{
    if ($this->app->runningInConsole() && function_exists('Orchestra\Testbench\package_path')) {
        $this->app->singleton(InitCommand::class, fn () => new InitCommand(
            new Composer(new Filesystem, package_path()),
            package_path(),
        ));
        $this->commands([InitCommand::class]);
    }

    if (! $this->shouldActivate()) {
        return;
    }

    // ... existing boost rebase, unchanged
}
```

The package root and the `Illuminate\Support\Composer` instance are constructor-injected. Two
consequences: the command never depends on the boost base-path rebase (it resolves every path from
its injected root), and the test can drive it against a temporary directory with a mocked
`Composer`.

### Dependency installation

`Illuminate\Support\Composer`, constructed with `workingPath = package_path()`:

- `hasPackage($name)` — skip anything already in `require`/`require-dev`.
- `requirePackages([...], dev: true, output: $this->output)` — streams composer output.
- `modify(callable)` — adds composer `scripts` entries (and the `autoload-dev.psr-4` test
  namespace when it is missing). Present in Laravel 11, 12 and 13.

### Prompts

`laravel/prompts:^0.3` is added to `require` (used directly: `intro`, `confirm`, `select`, `note`,
`table`, `outro`).

## Flow

| Step | Prompt | Installs (`--dev`) | Writes |
|---|---|---|---|
| Pest | *always* | `pestphp/pest:^5.0` | `phpunit.xml.dist`, `tests/Pest.php`\*, `tests/TestCase.php`\*, `testbench.yaml`\*, script `test` |
| Browser | `confirm('Add browser tests?')` | `pestphp/pest-plugin-browser:^5.0` | `tests/Browser/DummyTest.php`, `Browser` testsuite in `phpunit.xml.dist`, appends `uses(TestCase::class)->in('Browser');` to `tests/Pest.php` |
| Playwright | `confirm('Install Playwright browsers now?')` — only if browser accepted | — | runs `npx playwright install` |
| PHPStan | `confirm('Add PHPStan?')` + `select('Level', [5,6,7,8,9,'max'], default: 6)` | `larastan/larastan:^3.0`, `pestphp/pest-plugin-phpstan:^5.0` | `phpstan.neon.dist`, script `stan` |
| Rector | `confirm('Add Rector?')` | `rector/rector:^2.0` | `rector.php`, script `refactor` |
| Pint | `confirm('Add Pint?')` | `laravel/pint:^1.16` | `pint.json`, script `lint` |

\* written only when missing.

**Overwrite policy:** every write checks `file_exists()` first and asks
`confirm("Overwrite <file>?", default: false)`. Declining skips that file only; the step continues.
Nothing is ever replaced silently.

**Summary:** the command ends with a `table()` of every path and its outcome (written / skipped /
overwritten), then `outro()`.

## Generated files

### `phpunit.xml.dist`

Testsuites `Unit` and `Feature` (plus `Browser` when accepted), `bootstrap="vendor/autoload.php"`,
`cacheDirectory=".phpunit.cache"`, and:

```xml
<env name="APP_KEY" value="base64:…"/>        <!-- freshly generated, random_bytes(32) -->
<env name="APP_DEBUG" value="true"/>
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
<env name="CACHE_STORE" value="array"/>
<env name="SESSION_DRIVER" value="array"/>
<env name="MAIL_MAILER" value="array"/>
<env name="QUEUE_CONNECTION" value="sync"/>
<env name="BCRYPT_ROUNDS" value="4"/>
```

`DB_CONNECTION=sqlite` + `:memory:` rather than `testing`: the Testbench skeleton's
`config/database.php` defines no `testing` connection.

In-memory works for browser tests too — `pest-plugin-browser`'s `ServerManager::http()` returns
`LaravelHttpServer`, an in-process amp server that resolves the HTTP kernel from the live test
container, so there is no second process and no need for a file-backed database.

### `tests/Pest.php` (when missing)

```php
uses(Tests\TestCase::class)->in('Feature', 'Unit');
```

`'Browser'` is added to that same `->in(...)` list when browser tests are accepted and the file is
being created fresh.

When `tests/Pest.php` already exists and browser tests are accepted, the command **appends** a line
instead of rewriting the file:

```php
uses(Tests\TestCase::class)->in('Browser');
```

The test-case FQCN comes from the detected test namespace. Appending is skipped when the file
already mentions `'Browser'`, and it avoids parsing whatever `uses()` / `pest()->extend()` style
the target already uses.

### `tests/TestCase.php` (when missing)

Extends `Orchestra\Testbench\TestCase` with `getPackageProviders()` returning the providers found
in the target's `composer.json` `extra.laravel.providers`. The namespace comes from
`autoload-dev.psr-4`: the entry mapping to `tests/` is reused; if there is none, `Tests\` is used
and the mapping is added via `Composer::modify()`.

### `testbench.yaml` (when missing)

```yaml
laravel: '@testbench'

providers:
  - <detected provider FQCN>
```

The `providers` key is omitted when `extra.laravel.providers` is empty.

### `tests/Browser/DummyTest.php`

Self-contained so it passes against a skeleton with no routes:

```php
it('renders a route in the browser', function () {
    Route::get('/dummy', fn () => 'Hello from extended-testbench');

    visit('/dummy')->assertSee('Hello from extended-testbench');
});
```

### `phpstan.neon.dist`

Explicit includes instead of `phpstan/extension-installer`, which would require an `allow-plugins`
entry and an extra prompt:

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/pestphp/pest/extension.neon
    - vendor/pestphp/pest-plugin-phpstan/extension.neon

parameters:
    level: 6            # from the select
    paths:
        - src
        - tests
```

`pestphp/pest-plugin-phpstan` is the Pest 5 PHPStan extension (types `$this` inside test closures);
`pestphp/pest` ships its own `extension.neon` at package root as well.

### `rector.php`

```php
return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    ->withPhpSets()
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true);
```

### `pint.json`

The `laravel` preset with `declare_strict_types` enabled, matching this repository's own config.

## Composer scripts

Added via `Composer::modify()`, only for accepted sections, never clobbering an existing key of the
same name:

```json
"test": "pest",
"lint": "pint --format agent",
"stan": "phpstan analyse",
"refactor": "rector"
```

## Testing

One feature test, `tests/Feature/InitCommandTest.php`:

- Answers are scripted with `$this->artisan('package:init')->expectsConfirmation(...)` /
  `->expectsChoice(...)`. `Laravel\Prompts\Prompt::fake()` does **not** work here: `Command::run()`
  calls `configurePrompts()`, which sets `Prompt::fallbackWhen(windows_os() || runningUnitTests())`,
  so under any Testbench suite every prompt takes the Symfony fallback and returns its default,
  ignoring faked key presses. Laravel's `expects*()` helpers drive that same fallback.
- Each test first binds a temp-rooted command instance —
  `app()->instance(InitCommand::class, new InitCommand($composerDouble, $tempRoot))`. This is
  mandatory: the provider's binding roots the command at `package_path()`, which during this
  package's own suite is this repository, so resolving it would write real files and run a real
  `composer require`.
- The `Composer` double is a Mockery partial (`[requirePackages,dumpAutoloads]`) constructed with
  the temp root, so `hasPackage()` and `modify()` really run against the temp `composer.json` while
  the two shelling-out methods are stubbed.
- Assertions: the expected files exist under the temp root with the expected key contents
  (`:memory:` in the phpunit config, the `Browser` line in `Pest.php`, the accepted-only files
  absent when declined), and existing files are left untouched when the overwrite prompt is
  declined.

## Out of scope

- CI workflow files, `.gitignore`, `README` scaffolding, license headers.
- A non-interactive `--no-interaction` preset flag. Add when someone wants init in CI.
- Updating an existing setup (a `package:upgrade`). This command scaffolds, it does not migrate.
