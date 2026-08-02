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

That's it — this is the only dev dependency you need. `orchestra/testbench ^11` comes with it, and
the provider is auto-discovered by `testbench package:discover`.

Requiring testbench 11 means Laravel 13. If your package still supports Laravel 11 or 12, stay on
your own `orchestra/testbench` constraint and don't install this bridge yet.

## Use

```bash
vendor/bin/testbench boost:install
vendor/bin/testbench boost:update --no-interaction
```

Everything lands in your package repo: `CLAUDE.md` / `AGENTS.md`, `boost.json`, `.ai/`,
agent skill directories. Roster scans your real `composer.lock`, so package-specific
guidelines, `search-docs`, and `application-info` work. An `artisan` entrypoint — a one-line
shim that requires `vendor/bin/testbench` — is created so the generated MCP config
(`php artisan boost:mcp`) works verbatim. Commit it; Boost hardcodes the `artisan` script name
in its MCP config, its tool subprocesses, and its guideline text.

## Scaffold a package

```bash
vendor/bin/testbench package:init
```

Installs Pest 5 and writes `phpunit.xml.dist` (sqlite `:memory:`), `tests/TestCase.php`,
`tests/Pest.php` and `testbench.yaml` when they are missing, then asks about browser tests
(`pest-plugin-browser`, a dummy test, and a `Browser` suite appended to `tests/Pest.php`), PHPStan
(Larastan + the Pest PHPStan extension, writing `phpstan.neon.dist` plus a `stan` composer script),
Rector (`rector.php` plus a `refactor` script) and Pint (`pint.json` plus a `lint` script). Existing
files are never replaced without asking. It finishes by running `boost:install` (or `boost:update
--discover` when Boost is already set up) so the guidelines land in your `CLAUDE.md` / `AGENTS.md`
without a second step.

What `package:init` scaffolds requires PHP 8.4+ and `orchestra/testbench ^11`: Pest 5 needs PHPUnit 13
and `symfony/process ^8.1`; testbench 10.x pulls in Laravel 12, which pins `symfony/process` to `^7.2`,
so only testbench 11 (Laravel 13) resolves. `pest-plugin-browser` additionally requires `ext-sockets`.

## Shipped guidelines

This package publishes one Boost guideline covering comments, git and pull request conventions, and
the Testbench-specific facts agents get wrong (`artisan` is a shim, `base_path()` is the skeleton
and not your package). Boost discovers it automatically and composes it into your `CLAUDE.md` /
`AGENTS.md` under a `bambamboole/extended-testbench rules` heading.

You are asked about it during `vendor/bin/testbench boost:install`. If Boost is already installed,
pick it up once with:

```bash
vendor/bin/testbench boost:update --discover
```

Adding your own files to `.ai/guidelines/` in your package extends the set — a same-named file does not
replace ours, both get composed into your `CLAUDE.md` / `AGENTS.md`. To drop ours entirely, deselect
this package when running `boost:install`, or remove it from the `packages` key in `boost.json`.

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
- The `artisan` entrypoint is a plain PHP file, not a symlink, so it needs no special handling on
  Windows and survives a fresh clone once committed.
- Windows: on a checkout without `core.symlinks` enabled, the tracked `.ai/guidelines/core.blade.php`
  becomes a small text file containing its target path instead of a symlink, and `vendor/bin/pest`
  fails loudly on it. Enable symlinks (`git config core.symlinks true` and re-clone) or recreate the
  symlink by hand.
- `orchestra/testbench ^11` is a hard requirement, not just what the suite is tested against: it sits
  in `require` so installing this package is all you need. That means PHP 8.4+ and Laravel 13.
