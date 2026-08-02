# `package:init` Command Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an interactive `vendor/bin/testbench package:init` command that scaffolds a Laravel package's dev setup — Pest 5, optional browser tests, PHPStan/Larastan, Rector and Pint — writing into the package root.

**Architecture:** One command class, `Bambamboole\ExtendedTestbench\Commands\InitCommand`, with the package root and an `Illuminate\Support\Composer` instance injected through the constructor. The provider registers it outside the existing boost/mcp activation gate. Generated file contents live in `stubs/*.stub` with `{{ key }}` placeholders rendered by `str_replace`. All prompts are asked up front (via `laravel/prompts`), then dependencies are installed and files written.

**Tech Stack:** PHP 8.4, Orchestra Testbench, `laravel/prompts` ^0.3, `Illuminate\Support\Composer`, `symfony/process`, Pest 4 (this repo's own suite) + Mockery.

**Spec:** `docs/superpowers/specs/2026-08-02-package-init-command-design.md`

## Global Constraints

- This package's `require.php` becomes `^8.4` (PHP 8.4 and 8.5 only). Nothing may reintroduce an `^8.2`/`^8.3` floor.
- Every file the command writes goes through `$this->root` (the injected package root). Never `base_path()`, never `getcwd()`, never `package_path()` inside the command.
- Existing files are never silently replaced. `phpunit.xml.dist`, `phpstan.neon.dist`, `rector.php`, `pint.json`, `tests/Browser/DummyTest.php` prompt `confirm("Overwrite <path>?", default: false)`; `tests/TestCase.php`, `tests/Pest.php`, `testbench.yaml` are written **only when missing** and are recorded as `skipped (exists)` without a prompt.
- Declared version constraints, exactly: `pestphp/pest:^5.0`, `pestphp/pest-plugin-browser:^5.0`, `larastan/larastan:^3.0`, `pestphp/pest-plugin-phpstan:^5.0`, `rector/rector:^2.0`, `laravel/pint:^1.16`. `pestphp/pest-plugin-laravel` is **not** installed (v5 pins `laravel/framework ^13.23`).
- `phpstan/extension-installer` is **not** used; `phpstan.neon.dist` includes the three extension files explicitly.
- The existing boost/mcp gate (`shouldActivate()`, `isBoostCommand()`) and its test must not change behaviour.
- This repo's own suite runs on Pest 4 (`vendor/bin/pest`). The Pest 5 constraints above are what the command *writes into other packages*, not what this repo uses.
- Code style: `declare(strict_types=1)` everywhere, `final` command class, run `vendor/bin/pint` before each commit.

## File Structure

| File | Responsibility |
|---|---|
| `composer.json` | modify: `require.php` → `^8.4`, add `laravel/prompts: ^0.3` |
| `src/Commands/InitCommand.php` | create: the whole command — prompts, install, write, summary |
| `src/ExtendedTestbenchServiceProvider.php` | modify: register the command + its singleton, outside the boost gate |
| `stubs/phpunit.xml.dist.stub` | create: PHPUnit config, `{{ app_key }}` + `{{ browser_testsuite }}` |
| `stubs/TestCase.php.stub` | create: Orchestra test case, `{{ namespace }}` + `{{ providers }}` |
| `stubs/Pest.php.stub` | create: Pest bootstrap, `{{ test_case }}` + `{{ suites }}` |
| `stubs/testbench.yaml.stub` | create: testbench config, `{{ providers }}` |
| `stubs/BrowserDummyTest.php.stub` | create: self-contained browser smoke test |
| `stubs/phpstan.neon.dist.stub` | create: PHPStan config, `{{ level }}` |
| `stubs/rector.php.stub` | create: Rector config |
| `stubs/pint.json.stub` | create: Pint config |
| `tests/Feature/InitCommandTest.php` | create: all command tests, driven by `CommandTester` + `Prompt::fake()` |
| `README.md` | modify: document `package:init` |

`stubs/` is deliberately **not** autoloaded and the files use the `.stub` extension so Pint and PHPStan skip them (several contain `{{ … }}` placeholders that are not valid PHP).

---

### Task 1: Dependencies and command registration

**Files:**
- Modify: `composer.json`
- Create: `src/Commands/InitCommand.php`
- Modify: `src/ExtendedTestbenchServiceProvider.php:14-29`
- Test: `tests/Feature/InitCommandTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Bambamboole\ExtendedTestbench\Commands\InitCommand::__construct(Illuminate\Support\Composer $composer, string $root)`, command name `package:init`, `handle(): int`. Later tasks fill `handle()` in.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/InitCommandTest.php`:

```php
<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Commands\InitCommand;

it('registers the package:init command', function () {
    expect(array_keys($this->app[Illuminate\Contracts\Console\Kernel::class]->all()))
        ->toContain('package:init');
});

it('builds the command with the package root and a composer instance', function () {
    $command = $this->app->make(InitCommand::class);

    expect($command)->toBeInstanceOf(InitCommand::class)
        ->and($command->getName())->toBe('package:init');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/InitCommandTest.php`
Expected: FAIL — `Class "Bambamboole\ExtendedTestbench\Commands\InitCommand" not found`.

- [ ] **Step 3: Add the dependency and bump PHP**

In `composer.json`, change `"php": "^8.2"` to `"php": "^8.4"` and add `laravel/prompts` to `require` (keep `sort-packages` ordering — `laravel/boost` then `laravel/prompts`):

```json
    "require": {
        "php": "^8.4",
        "laravel/boost": "^2.4",
        "laravel/prompts": "^0.3"
    },
```

Then run: `composer update laravel/prompts --no-interaction`

- [ ] **Step 4: Create the command skeleton**

Create `src/Commands/InitCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Composer;

final class InitCommand extends Command
{
    protected $signature = 'package:init';

    protected $description = 'Scaffold Pest, static analysis and formatting for this package';

    public function __construct(
        private readonly Composer $composer,
        private readonly string $root,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Register the command in the provider**

In `src/ExtendedTestbenchServiceProvider.php`, add the imports:

```php
use Bambamboole\ExtendedTestbench\Commands\InitCommand;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
```

and put this block at the **top** of `register()`, before the existing `shouldActivate()` early return (which stays exactly as it is):

```php
    public function register(): void
    {
        if ($this->app->runningInConsole() && function_exists('Orchestra\Testbench\package_path')) {
            $this->app->singleton(InitCommand::class, fn (): InitCommand => new InitCommand(
                new Composer(new Filesystem, package_path()),
                package_path(),
            ));

            $this->commands([InitCommand::class]);
        }

        if (! $this->shouldActivate()) {
            return;
        }

        // ... existing body unchanged
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest`
Expected: PASS — the two new tests plus the existing suite green.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint
git add composer.json composer.lock src/Commands/InitCommand.php src/ExtendedTestbenchServiceProvider.php tests/Feature/InitCommandTest.php
git commit -m "feat: register package:init command skeleton"
```

---

### Task 2: Pest baseline — install, stubs, overwrite policy, summary

**Files:**
- Modify: `src/Commands/InitCommand.php`
- Create: `stubs/phpunit.xml.dist.stub`, `stubs/TestCase.php.stub`, `stubs/Pest.php.stub`, `stubs/testbench.yaml.stub`
- Test: `tests/Feature/InitCommandTest.php`

**Interfaces:**
- Consumes: `InitCommand::__construct(Composer $composer, string $root)` from Task 1.
- Produces, for later tasks (all `private` on `InitCommand`):
  - `install(array $packages): void` — `$packages` are `name:constraint` strings; skips any already in the target's composer.json.
  - `write(string $path, string $stub, array $replacements = [], bool $onlyIfMissing = false): void` — `$path` is relative to `$this->root`, `$stub` is a filename inside `stubs/`.
  - `script(string $name, string $command): void` — adds a composer script unless the key exists.
  - `composerJson(): array`, `providers(): array<int, string>`, `testNamespace(): string` (returns e.g. `'Tests\'` with a trailing backslash).
  - `array<int, array{0: string, 1: string}> $results` — the summary rows.
  - `handle()` asks every prompt first, then performs the work.

- [ ] **Step 1: Write the failing tests**

Replace the contents of `tests/Feature/InitCommandTest.php` with (keeping the two Task 1 tests at the top):

```php
<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Commands\InitCommand;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/etb-init-'.bin2hex(random_bytes(4));

    mkdir($this->root, 0755, true);

    file_put_contents($this->root.'/composer.json', json_encode([
        'name' => 'acme/demo',
        'extra' => ['laravel' => ['providers' => ['Acme\\Demo\\DemoServiceProvider']]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->root);
});

/**
 * Binds a temp-rooted InitCommand instance so `$this->artisan('package:init')`
 * runs against the temp package instead of this repository. The Composer double
 * keeps the real hasPackage()/modify() (they only touch the temp composer.json)
 * and stubs out the two methods that shell out.
 *
 * MANDATORY: every test that runs the command calls this first. The provider's
 * own binding roots the command at package_path() — this repository — so
 * resolving it would write real files and run a real `composer require`.
 */
function bindInit(string $root): void
{
    $composer = Mockery::mock(Composer::class.'[requirePackages,dumpAutoloads]', [new Filesystem, $root]);
    $composer->shouldReceive('requirePackages')->andReturnTrue();
    $composer->shouldReceive('dumpAutoloads')->andReturnTrue();

    app()->instance(InitCommand::class, new InitCommand($composer, $root));
}

it('registers the package:init command', function () {
    expect(array_keys($this->app[Illuminate\Contracts\Console\Kernel::class]->all()))
        ->toContain('package:init');
});

it('builds the command with the package root and a composer instance', function () {
    $command = $this->app->make(InitCommand::class);

    expect($command)->toBeInstanceOf(InitCommand::class)
        ->and($command->getName())->toBe('package:init');
});

it('scaffolds the pest baseline when everything else is declined', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->assertSuccessful();

    expect($this->root.'/phpunit.xml.dist')->toBeFile()
        ->and($this->root.'/tests/TestCase.php')->toBeFile()
        ->and($this->root.'/tests/Pest.php')->toBeFile()
        ->and($this->root.'/testbench.yaml')->toBeFile();

    $phpunit = file_get_contents($this->root.'/phpunit.xml.dist');

    expect($phpunit)->toContain('<env name="DB_CONNECTION" value="sqlite"/>')
        ->toContain('<env name="DB_DATABASE" value=":memory:"/>')
        ->toContain('value="base64:')
        ->not->toContain('name="Browser"');

    expect(file_get_contents($this->root.'/tests/TestCase.php'))
        ->toContain('namespace Tests;')
        ->toContain('\Acme\Demo\DemoServiceProvider::class');

    expect(file_get_contents($this->root.'/tests/Pest.php'))
        ->toContain("->in('Feature', 'Unit');");

    expect(file_get_contents($this->root.'/testbench.yaml'))
        ->toContain("laravel: '@testbench'")
        ->toContain('Acme\Demo\DemoServiceProvider');

    $composerJson = json_decode(file_get_contents($this->root.'/composer.json'), true);

    expect($composerJson['scripts']['test'])->toBe('pest')
        ->and($composerJson['autoload-dev']['psr-4']['Tests\\'])->toBe('tests/')
        ->and($composerJson['scripts'])->not->toHaveKeys(['stan', 'refactor', 'lint']);
});

it('keeps existing files when the overwrite prompt is declined', function () {
    file_put_contents($this->root.'/phpunit.xml.dist', 'ORIGINAL');
    mkdir($this->root.'/tests', 0755, true);
    file_put_contents($this->root.'/tests/Pest.php', '<?php // ORIGINAL PEST');

    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Overwrite phpunit.xml.dist?', 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/phpunit.xml.dist'))->toBe('ORIGINAL')
        ->and(file_get_contents($this->root.'/tests/Pest.php'))->toBe('<?php // ORIGINAL PEST');
});

it('overwrites an existing file when the prompt is accepted', function () {
    file_put_contents($this->root.'/phpunit.xml.dist', 'ORIGINAL');

    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Overwrite phpunit.xml.dist?', 'yes')
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/phpunit.xml.dist'))->toContain(':memory:');
});
```

**Why `$this->artisan()` and not `Prompt::fake()` + `CommandTester`:** `Illuminate\Console\Command::run()`
calls `configurePrompts()`, which does `Prompt::fallbackWhen(windows_os() || $this->laravel->runningUnitTests())`.
Under any Testbench suite that is unconditionally `true`, so `confirm()` never reads the faked terminal — it
takes the Symfony fallback and returns its default, silently ignoring every queued key press. Laravel's
`expectsConfirmation()` / `expectsChoice()` drive that same fallback, which is why they work.

**Why the instance binding is mandatory:** the provider binds `InitCommand` rooted at `package_path()`, which
during this repo's own suite is **this repository**. Resolving that binding in a test would write real files
and shell out to a real `composer require`. `app()->instance(...)` with a temp-rooted command makes that
impossible; never call `$this->artisan('package:init')` without `bindInit()` first.

**Prompt expectations track the prompts that exist:** unlike a faked key queue, `expectsConfirmation()` fails
on a question that is never asked. Task 2's `handle()` asks exactly one section prompt, so these tests expect
only `Add browser tests?` plus the overwrite confirmations. Tasks 3-5 add their new prompts to these same
tests as they introduce them.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/InitCommandTest.php`
Expected: FAIL — the three new tests fail because no files are written (`phpunit.xml.dist` does not exist).

- [ ] **Step 3: Create the four stubs**

`stubs/phpunit.xml.dist.stub`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>{{ browser_testsuite }}
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
    <php>
        <env name="APP_KEY" value="{{ app_key }}"/>
        <env name="APP_DEBUG" value="true"/>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
        <env name="CACHE_STORE" value="array"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
    </php>
</phpunit>
```

`stubs/TestCase.php.stub`:

```php
<?php

declare(strict_types=1);

namespace {{ namespace }};

use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [{{ providers }}];
    }
}
```

`stubs/Pest.php.stub`:

```php
<?php

declare(strict_types=1);

uses({{ test_case }}::class)->in({{ suites }});
```

`stubs/testbench.yaml.stub`:

```yaml
laravel: '@testbench'
{{ providers }}
```

- [ ] **Step 4: Implement the command body**

Rewrite `src/Commands/InitCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Composer;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;

final class InitCommand extends Command
{
    private const BROWSER_TESTSUITE = <<<'XML'


        <testsuite name="Browser">
            <directory>tests/Browser</directory>
        </testsuite>
    XML;

    protected $signature = 'package:init';

    protected $description = 'Scaffold Pest, static analysis and formatting for this package';

    /** @var array<int, array{0: string, 1: string}> */
    private array $results = [];

    private ?string $testNamespace = null;

    private bool $autoloadChanged = false;

    public function __construct(
        private readonly Composer $composer,
        private readonly string $root,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        intro('extended-testbench: package init');

        $browser = confirm('Add browser tests?', default: false);

        $this->pest($browser);

        if ($this->autoloadChanged) {
            $this->composer->dumpAutoloads();
        }

        table(['File', 'Result'], $this->results);

        outro('Done.');

        return self::SUCCESS;
    }

    private function pest(bool $browser): void
    {
        $this->install(['pestphp/pest:^5.0']);

        $this->write('phpunit.xml.dist', 'phpunit.xml.dist.stub', [
            'app_key' => 'base64:'.base64_encode(random_bytes(32)),
            'browser_testsuite' => $browser ? self::BROWSER_TESTSUITE : '',
        ]);

        $this->write('tests/TestCase.php', 'TestCase.php.stub', [
            'namespace' => rtrim($this->testNamespace(), '\\'),
            'providers' => implode(', ', array_map(
                static fn (string $provider): string => '\\'.ltrim($provider, '\\').'::class',
                $this->providers(),
            )),
        ], onlyIfMissing: true);

        $this->write('tests/Pest.php', 'Pest.php.stub', [
            'test_case' => '\\'.$this->testNamespace().'TestCase',
            'suites' => $browser ? "'Feature', 'Unit', 'Browser'" : "'Feature', 'Unit'",
        ], onlyIfMissing: true);

        $this->write('testbench.yaml', 'testbench.yaml.stub', [
            'providers' => $this->providers() === [] ? '' : "\nproviders:\n".implode("\n", array_map(
                static fn (string $provider): string => '  - '.ltrim($provider, '\\'),
                $this->providers(),
            ))."\n",
        ], onlyIfMissing: true);

        $this->script('test', 'pest');
    }

    /** @param  array<int, string>  $packages */
    private function install(array $packages): void
    {
        $missing = array_values(array_filter(
            $packages,
            fn (string $package): bool => ! $this->composer->hasPackage(explode(':', $package)[0]),
        ));

        if ($missing === []) {
            return;
        }

        $this->composer->requirePackages($missing, dev: true, output: $this->output);
    }

    /** @param  array<string, string>  $replacements */
    private function write(string $path, string $stub, array $replacements = [], bool $onlyIfMissing = false): void
    {
        $target = $this->root.'/'.$path;

        if (file_exists($target)) {
            if ($onlyIfMissing) {
                $this->results[] = [$path, 'skipped (exists)'];

                return;
            }

            if (! confirm("Overwrite {$path}?", default: false)) {
                $this->results[] = [$path, 'skipped'];

                return;
            }
        }

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, recursive: true);
        }

        file_put_contents($target, $this->render($stub, $replacements));

        $this->results[] = [$path, 'written'];
    }

    /** @param  array<string, string>  $replacements */
    private function render(string $stub, array $replacements): string
    {
        $contents = (string) file_get_contents(__DIR__.'/../../stubs/'.$stub);

        foreach ($replacements as $key => $value) {
            $contents = str_replace('{{ '.$key.' }}', $value, $contents);
        }

        return $contents;
    }

    private function script(string $name, string $command): void
    {
        if (isset($this->composerJson()['scripts'][$name])) {
            return;
        }

        $this->composer->modify(static function (array $composer) use ($name, $command): array {
            $composer['scripts'][$name] = $command;

            return $composer;
        });

        $this->results[] = ["composer script: {$name}", 'added'];
    }

    /** @return array<string, mixed> */
    private function composerJson(): array
    {
        return (array) json_decode((string) file_get_contents($this->root.'/composer.json'), true);
    }

    /** @return array<int, string> */
    private function providers(): array
    {
        return array_values((array) ($this->composerJson()['extra']['laravel']['providers'] ?? []));
    }

    private function testNamespace(): string
    {
        if ($this->testNamespace !== null) {
            return $this->testNamespace;
        }

        foreach ((array) ($this->composerJson()['autoload-dev']['psr-4'] ?? []) as $namespace => $path) {
            if (rtrim((string) $path, '/') === 'tests') {
                return $this->testNamespace = (string) $namespace;
            }
        }

        $this->composer->modify(static function (array $composer): array {
            $composer['autoload-dev']['psr-4']['Tests\\'] = 'tests/';

            return $composer;
        });

        $this->autoloadChanged = true;

        return $this->testNamespace = 'Tests\\';
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/InitCommandTest.php`
Expected: PASS — 5 tests.

If a test fails with "Expected question was not asked", the expectations do not match the prompts `handle()` actually asks, in order. Count the `confirm()` calls and fix the expectations, not the command.

- [ ] **Step 6: Run the whole suite**

Run: `vendor/bin/pest`
Expected: PASS — no regressions in `CommandGateTest`, `PackageRootRebaseTest`, `BoostUpdateEndToEndTest`.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint
git add src/Commands/InitCommand.php stubs tests/Feature/InitCommandTest.php
git commit -m "feat: scaffold pest baseline in package:init"
```

---

### Task 3: Browser tests and Playwright

**Files:**
- Modify: `src/Commands/InitCommand.php`
- Create: `stubs/BrowserDummyTest.php.stub`
- Test: `tests/Feature/InitCommandTest.php`

**Interfaces:**
- Consumes: `install()`, `write()`, `$results`, `testNamespace()` from Task 2; the `$browser` flag already asked in `handle()`.
- Produces: `browser(): void` and `playwright(): void` on `InitCommand`. `handle()` gains a second prompt, `confirm('Install Playwright browsers now?')`, asked **only** when browser tests were accepted, immediately after the browser prompt.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/InitCommandTest.php`:

```php
it('scaffolds browser tests when accepted', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add browser tests?', 'yes')
        ->expectsConfirmation('Install Playwright browsers now?', 'no')
        ->assertSuccessful();

    expect($this->root.'/tests/Browser/DummyTest.php')->toBeFile();

    expect(file_get_contents($this->root.'/phpunit.xml.dist'))
        ->toContain('<testsuite name="Browser">')
        ->toContain('<directory>tests/Browser</directory>');

    expect(file_get_contents($this->root.'/tests/Pest.php'))
        ->toContain("->in('Feature', 'Unit', 'Browser');");

    expect(file_get_contents($this->root.'/tests/Browser/DummyTest.php'))
        ->toContain("visit('/dummy')");
});

it('appends the browser suite to an existing Pest.php', function () {
    mkdir($this->root.'/tests', 0755, true);
    file_put_contents(
        $this->root.'/tests/Pest.php',
        "<?php\n\ndeclare(strict_types=1);\n\nuses(Tests\\TestCase::class)->in('Feature');\n",
    );

    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add browser tests?', 'yes')
        ->expectsConfirmation('Install Playwright browsers now?', 'no')
        ->assertSuccessful();

    $pest = file_get_contents($this->root.'/tests/Pest.php');

    expect($pest)->toContain("uses(Tests\\TestCase::class)->in('Feature');")
        ->toContain("->in('Browser');");

    expect(substr_count($pest, "in('Browser')"))->toBe(1);
});

it('does not scaffold browser tests when declined', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->assertSuccessful();

    expect($this->root.'/tests/Browser')->not->toBeDirectory();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/InitCommandTest.php`
Expected: FAIL — `tests/Browser/DummyTest.php` is not a file.

- [ ] **Step 3: Create the browser stub**

`stubs/BrowserDummyTest.php.stub`:

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('renders a route in the browser', function () {
    Route::get('/dummy', fn () => 'Hello from extended-testbench');

    visit('/dummy')->assertSee('Hello from extended-testbench');
});
```

- [ ] **Step 4: Wire the browser step into the command**

In `src/Commands/InitCommand.php` add the import `use Symfony\Component\Process\Process;`, then extend `handle()` (the two new lines go directly after the existing `$browser = confirm(...)` line and after `$this->pest($browser)` respectively):

```php
        $browser = confirm('Add browser tests?', default: false);
        $playwright = $browser && confirm('Install Playwright browsers now?', default: false);

        $this->pest($browser);

        if ($browser) {
            $this->browser();
        }

        if ($playwright) {
            $this->playwright();
        }
```

and add the two methods:

```php
    private function browser(): void
    {
        $this->install(['pestphp/pest-plugin-browser:^5.0']);

        $this->write('tests/Browser/DummyTest.php', 'BrowserDummyTest.php.stub');

        $pest = $this->root.'/tests/Pest.php';

        if (! file_exists($pest) || str_contains((string) file_get_contents($pest), "'Browser'")) {
            return;
        }

        file_put_contents(
            $pest,
            sprintf("\nuses(\\%sTestCase::class)->in('Browser');\n", $this->testNamespace()),
            FILE_APPEND,
        );

        $this->results[] = ['tests/Pest.php', 'browser suite appended'];
    }

    private function playwright(): void
    {
        $process = new Process(['npx', 'playwright', 'install'], $this->root, timeout: null);

        $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));

        $this->results[] = ['npx playwright install', $process->isSuccessful() ? 'ran' : 'failed'];
    }
```

Note the ordering guarantee this relies on: `pest()` runs before `browser()`, so a freshly written `tests/Pest.php` already lists `'Browser'` and the append is skipped by the `str_contains` guard. Only a pre-existing `Pest.php` gets the extra line.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/InitCommandTest.php`
Expected: PASS — 8 tests.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint
git add src/Commands/InitCommand.php stubs/BrowserDummyTest.php.stub tests/Feature/InitCommandTest.php
git commit -m "feat: add browser test scaffolding to package:init"
```

The accepted-Playwright branch shells out to `npx` and is deliberately left untested — the tests always answer `n`. Do not add an abstraction just to test a single `Process` call.

---

### Task 4: PHPStan / Larastan

**Files:**
- Modify: `src/Commands/InitCommand.php`
- Create: `stubs/phpstan.neon.dist.stub`
- Test: `tests/Feature/InitCommandTest.php`

**Interfaces:**
- Consumes: `install()`, `write()`, `script()` from Task 2.
- Produces: `phpstan(string $level): void`. `handle()` gains `confirm('Add PHPStan (Larastan)?', default: true)` followed, when accepted, by `select('PHPStan level', ['5','6','7','8','9','max'], default: '6')`. Both are asked after the Playwright prompt and before Rector.

- [ ] **Step 1: Write the failing tests**

First, because `handle()` gains a prompt, add `->expectsConfirmation('Add PHPStan (Larastan)?', 'no')` to
**every existing test** in `tests/Feature/InitCommandTest.php` that runs the command, placed after the browser
and Playwright expectations and before `->assertSuccessful()`. Unasked-for expectations and unexpected
questions both fail, so the list must mirror `handle()` exactly.

Then append:

```php
it('scaffolds phpstan when accepted', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'yes')
        ->expectsChoice('PHPStan level', '6', ['5', '6', '7', '8', '9', 'max'])
        ->assertSuccessful();

    expect($this->root.'/phpstan.neon.dist')->toBeFile();

    expect(file_get_contents($this->root.'/phpstan.neon.dist'))
        ->toContain('- vendor/larastan/larastan/extension.neon')
        ->toContain('- vendor/pestphp/pest/extension.neon')
        ->toContain('- vendor/pestphp/pest-plugin-phpstan/extension.neon')
        ->toContain('level: 6');

    $composerJson = json_decode(file_get_contents($this->root.'/composer.json'), true);

    expect($composerJson['scripts']['stan'])->toBe('phpstan analyse');
});

it('writes the selected phpstan level', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'yes')
        ->expectsChoice('PHPStan level', '8', ['5', '6', '7', '8', '9', 'max'])
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/phpstan.neon.dist'))->toContain('level: 8');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest tests/Feature/InitCommandTest.php`
Expected: FAIL — `phpstan.neon.dist` is not a file.

- [ ] **Step 3: Create the stub**

`stubs/phpstan.neon.dist.stub`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon
    - vendor/pestphp/pest/extension.neon
    - vendor/pestphp/pest-plugin-phpstan/extension.neon

parameters:
    level: {{ level }}
    paths:
        - src
        - tests
```

- [ ] **Step 4: Wire the PHPStan step into the command**

Add `use function Laravel\Prompts\select;` to the imports, extend `handle()` (after the Playwright prompt, before the work section):

```php
        $phpstan = confirm('Add PHPStan (Larastan)?', default: true);
        $level = $phpstan ? select('PHPStan level', ['5', '6', '7', '8', '9', 'max'], default: '6') : '6';
```

and inside the work section, after the Playwright block:

```php
        if ($phpstan) {
            $this->phpstan($level);
        }
```

Add the method:

```php
    private function phpstan(string $level): void
    {
        $this->install(['larastan/larastan:^3.0', 'pestphp/pest-plugin-phpstan:^5.0']);

        $this->write('phpstan.neon.dist', 'phpstan.neon.dist.stub', ['level' => $level]);

        $this->script('stan', 'phpstan analyse');
    }
```

`select()` with a list array returns the selected **value** (`'6'`, `'max'`), not the index — pass it straight into the stub.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest tests/Feature/InitCommandTest.php`
Expected: PASS — 10 tests.

If `expectsChoice()` fails on the choices array (Laravel compares it against what the fallback passes to
`components->choice()`), drop the third argument or use `->expectsQuestion('PHPStan level', '6')` instead —
the assertion that matters is the level that lands in `phpstan.neon.dist`.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint
git add src/Commands/InitCommand.php stubs/phpstan.neon.dist.stub tests/Feature/InitCommandTest.php
git commit -m "feat: add phpstan scaffolding to package:init"
```

---

### Task 5: Rector and Pint

**Files:**
- Modify: `src/Commands/InitCommand.php`
- Create: `stubs/rector.php.stub`, `stubs/pint.json.stub`
- Test: `tests/Feature/InitCommandTest.php`

**Interfaces:**
- Consumes: `install()`, `write()`, `script()` from Task 2.
- Produces: `rector(): void`, `pint(): void`. `handle()` gains `confirm('Add Rector?', default: true)` and `confirm('Add Pint?', default: true)` as the last two prompts, in that order.

- [ ] **Step 1: Write the failing test**

First, `handle()` gains two prompts, so add `->expectsConfirmation('Add Rector?', 'no')` and
`->expectsConfirmation('Add Pint?', 'no')` — in that order, as the last expectations before
`->assertSuccessful()` — to **every existing test** in `tests/Feature/InitCommandTest.php` that runs the
command.

Then append:

```php
it('scaffolds rector and pint when accepted', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'yes')
        ->expectsConfirmation('Add Pint?', 'yes')
        ->assertSuccessful();

    expect($this->root.'/rector.php')->toBeFile()
        ->and($this->root.'/pint.json')->toBeFile();

    expect(file_get_contents($this->root.'/rector.php'))
        ->toContain('RectorConfig::configure()')
        ->toContain("__DIR__.'/src'");

    expect(json_decode(file_get_contents($this->root.'/pint.json'), true))
        ->toBe(['preset' => 'laravel', 'rules' => ['declare_strict_types' => true]]);

    $composerJson = json_decode(file_get_contents($this->root.'/composer.json'), true);

    expect($composerJson['scripts']['refactor'])->toBe('rector')
        ->and($composerJson['scripts']['lint'])->toBe('pint --format agent');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Feature/InitCommandTest.php`
Expected: FAIL — `rector.php` is not a file.

- [ ] **Step 3: Create the stubs**

`stubs/rector.php.stub`:

```php
<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src', __DIR__.'/tests'])
    ->withPhpSets()
    ->withPreparedSets(deadCode: true, codeQuality: true, typeDeclarations: true);
```

`stubs/pint.json.stub`:

```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true
    }
}
```

- [ ] **Step 4: Wire both steps into the command**

Extend `handle()` — prompts, after the PHPStan level:

```php
        $rector = confirm('Add Rector?', default: true);
        $pint = confirm('Add Pint?', default: true);
