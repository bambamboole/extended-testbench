<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Commands\InitCommand;
use Illuminate\Contracts\Console\Kernel;
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
 * Binds a concrete InitCommand instance rooted at the temp package into the
 * container, so `$this->artisan('package:init')` resolves it instead of the
 * real singleton the service provider registers (which is rooted at
 * package_path() — this repository itself). Never let that real singleton
 * resolve in a test: it would write into this repo and shell out to a real
 * `composer require`.
 *
 * The Composer double keeps the real hasPackage()/modify() (they only touch
 * the temp composer.json) and stubs out the two methods that shell out.
 */
function bindInit(string $root): void
{
    $composer = Mockery::mock(Composer::class.'[requirePackages,dumpAutoloads]', [new Filesystem, $root]);
    $composer->shouldReceive('requirePackages')->andReturnTrue();
    $composer->shouldReceive('dumpAutoloads')->andReturnTrue();

    app()->instance(InitCommand::class, new InitCommand($composer, $root));
}

it('registers the package:init command', function () {
    expect(array_keys($this->app[Kernel::class]->all()))
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
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
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
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
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
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Overwrite phpunit.xml.dist?', 'yes')
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/phpunit.xml.dist'))->toContain(':memory:');
});

it('scaffolds browser tests when accepted', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add browser tests?', 'yes')
        ->expectsConfirmation('Install Playwright browsers now?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
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
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
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
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->assertSuccessful();

    expect($this->root.'/tests/Browser')->not->toBeDirectory();
});

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
