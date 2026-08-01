# extended-testbench: Laravel Boost bridge for package development

**Date:** 2026-08-02
**Status:** Approved
**Package:** `bambamboole/extended-testbench`

## Problem

Laravel Boost assumes it runs inside a full Laravel application: it resolves "the project" via
`base_path()` in ~25 call sites. Under Orchestra Testbench, `base_path()` is the vendor skeleton
(`vendor/orchestra/testbench-core/laravel`), not the package repo. The result is a split install:
CWD-relative writes (`CLAUDE.md`, `.mcp.json`) land in the package root by accident, while skills,
`.ai/`, `boost.json`, and rules land in vendor. Roster scans the skeleton (no `composer.lock`) and
returns nothing, so all package-conditional guidelines and skills silently disappear.
`Support\Composer::packages()` reads the skeleton's stub `composer.json`, so third-party guideline
discovery and Nightwatch detection are dead. Agent auto-detection scans the skeleton and finds
nothing.

Today every package carries a hand-rolled workaround (four subclass overrides of Boost internals
plus a relative skills-path config hack in `WorkbenchServiceProvider`). It covers only part of the
surface and breaks whenever Boost refactors its protected internals.

## Decision record

- **Upstream integration was rejected.** PR laravel/boost#746 (April 2026, +80/−21, a
  `ProjectPath::resolve()` seam) was closed: "consider releasing your code as an additional
  package." Issue #855: the maintainer prefers not to combine Boost with package development.
- **Therefore: a thin bridge package**, the path upstream explicitly blessed. No fork to maintain.
- **Architecture: path rebase, not targeted overrides.** Boost has no single seam: statics
  (`Support/Composer.php:38,47`, `Npm.php:38,50`), inline-`new`'d writers
  (`InstallCommand.php:394,451`), and command-internal `base_path()` calls are unreachable via
  container rebinding. Rebasing the booted app's base path fixes every site in one move and touches
  zero Boost internals, so it survives Boost refactors.

## Design

### 1. Package shape

- New package `bambamboole/extended-testbench`; consuming packages add it once via
  `composer require --dev bambamboole/extended-testbench`. Nothing else — the provider is
  auto-discovered by `testbench package:discover`, same as Boost itself.
- `require`: `php ^8.2`, `laravel/boost ^2.4`. **No** orchestra dependency: Testbench is by
  definition installed wherever `vendor/bin/testbench` runs; guard with
  `function_exists('Orchestra\Testbench\package_path')`.
- One service provider plus at most one small support class. No config file; `composer remove` is
  the off switch.

### 2. Activation gate

`register()` does nothing unless all hold:

```php
defined('TESTBENCH_CORE')                               // running via vendor/bin/testbench CLI
&& ! $this->app->runningUnitTests()                     // never during pest/phpunit
&& function_exists('Orchestra\Testbench\package_path')
&& $this->isBoostCommand()                              // argv[1] starts with 'boost:' or 'mcp:'
```

- `TESTBENCH_CORE` is defined only by the testbench binary, not by phpunit/pest runs.
- The argv gate covers `boost:install`, `boost:update`, `boost:mcp`, `boost:execute-tool`,
  `boost:add-skill`, `boost:list-skill`, and `mcp:start`. Missing `argv[1]` → inactive.
- `package:test`, `workbench:build`, `serve`, and test suites never see the rebase.

### 3. Core mechanism (in `register()`)

```php
$pins = capture [storagePath, configPath, databasePath, bootstrapPath, langPath, publicPath];

$app->setBasePath(Orchestra\Testbench\package_path());

re-apply all pins via use*Path();      // MUST happen AFTER setBasePath:
                                       // bindPathsInContainer() force-rederives bootstrap and
                                       // lang from the new base (Foundation/Application.php:432-441)

$app->useAppPath(package_path('src')); // only if src/ exists — Boost enum discovery then works
                                       // on package code (Boost's own tests do useAppPath('src'))

config(['cache.default' => 'array']);  // skeleton default is database → boost:install crash
                                       // (laravel/boost#366)
```