```

work section, after the PHPStan block:

```php
        if ($rector) {
            $this->rector();
        }

        if ($pint) {
            $this->pint();
        }
```

and the two methods:

```php
    private function rector(): void
    {
        $this->install(['rector/rector:^2.0']);

        $this->write('rector.php', 'rector.php.stub');

        $this->script('refactor', 'rector');
    }

    private function pint(): void
    {
        $this->install(['laravel/pint:^1.16']);

        $this->write('pint.json', 'pint.json.stub');

        $this->script('lint', 'pint --format agent');
    }
```

- [ ] **Step 5: Run the whole suite**

Run: `vendor/bin/pest`
Expected: PASS — 11 command tests plus the pre-existing suite.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint
git add src/Commands/InitCommand.php stubs/rector.php.stub stubs/pint.json.stub tests/Feature/InitCommandTest.php
git commit -m "feat: add rector and pint scaffolding to package:init"
```

---

### Task 6: Documentation and end-to-end check

**Files:**
- Modify: `README.md`

**Interfaces:**
- Consumes: the finished command.
- Produces: nothing code-facing.

- [ ] **Step 1: Document the command in the README**

Add this section to `README.md`, between the `## Use` section and `## How it works`:

```markdown
## Scaffold a package

```bash
vendor/bin/testbench package:init
```

Installs Pest 5 and writes `phpunit.xml.dist` (sqlite `:memory:`), `tests/TestCase.php`,
`tests/Pest.php` and `testbench.yaml` when they are missing, then asks about browser tests
(`pest-plugin-browser` + a dummy test), PHPStan (Larastan + the Pest PHPStan extension), Rector and
Pint — installing each and writing its config and composer script. Existing files are never
replaced without asking.

Requires PHP 8.4+ and `orchestra/testbench ^10.10|^11`; Pest 5 needs PHPUnit 13.
```

