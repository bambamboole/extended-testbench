# extended-testbench

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
composer require --dev bambamboole/extended-testbench
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

## Scaffold a package

```bash
vendor/bin/testbench package:init
```

Installs Pest 5 and writes `phpunit.xml.dist` (sqlite `:memory:`), `tests/TestCase.php`,
`tests/Pest.php` and `testbench.yaml` when they are missing, then asks about browser tests
(`pest-plugin-browser`, a dummy test, and a `Browser` suite appended to `tests/Pest.php`), PHPStan
(Larastan + the Pest PHPStan extension, writing `phpstan.neon.dist` plus a `stan` composer script),
Rector (`rector.php` plus a `refactor` script) and Pint (`pint.json` plus a `lint` script). Existing
files are never replaced without asking.

Requires PHP 8.4+ and `orchestra/testbench ^11`. Pest 5 needs PHPUnit 13 and `symfony/process ^8.1`;
testbench 10.x pulls in Laravel 12, which pins `symfony/process` to `^7.2`, so only testbench 11
(Laravel 13) resolves. `pest-plugin-browser` additionally requires `ext-sockets`.

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
- Windows: if symlink creation fails, create the entrypoint manually with
  `mklink artisan vendor\bin\testbench` (cmd, may need admin rights or developer mode),
  or use `ln -s vendor/bin/testbench artisan` from Git Bash/WSL.
