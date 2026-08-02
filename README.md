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

Installs Pest 5 with `pest-plugin-laravel` and writes the `artisan` entrypoint, `.gitattributes`
(development-only files marked `export-ignore` so they don't ship in the dist archive),
`phpunit.xml.dist` (sqlite `:memory:`; no `APP_KEY` is generated — the Testbench skeleton already
provides one), `tests/TestCase.php`, `tests/Pest.php` and `testbench.yaml` when they are missing,
plus the `.gitignore` entries for everything it and Boost generate. Then it asks about:

- **a workbench app** — adds the `workbench:` block to `testbench.yaml` and hands the namespaces,
  directories and `autoload-dev` entries to Testbench's own `workbench:devtool`
- **browser tests** — `pest-plugin-browser`, a dummy test, a `tests/BrowserTestCase.php` that fails
  fast when the workbench's Vite build is missing or stale (skipped for packages with no
  `vite.config`), and a `Browser` suite in `tests/Pest.php` mapped to it instead of the base
  `TestCase`
- **PHPStan** — Larastan + the Pest PHPStan extension, `phpstan.neon.dist` (level `6` unless you pass
  `--phpstan-level`), a `stan` script
- **Rector** — `rector.php` plus a `refactor` script
- **Pint** — `pint.json` plus a `lint` script

Every section can be answered on the command line, so an agent or a CI job can drive it:

```bash
vendor/bin/testbench package:init --workbench --browser --phpstan-level=8 --no-rector
vendor/bin/testbench package:init --defaults
```

`--no-workbench`, `--no-browser`, `--no-playwright`, `--no-phpstan`, `--no-rector` and `--no-pint`
answer the other way. Without a terminal and without any of these flags the command refuses to run
rather than guessing — pass `--defaults` to take every default.

`src`, `tests` and, when present, `workbench/app` are the paths both PHPStan and Rector analyse;
PHPStan also adds `database` when the package has one. Rector's generated config skips the rule that
strips unused parameters from public methods, since Laravel resolves many signatures — policy
methods, middleware, listeners — by reflection, and stripping a parameter the body ignores breaks
them at runtime. Alongside the per-tool scripts you get a `test` script (the `Browser` suite excluded
once browser tests are accepted) and a `test:browser` script for that suite, preceded by
`npm run build` when the package has a `package.json`, plus a `check` script composed of whichever
tools you accepted, ending in `@test`, for CI and git hooks. It also wires the Testbench
`post-autoload-dump` hooks (`package:purge-skeleton`, `package:discover`) and a `boost:refresh`
script into `post-install-cmd` / `post-update-cmd`; `boost:refresh` reruns
`boost:update --no-interaction` on every local install or update, but no-ops in CI, before
`vendor/bin/testbench` exists, or before Boost has been set up (`boost.json` present), and never
fails the surrounding `composer install`/`update` even if that rerun does.

Existing files are never replaced without asking; a legacy `phpunit.xml` or `phpstan.neon` that would
shadow the generated `.dist` file, or an `artisan` that's still a symlink rather than the committed
shim, only gets a warning, never rewritten automatically. It finishes by running `boost:install` (or
`boost:update --discover` when Boost is already set up) and registering itself in `boost.json`'s
`packages` key — Boost cannot discover a new third-party package non-interactively — so the
guidelines land in your `CLAUDE.md` / `AGENTS.md` without a second step.

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