- [ ] **Step 2: Sanity-check the stubs the tests cannot lint**

The feature tests assert file *contents*, not that the generated PHP parses. Check the two stubs that
are complete PHP files and the JSON one:

```bash
php -l stubs/rector.php.stub
php -l stubs/BrowserDummyTest.php.stub
php -r 'json_decode(file_get_contents("stubs/pint.json.stub"), true, 512, JSON_THROW_ON_ERROR); echo "pint.json.stub ok\n";'
```

Expected: `No syntax errors detected` twice, then `pint.json.stub ok`.

Do **not** run the real `vendor/bin/testbench package:init` against this repository — its root is this
repo, so it would rewrite `phpunit.xml.dist` / `pint.json` and shell out to a real `composer require`.
The `npx playwright install` branch stays unverified by design.

- [ ] **Step 3: Full verification**

```bash
vendor/bin/pint --test
vendor/bin/pest
```

Expected: both green. Paste the actual output into the task report — no "should pass" claims.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "docs: document package:init"
```

---

## Self-Review

**Spec coverage:**

| Spec section | Task |
|---|---|
| PHP `^8.4` constraint | 1 |
| Registration outside the boost gate, injected root + Composer | 1 |
| `laravel/prompts` in `require` | 1 |
| Pest 5 install, `phpunit.xml.dist`, `tests/TestCase.php`, `tests/Pest.php`, `testbench.yaml`, `test` script | 2 |
| In-memory sqlite env block, generated `APP_KEY` | 2 |
| Overwrite prompt + `onlyIfMissing` + summary table | 2 |
| Provider / test-namespace detection, `autoload-dev` mapping | 2 |
| Browser plugin, dummy test, Browser testsuite, Pest.php mapping + append | 3 |
| Playwright prompt and shell-out | 3 |
| Larastan + `pest-plugin-phpstan`, explicit includes, level select, `stan` script | 4 |
| Rector + `refactor` script, Pint + `lint` script | 5 |
| README | 6 |
| No `pest-plugin-laravel`, no `extension-installer`, no preflight version check | Global Constraints |

**Placeholder scan:** none — every step carries the literal file content or command.

**Type consistency:** `install()`, `write()`, `render()`, `script()`, `composerJson()`, `providers()`, `testNamespace()`, `$results`, `$autoloadChanged` are defined in Task 2 and used with identical signatures in Tasks 3–5. `handle()`'s prompt order — browser, playwright, phpstan, level, rector, pint — matches the key-press arrays in every test.