Why timing works: all providers register before any console command executes, and every
path-sensitive spot in Boost resolves lazily at command runtime — Roster's `ProjectManager`
singleton (scans `base_path()` → finds the package's real `composer.lock`),
`Support\Composer::packages()` (reads the package's real `composer.json` → third-party guidelines
and Nightwatch work), `Config`/`boost.json`, `RuleRepository`, skill/guideline writers, and agent
auto-detection (`InstallCommand.php:111`). App config was already loaded from the skeleton during
bootstrap, so the running app is unaffected. `resource_path()` drifts to the package root after
rebasing (no `useResourcePath()` exists); the only Boost consumer is the Inertia assist, which is
correct-or-irrelevant for packages.

### 4. MCP entrypoint

Boost writes `{"command": "php", "args": ["artisan", "boost:mcp"]}` (CWD-relative) into
`.mcp.json`, and `ToolExecutor::buildCommand()` (`ToolExecutor.php:134`) invokes
`base_path('artisan')` for every MCP tool subprocess. Both need an `artisan` at the package root.

The bridge creates a relative symlink `artisan -> vendor/bin/testbench` at the package root when a
Boost command runs and no `artisan` exists there; on symlink failure it warns and continues. This
single symlink makes the default MCP registration, the absolute-path agents (Junie/Kiro), and the
tool-subprocess chain all work. The chain stays consistent: MCP client → `php artisan boost:mcp` →
testbench → argv gate activates bridge → rebased paths → `boost:execute-tool` subprocesses re-enter
through the same gate.

### 5. Migration in consuming packages

For laravel-webhooks (template for the others):

- Delete `workbench/app/Support/{BoostConfig,BoostGuidelineComposer,BoostSkillComposer}.php`.
- Remove all Boost wiring from `WorkbenchServiceProvider` (keep `webhooks.scan_paths`).
- `composer require --dev bambamboole/extended-testbench`.
- `composer boost:refresh` keeps working unchanged.

Net effect: ~150 lines deleted per package; previously-broken features start working (third-party
package guidelines, agent auto-detection, package-aware `search-docs` / `application-info`).

### 6. Testing

- **End-to-end (the real proof):** the bridge repo has testbench + boost installed, so a Pest test
  spawns `vendor/bin/testbench boost:update --no-interaction` as a subprocess and asserts that
  `CLAUDE.md` / `boost.json` land at the bridge repo root and nothing is written into
  `vendor/orchestra/testbench-core/laravel/`.
- **Unit:** the pure argv command gate.
- No mocking of Boost internals — that coupling is what the bridge exists to escape.

### 7. Error handling

- Any gate condition false → provider is a no-op. No exceptions on missing testbench functions.
- Symlink creation failure (e.g. Windows without privileges) → console warning, continue; the
  rebase still fixes everything except the MCP entrypoint, and the warning names the manual fix.
- If a package's environment fails Boost's own `local`-env gate
  (`BoostServiceProvider.php:188-204`), the documented fix is `APP_ENV=local` in `testbench.yaml`
  env — not a bridge concern.

## Out of scope

- The laravel-boost fork: unneeded for this plan. Keep only for a possible future upstream
  "single path seam" refactor PR, which would shrink the bridge, not replace it.
- Database-backed MCP tools run against whatever the skeleton app provides (workbench env /
  sqlite); configuring that is normal Testbench workbench setup.
- Windows-native shim for the artisan entrypoint (warn-only in v1).

## Key reference points (from research, for implementation)

- `Illuminate\Foundation\Application::setBasePath()` is public (`Application.php:408`);
  `bindPathsInContainer()` (`:422`) rebinds `path.*` instances and force-rederives bootstrap/lang.
  Explicitly pinned paths survive via `$this->configPath ?: $this->basePath('config')` pattern.
- Boost gate: `BoostServiceProvider.php:188-204` (`runningUnitTests()` bail; local-env check).
- Unreachable-by-rebinding sites the rebase covers: `Support/Composer.php:38,47`,
  `Support/Npm.php:38,50`, `InstallCommand.php:111,177,181,488`, `UpdateCommand.php:37`,
  `AddSkillCommand.php:268`, `SkillWriter.php:38,39,130`, `GuidelineComposer.php:74`,
  `SkillComposer.php:137,154`, `Support/Config.php:118,131,153,160`, `RuleRepository` binding
  (`BoostServiceProvider.php:43`), `ToolExecutor.php:134`.
- CWD-relative writers (already correct when invoked from package root):
  `GuidelineWriter.php:36-44`, `Install/Mcp/FileWriter.php:19,444,469`.
- Laravel-webhooks' existing `artisan -> vendor/bin/testbench` relative symlink proves the
  entrypoint pattern.
