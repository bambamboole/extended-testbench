# boost-testbench Bridge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A `bambamboole/boost-testbench` package that makes Laravel Boost work in package repos developed with Orchestra Testbench via a single `composer require --dev`.

**Architecture:** One auto-discovered service provider. When (and only when) a `boost:*`/`mcp:*` command runs under the Testbench CLI, it rebases the booted app's base path to the package root (with skeleton-derived paths pinned first), ensures an `artisan → vendor/bin/testbench` symlink for the MCP entrypoint, and defaults the cache store to `array`. All ~25 `base_path()` sites in Boost then resolve to the package root; Boost internals are never subclassed or rebound.

**Tech Stack:** PHP ^8.2, laravel/boost ^2.4 (only runtime dep), orchestra/testbench + pest + pint as dev deps.

**Spec:** `docs/superpowers/specs/2026-08-02-boost-testbench-bridge-design.md` (committed in this repo).

## Global Constraints

- Repo root: `/Users/manuel.christlieb/Projects/boost-testbench` (git already initialized, spec committed). Run all commands from there.
- `require`: `"php": "^8.2"`, `"laravel/boost": "^2.4"` — nothing else. **No orchestra/* in `require`** (guard with `function_exists` at runtime).
- No config file, no facade, no published assets. One provider + one support class.
- Namespace `Bambamboole\BoostTestbench`, PSR-4 from `src/`.
- Commits: conventional-commit subjects (`feat:`, `test:`, `chore:`, `docs:`). **Never add `Co-Authored-By` or any agent attribution.**
- No code comments; self-explanatory names only. PHPDoc only for types (e.g. `@param list<string>`).
- Run `vendor/bin/pint --dirty --format agent` before every commit.
- Local PHP is 8.3 (`php`); Herd's `php84` exists if a dependency needs PHP 8.4. Default to plain `php`/`composer` — composer will resolve versions that fit 8.3.

---

### Task 1: Scaffold the package

**Files:**
- Create: `composer.json`
- Create: `.gitignore`
- Create: `pint.json`
- Create: `phpunit.xml.dist`
- Create: `testbench.yaml`
- Create: `tests/Pest.php`
- Create: `tests/TestCase.php`

**Interfaces:**
- Produces: installable composer package with `Bambamboole\BoostTestbench\` autoloading, Pest wired to an Orchestra Testbench `Tests\TestCase`, `composer test` and `composer lint` scripts. Later tasks rely on `Tests\TestCase::getPackageProviders()` returning `[BoostTestbenchServiceProvider::class]` and on `vendor/bin/testbench` existing.

- [ ] **Step 1: Write `composer.json`**

```json
{
    "name": "bambamboole/boost-testbench",
    "description": "Use Laravel Boost in package development with Orchestra Testbench - zero configuration.",
    "keywords": ["laravel", "boost", "testbench", "package-development", "ai"],
    "license": "MIT",
    "require": {
        "php": "^8.2",
        "laravel/boost": "^2.4"
    },
    "require-dev": {
        "laravel/pint": "^1.16",
        "orchestra/testbench": "^9.15|^10.6|^11.0",
        "pestphp/pest": "^3.8|^4.0"
    },
    "autoload": {
        "psr-4": {
            "Bambamboole\\BoostTestbench\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    },
    "extra": {
        "laravel": {
            "providers": [
                "Bambamboole\\BoostTestbench\\BoostTestbenchServiceProvider"
            ]
        }
    },
    "scripts": {
        "post-autoload-dump": "@prepare",
        "prepare": "@php vendor/bin/testbench package:discover --ansi",
        "lint": "pint --format agent",
        "test": "pest"
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "pestphp/pest-plugin": true
        }
    },
    "minimum-stability": "stable"
}
```

- [ ] **Step 2: Write `.gitignore`**

```
/vendor/
composer.lock
.phpunit.cache/
/artisan
/CLAUDE.md
/boost.json
/.ai/
```

(`artisan`, `CLAUDE.md`, `boost.json`, `.ai/` are created/removed by the E2E test in Task 4; ignoring them keeps `git status` clean if a test aborts mid-run.)

- [ ] **Step 3: Write `pint.json`**

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true
    }
}
```

- [ ] **Step 4: Write `phpunit.xml.dist`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="APP_KEY" value="base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10="/>
        <env name="APP_DEBUG" value="true"/>
        <env name="CACHE_STORE" value="array"/>
    </php>
</phpunit>
```

- [ ] **Step 5: Write `testbench.yaml`**

The bridge's own provider is the package-under-development here, so it is not in `vendor/` and must be listed explicitly (consuming packages get it auto-discovered instead):

```yaml
laravel: '@testbench'

providers:
  - Bambamboole\BoostTestbench\BoostTestbenchServiceProvider
```

- [ ] **Step 6: Write `tests/TestCase.php`**

```php
<?php

declare(strict_types=1);

namespace Tests;

use Bambamboole\BoostTestbench\BoostTestbenchServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BoostTestbenchServiceProvider::class];
    }
}
```

- [ ] **Step 7: Write `tests/Pest.php`**

```php
<?php

declare(strict_types=1);

uses(Tests\TestCase::class)->in('Feature');
```

- [ ] **Step 8: Create a placeholder provider so `composer install` autoloading and `package:discover` don't fail**

Create `src/BoostTestbenchServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Bambamboole\BoostTestbench;

use Illuminate\Support\ServiceProvider;

class BoostTestbenchServiceProvider extends ServiceProvider
{
    public function register(): void {}
}
```

- [ ] **Step 9: Install and verify**

Run: `mkdir -p tests/Unit tests/Feature && touch tests/Unit/.gitkeep tests/Feature/.gitkeep`
(phpunit errors on testsuite directories that do not exist yet)

Run: `composer install`
Expected: resolves laravel/boost ^2.4 + testbench + pest without conflicts; `prepare` script runs `package:discover` successfully. If resolution fails on PHP version, retry with `php84 $(which composer) install`.

Run: `vendor/bin/pest`
Expected: no failing tests — a "no tests executed" notice is fine and its exit code may be non-zero; only actual errors/failures are a problem.

- [ ] **Step 10: Commit**

```bash
git add -A && git commit -m "chore: scaffold package"
```

---

### Task 2: Command gate

**Files:**
- Modify: `src/BoostTestbenchServiceProvider.php`
- Test: `tests/Unit/CommandGateTest.php`

**Interfaces:**
- Produces: `BoostTestbenchServiceProvider::isBoostCommand(array $argv): bool` (public static). Task 4's `shouldActivate()` calls it with `$_SERVER['argv'] ?? []`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/CommandGateTest.php`:

```php
<?php

declare(strict_types=1);

use Bambamboole\BoostTestbench\BoostTestbenchServiceProvider;

test('boost and mcp commands activate the bridge', function (array $argv) {
    expect(BoostTestbenchServiceProvider::isBoostCommand($argv))->toBeTrue();
})->with([
    'boost:install' => [['testbench', 'boost:install']],
    'boost:update' => [['testbench', 'boost:update', '--no-interaction']],
    'boost:mcp' => [['testbench', 'boost:mcp']],
    'boost:execute-tool' => [['testbench', 'boost:execute-tool', 'SomeTool', 'e30=']],
    'mcp:start' => [['testbench', 'mcp:start', 'laravel-boost']],
]);

test('other commands do not activate the bridge', function (array $argv) {
    expect(BoostTestbenchServiceProvider::isBoostCommand($argv))->toBeFalse();
})->with([
    'package:test' => [['testbench', 'package:test']],
    'workbench:build' => [['testbench', 'workbench:build']],
    'bare invocation' => [['testbench']],
    'empty argv' => [[]],
]);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Unit/CommandGateTest.php`
Expected: FAIL — `isBoostCommand` does not exist.

- [ ] **Step 3: Implement the gate method**

Add to `src/BoostTestbenchServiceProvider.php`:

```php
    /**
     * @param  list<string>  $argv
     */
    public static function isBoostCommand(array $argv): bool
    {
        $command = $argv[1] ?? '';

        return str_starts_with($command, 'boost:') || str_starts_with($command, 'mcp:');
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Unit/CommandGateTest.php`
Expected: PASS (9 assertions).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add boost command gate"
```

---

### Task 3: Path rebase with skeleton pins

**Files:**
- Create: `src/PackageRootRebase.php`
- Test: `tests/Feature/PackageRootRebaseTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks (pure Laravel `Application` manipulation).
- Produces: `PackageRootRebase::apply(\Illuminate\Foundation\Application $app, string $packageRoot): void` (static). Task 4's provider calls it with `package_path()`.

**Background for the implementer:** `Application::setBasePath()` re-runs `bindPathsInContainer()`, which force-rederives the bootstrap and lang paths from the new base (`vendor/laravel/framework/src/Illuminate/Foundation/Application.php:422-441`). Paths set via `use*Path()` survive rebasing (`$this->configPath ?: $this->basePath('config')` pattern) — but bootstrap/lang get clobbered, so **all pins must be re-applied after `setBasePath()`**, which is why the implementation captures first and applies after.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PackageRootRebaseTest.php`:

```php
<?php

declare(strict_types=1);

use Bambamboole\BoostTestbench\PackageRootRebase;

test('base path moves to the package root while skeleton paths stay pinned', function () {
    $app = $this->app;
    $packageRoot = dirname(__DIR__, 2);

    $skeleton = [
        'storage' => $app->storagePath(),
        'config' => $app->configPath(),
        'database' => $app->databasePath(),
        'bootstrap' => $app->bootstrapPath(),
        'lang' => $app->langPath(),
        'public' => $app->publicPath(),
    ];

    PackageRootRebase::apply($app, $packageRoot);

    expect($app->basePath())->toBe($packageRoot)
        ->and(base_path('composer.json'))->toBe($packageRoot.DIRECTORY_SEPARATOR.'composer.json')
        ->and($app->storagePath())->toBe($skeleton['storage'])
        ->and($app->configPath())->toBe($skeleton['config'])
        ->and($app->databasePath())->toBe($skeleton['database'])
        ->and($app->bootstrapPath())->toBe($skeleton['bootstrap'])
        ->and($app->langPath())->toBe($skeleton['lang'])
        ->and($app->publicPath())->toBe($skeleton['public']);
});

test('app path points at src when it exists', function () {
    $app = $this->app;
    $packageRoot = dirname(__DIR__, 2);

    PackageRootRebase::apply($app, $packageRoot);

    expect($app->path())->toBe($packageRoot.DIRECTORY_SEPARATOR.'src');
});

test('app path is left alone when the package has no src directory', function () {
    $app = $this->app;

    PackageRootRebase::apply($app, sys_get_temp_dir());

    expect($app->path())->toBe(sys_get_temp_dir().DIRECTORY_SEPARATOR.'app');
});
```

Note on the third test: with no `src/`, the app path is not pinned, so it derives from the new base — that is the acceptable default, and the assertion documents it.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/PackageRootRebaseTest.php`
Expected: FAIL — class `PackageRootRebase` not found.

- [ ] **Step 3: Implement**

Create `src/PackageRootRebase.php`:

```php
<?php

declare(strict_types=1);

namespace Bambamboole\BoostTestbench;

use Illuminate\Foundation\Application;

final class PackageRootRebase
{
    public static function apply(Application $app, string $packageRoot): void
    {
        $pins = [
            'useStoragePath' => $app->storagePath(),
            'useConfigPath' => $app->configPath(),
            'useDatabasePath' => $app->databasePath(),
            'useBootstrapPath' => $app->bootstrapPath(),
            'useLangPath' => $app->langPath(),
            'usePublicPath' => $app->publicPath(),
        ];

        $app->setBasePath($packageRoot);

        foreach ($pins as $method => $path) {
            $app->{$method}($path);
        }

        $srcPath = $packageRoot.DIRECTORY_SEPARATOR.'src';

        if (is_dir($srcPath)) {
            $app->useAppPath($srcPath);
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/pest tests/Feature/PackageRootRebaseTest.php`
Expected: PASS (3 tests). Also run the full suite (`vendor/bin/pest`) to confirm the app mutation does not leak between tests (Testbench rebuilds the app per test).

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: add package root rebase with skeleton path pins"
```

---

### Task 4: Provider activation wiring + artisan entrypoint + end-to-end proof

**Files:**
- Modify: `src/BoostTestbenchServiceProvider.php`
- Test: `tests/Feature/BoostUpdateEndToEndTest.php`

**Interfaces:**
- Consumes: `BoostTestbenchServiceProvider::isBoostCommand(array $argv): bool` (Task 2), `PackageRootRebase::apply(Application $app, string $packageRoot): void` (Task 3).
- Produces: the finished provider. Activation requires ALL of: `TESTBENCH_CORE` defined (only the testbench binary defines it), not `runningUnitTests()`, `Orchestra\Testbench\package_path` function exists, argv gate passes.

**How the E2E test proves the rebase:** `boost:update` first calls `Config::isValid()` + `Config::getAgents()`, which read `base_path('boost.json')`. The test seeds `boost.json` at the *repo root*. Without the rebase, Boost looks in the Testbench skeleton, finds nothing, and errors out ("Please set up Boost with [php artisan boost:install] first.") — so a zero exit code plus a generated `CLAUDE.md` is only reachable through the rebased path.

- [ ] **Step 1: Write the failing E2E test**

Create `tests/Feature/BoostUpdateEndToEndTest.php`:

```php
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->root = dirname(__DIR__, 2);
    $this->skeleton = $this->root.'/vendor/orchestra/testbench-core/laravel';

    file_put_contents($this->root.'/boost.json', json_encode([
        'agents' => ['claude_code'],
        'guidelines' => true,
        'mcp' => false,
        'skills' => [],
    ], JSON_PRETTY_PRINT).PHP_EOL);

    foreach (['/CLAUDE.md', '/artisan'] as $file) {
        @unlink($this->root.$file);
    }

    foreach (['/CLAUDE.md', '/boost.json'] as $file) {
        @unlink($this->skeleton.$file);
    }
});

afterEach(function () {
    foreach (['/boost.json', '/CLAUDE.md', '/artisan'] as $file) {
        @unlink($this->root.$file);
    }

    \Illuminate\Support\Facades\File::deleteDirectory($this->root.'/.ai');
});

test('boost:update writes guidelines to the package root, never the skeleton', function () {
    $process = new Process(
        [PHP_BINARY, 'vendor/bin/testbench', 'boost:update', '--no-interaction', '--no-discover'],
        $this->root,
        ['APP_ENV' => 'local', 'APP_DEBUG' => 'true'],
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput())
        ->and($this->root.'/CLAUDE.md')->toBeFile()
        ->and(file_get_contents($this->root.'/CLAUDE.md'))->toContain('=== foundation rules ===')
        ->and(is_link($this->root.'/artisan'))->toBeTrue()
        ->and(readlink($this->root.'/artisan'))->toBe('vendor/bin/testbench')
        ->and(file_exists($this->skeleton.'/CLAUDE.md'))->toBeFalse()
        ->and(file_exists($this->skeleton.'/boost.json'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/pest tests/Feature/BoostUpdateEndToEndTest.php`
Expected: FAIL — exit code non-zero and/or no `CLAUDE.md` at root, because the provider's `register()` is still empty, so Boost reads the skeleton and errors with "Please set up Boost … first."

- [ ] **Step 3: Implement the full provider**

Replace `src/BoostTestbenchServiceProvider.php` content (keeping `isBoostCommand` from Task 2):

```php
<?php

declare(strict_types=1);

namespace Bambamboole\BoostTestbench;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

use function Orchestra\Testbench\package_path;

class BoostTestbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->shouldActivate()) {
            return;
        }

        $app = $this->app;
        assert($app instanceof Application);

        PackageRootRebase::apply($app, package_path());
        $this->ensureArtisanEntrypoint();

        config(['cache.default' => 'array']);
    }

    /**
     * @param  list<string>  $argv
     */
    public static function isBoostCommand(array $argv): bool
    {
        $command = $argv[1] ?? '';

        return str_starts_with($command, 'boost:') || str_starts_with($command, 'mcp:');
    }

    private function shouldActivate(): bool
    {
        return defined('TESTBENCH_CORE')
            && ! $this->app->runningUnitTests()
            && function_exists('Orchestra\Testbench\package_path')
            && self::isBoostCommand($_SERVER['argv'] ?? []);
    }

    private function ensureArtisanEntrypoint(): void
    {
        $artisan = package_path('artisan');

        if (file_exists($artisan) || is_link($artisan)) {
            return;
        }

        if (! @symlink('vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'testbench', $artisan)) {
            fwrite(STDERR, 'boost-testbench: could not create the artisan symlink; run: ln -s vendor/bin/testbench artisan'.PHP_EOL);
        }
    }
}
```

- [ ] **Step 4: Run the E2E test to verify it passes**

Run: `vendor/bin/pest tests/Feature/BoostUpdateEndToEndTest.php`
Expected: PASS.

Troubleshooting if it still fails (check the process output the assertion prints):
- "Please set up Boost … first" → the rebase did not run; verify `TESTBENCH_CORE` gate and that `testbench.yaml` lists the provider.
- Command "boost:update" is not defined → Boost's own provider bailed its environment gate (`BoostServiceProvider::shouldRun()` requires `local` env or debug); confirm the `APP_ENV=local` env reaches the subprocess.
- Cache/database connection errors → the `config(['cache.default' => 'array'])` line is missing or ran after resolution.

- [ ] **Step 5: Run the whole suite**

Run: `vendor/bin/pest`
Expected: all tests pass (unit gate tests, rebase tests, E2E).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add -A && git commit -m "feat: activate boost path rebase and artisan entrypoint under testbench"
```

---

### Task 5: README and publishing

**Files:**
- Create: `README.md`
- Create: `LICENSE.md`

**Interfaces:**
- Consumes: final behavior from Task 4 (for accurate docs).
- Produces: publishable repo.

- [ ] **Step 1: Write `README.md`**

```markdown
# boost-testbench

Use [Laravel Boost](https://github.com/laravel/boost) in Laravel **package** development with
[Orchestra Testbench](https://github.com/orchestral/testbench) — zero configuration.

## The problem

Boost assumes it runs inside a full Laravel app and resolves everything via `base_path()`.
Under Testbench, `base_path()` is the vendor skeleton (`vendor/orchestra/testbench-core/laravel`),
so `boost:install` / `boost:update` read config from and write skills, guidelines state, and
`boost.json` into your `vendor/` directory, and Roster detects zero packages.
Upstream declined package support (laravel/boost#746, laravel/boost#855) and recommended
an additional package — this is that package.

## Install

```bash
composer require --dev bambamboole/boost-testbench
```

That's it. The provider is auto-discovered by `testbench package:discover`.

## Use

```bash
vendor/bin/testbench boost:install
vendor/bin/testbench boost:update --no-interaction
```

Everything lands in your package repo: `CLAUDE.md` / `AGENTS.md`, `boost.json`, `.ai/`,
agent skill directories. Roster scans your real `composer.lock`, so package-specific
guidelines, `search-docs`, and `application-info` work. An `artisan -> vendor/bin/testbench`
symlink is created so the generated MCP config (`php artisan boost:mcp`) works verbatim.

## How it works

Only while a `boost:*` or `mcp:*` command runs under the Testbench CLI, the provider rebases
the booted app's base path to your package root (`Application::setBasePath()`), pinning the
skeleton's storage/config/database/bootstrap/lang/public paths first. Your test suite,
`package:test`, `workbench:build`, and `serve` never see any of this.

## Notes

- Boost only registers its commands in a `local` environment (or with debug on). If commands
  are missing, add `APP_ENV=local` to the `env` section of your `testbench.yaml`.
- Database-backed MCP tools run against the Testbench skeleton app — configure connections
  via your workbench setup as usual.
- Windows: if symlink creation fails, create the entrypoint manually:
  `ln -s vendor/bin/testbench artisan`.
```

- [ ] **Step 2: Write `LICENSE.md`** — standard MIT license text with `Copyright (c) 2026 Manuel Christlieb`.

- [ ] **Step 3: Commit**

```bash
git add -A && git commit -m "docs: add readme and license"
```

- [ ] **Step 4: Publish (REQUIRES EXPLICIT USER CONFIRMATION — do not run unprompted)**

Ask the user before executing; creating a public GitHub repo is an outward-facing action:

```bash
gh repo create bambamboole/boost-testbench --public --source . --push
```

Packagist submission (https://packagist.org/packages/submit) is a manual browser step for the user.

---

### Task 6: Validate against laravel-webhooks (no commits in that repo)

**Files (in `/Users/manuel.christlieb/Projects/laravel-webhooks` — all changes reverted at the end):**
- Modify: `composer.json` (temporary path repository)
- Delete (temporarily): `workbench/app/Support/BoostConfig.php`, `workbench/app/Support/BoostGuidelineComposer.php`, `workbench/app/Support/BoostSkillComposer.php`
- Modify (temporarily): `workbench/app/Providers/WorkbenchServiceProvider.php`

**Interfaces:**
- Consumes: the finished bridge package on disk at `/Users/manuel.christlieb/Projects/boost-testbench`.
- Produces: a validation report (CLAUDE.md diff, command exit codes). The real migration commit happens only after Packagist publication, outside this plan.

- [ ] **Step 1: Snapshot the current state**

```bash
cd /Users/manuel.christlieb/Projects/laravel-webhooks
git status --short   # must be clean before starting; abort if not
cp CLAUDE.md /tmp/CLAUDE.md.before
```

- [ ] **Step 2: Swap workaround for bridge**

```bash
composer config repositories.boost-testbench '{"type": "path", "url": "../boost-testbench"}'
composer require --dev bambamboole/boost-testbench:@dev
```

Then remove the workaround: delete the three `workbench/app/Support/Boost*.php` files and strip `WorkbenchServiceProvider` down to:

```php
<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;

use function Orchestra\Testbench\package_path;

class WorkbenchServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        config()->set('webhooks.scan_paths', [package_path('workbench/app/Events')]);
    }
}
```

(Note: laravel-webhooks requires PHP ^8.4 — run composer/artisan here with `php84` per the repo's convention if plain `php` fails.)

- [ ] **Step 3: Regenerate and compare**

```bash
composer boost:refresh
diff /tmp/CLAUDE.md.before CLAUDE.md
```

Expected: exit 0 from `boost:refresh`; the diff shows equal-or-richer content (the bridge additionally enables third-party package guideline discovery, so NEW sections may appear — that is success, not drift). Verify no new files appeared under `vendor/orchestra/testbench-core/laravel/` (`git -C ... status` is blind to vendor; check with `ls vendor/orchestra/testbench-core/laravel/ | grep -iE 'claude|boost|\.ai'`).

- [ ] **Step 4: Revert everything in laravel-webhooks**

```bash
git checkout -- . && git clean -fd workbench/ && composer install
git status --short   # must be clean again
```

(`composer.json`/`composer.lock` changes from Step 2 are reverted by `git checkout`; `composer install` restores the lockfile state.)

- [ ] **Step 5: Report**

Summarize for the user: exit codes, the CLAUDE.md diff highlights, and confirmation the skeleton stayed clean. List the follow-up: publish to Packagist, then commit the real migration in each package (`composer require --dev bambamboole/boost-testbench` + delete workaround).
