<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Commands\InitCommand;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\OutputInterface;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/etb-init-order-'.bin2hex(random_bytes(4));

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
 * Pins the ordering InitCommand::handle() relies on: Composer::dumpAutoloads() shells out to
 * `composer dump-autoload` without --no-scripts, so it fires this same run's freshly-written
 * post-autoload-dump script (package:purge-skeleton + package:discover) as a side effect — a
 * reordering of two subprocesses relative to the Boost run, not just an autoloader regen. A future
 * accidental reorder (moving the dumpAutoloads() call back above scaffold(), or splitting scaffold()
 * so Boost runs after it) must fail this test rather than silently changing first-run behaviour for
 * new adopters.
 *
 * boost.json is seeded up front with a foreign package already registered, so BoostRegistration has
 * something concrete to write: a package with no vendor/bin/testbench never shells out to Boost, so
 * the file write is the only Boost side effect this run produces. Reading boost.json from inside
 * dumpAutoloads() is what proves that write already happened by the time Composer would fire the
 * scripts.
 *
 * A plain Composer subclass is used instead of the Mockery double every other test relies on:
 * capturing a value *during* the dumpAutoloads() call needs andReturnUsing(), which lives on
 * Mockery's concrete Expectation class rather than on ExpectationInterface, so PHPStan cannot see it
 * through shouldReceive()'s declared return type.
 */
it('calls dumpAutoloads only after the Boost artifacts have been applied', function () {
    file_put_contents($this->root.'/boost.json', json_encode([
        'guidelines' => true,
        'packages' => ['acme/other'],
    ], JSON_PRETTY_PRINT));

    $composer = new class(new Filesystem, $this->root) extends Composer
    {
        public ?string $boostJsonAtDumpAutoloads = null;

        public function requirePackages(array $packages, bool $dev = false, Closure|OutputInterface|null $output = null, $composerBinary = null): bool
        {
            return true;
        }

        public function dumpAutoloads($extra = '', $composerBinary = null): int
        {
            $this->boostJsonAtDumpAutoloads = (string) file_get_contents($this->workingPath.'/boost.json');

            return 0;
        }
    };

    app()->instance(InitCommand::class, new InitCommand($composer, $this->root));

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    expect($composer->boostJsonAtDumpAutoloads)->not->toBeNull();

    $boost = json_decode((string) $composer->boostJsonAtDumpAutoloads, true);

    expect($boost['packages'])->toContain('bambamboole/extended-testbench');
});

/**
 * InitCommand::handle() resolves `--playwright` by reading `$enabled['browser'] ?? false` — a key
 * the same loop only fills in if BrowserFeature's flag was already resolved this iteration, which
 * only holds because features() lists BrowserFeature before PlaywrightFeature. Nothing else pins that
 * ordering dependency: every other test either omits --browser or omits --playwright, so a reorder of
 * features() would silently resolve playwright false (and print a bogus "no effect" warning) without
 * a single test noticing. npx may not exist in this environment, so this asserts the row was reached
 * (even a `failed` row proves that) rather than that the subprocess itself succeeded.
 */
it('runs the playwright step when both --browser and --playwright are passed', function () {
    /** @var Composer&MockInterface $composer */
    $composer = Mockery::mock(Composer::class.'[requirePackages,dumpAutoloads]', [new Filesystem, $this->root]);
    $composer->shouldReceive('requirePackages')->andReturn(true);
    $composer->shouldReceive('dumpAutoloads')->andReturn(true);

    app()->instance(InitCommand::class, new InitCommand($composer, $this->root));

    $this->artisan('package:init', [
        '--no-interaction' => true,
        '--browser' => true,
        '--playwright' => true,
        '--no-phpstan' => true,
        '--no-rector' => true,
        '--no-pint' => true,
    ])
        ->doesntExpectOutputToContain('--playwright has no effect')
        ->expectsOutputToContain('npx playwright install')
        ->assertSuccessful();
});
