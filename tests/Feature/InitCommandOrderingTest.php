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
 * dumpAutoloads() shells out without --no-scripts, firing this run's freshly-written
 * post-autoload-dump script (package:purge-skeleton + package:discover) as a side effect, so moving
 * it above scaffold() reorders two subprocesses relative to the Boost run rather than just
 * regenerating an autoloader. Reading boost.json from inside dumpAutoloads() proves the Boost
 * artifacts were already applied by then; it is seeded with a foreign package so BoostRegistration
 * has something to write without vendor/bin/testbench being present.
 *
 * A plain Composer subclass rather than the usual Mockery double: capturing a value *during* the
 * call needs andReturnUsing(), which PHPStan cannot see through ExpectationInterface.
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
 * handle() resolves `--playwright` from `$enabled['browser'] ?? false`, a key only filled in
 * because features() lists BrowserFeature first. Nothing else pins that: every other test omits one
 * of the two flags, so a reorder would silently resolve playwright false without a failure. npx may
 * not exist here, so this asserts the row was reached — even a `failed` row proves it.
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
