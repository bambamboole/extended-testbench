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
 * `$installs` controls what `requirePackages()` returns, so a test can
 * simulate a failed `composer require`.
 */
function bindInit(string $root, bool $installs = true): void
{
    $composer = Mockery::mock(Composer::class.'[requirePackages,dumpAutoloads]', [new Filesystem, $root]);
    $composer->shouldReceive('requirePackages')->andReturn($installs);
    $composer->shouldReceive('dumpAutoloads')->andReturn(true);

    app()->instance(InitCommand::class, new InitCommand($composer, $root));
}

it('registers the package:init command', function () {
    expect(array_keys($this->app[Kernel::class]->all()))
        ->toContain('package:init');
});

it('builds the command with the package root and a composer instance', function () {
    $command = $this->app->make(InitCommand::class);

    expect($this->app->make(InitCommand::class))->toBe($command)
        ->and($command->getName())->toBe('package:init');
});

it('scaffolds the pest baseline when everything else is declined', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
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

it('creates the Unit and Feature test directories with a .gitkeep so PHPUnit does not fail to boot', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->assertSuccessful();

    expect($this->root.'/tests/Unit')->toBeDirectory()
        ->and($this->root.'/tests/Unit/.gitkeep')->toBeFile()
        ->and($this->root.'/tests/Feature')->toBeDirectory()
        ->and($this->root.'/tests/Feature/.gitkeep')->toBeFile();
});

it('reports the test directories as skipped when their .gitkeep already exists', function () {
    mkdir($this->root.'/tests/Unit', 0755, true);
    mkdir($this->root.'/tests/Feature', 0755, true);
    file_put_contents($this->root.'/tests/Unit/.gitkeep', '');
    file_put_contents($this->root.'/tests/Feature/.gitkeep', '');

    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->expectsPromptsTable(['File', 'Result'], [
            ['tests/Unit', 'skipped (exists)'],
            ['tests/Feature', 'skipped (exists)'],
            ['phpunit.xml.dist', 'written'],
            ['tests/TestCase.php', 'written'],
            ['tests/Pest.php', 'written'],
            ['testbench.yaml', 'written'],
            ['composer script: test', 'added'],
        ])
        ->assertSuccessful();
});

it('records a failed outcome instead of a false "written" when a path is blocked by a file', function () {
    // A regular file named `tests` makes every `mkdir('tests/...', recursive: true)` fail with
    // "File exists", deterministically and regardless of the runner's UID (unlike chmod, which
    // root ignores). This blocks both testDirectory() (tests/Unit, tests/Feature) and write()
    // (tests/TestCase.php, tests/Pest.php).
    file_put_contents($this->root.'/tests', 'not a directory');

    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->expectsPromptsTable(['File', 'Result'], [
            ['tests/Unit', 'failed'],
            ['tests/Feature', 'failed'],
            ['phpunit.xml.dist', 'written'],
            ['tests/TestCase.php', 'failed'],
            ['tests/Pest.php', 'failed'],
            ['testbench.yaml', 'written'],
            ['composer script: test', 'added'],
        ])
        ->assertSuccessful();

    expect($this->root.'/tests')->toBeFile()
        ->and($this->root.'/tests')->not->toBeDirectory();
});

it('keeps existing files when the overwrite prompt is declined', function () {
    file_put_contents($this->root.'/phpunit.xml.dist', 'ORIGINAL');
    mkdir($this->root.'/tests', 0755, true);
    file_put_contents($this->root.'/tests/Pest.php', '<?php // ORIGINAL PEST');

    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
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
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->expectsConfirmation('Overwrite phpunit.xml.dist?', 'yes')
        ->expectsOutputToContain('overwritten')
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/phpunit.xml.dist'))->toContain(':memory:');
});

it('warns instead of overwriting when browser tests are accepted but the phpunit config is kept', function () {
    file_put_contents($this->root.'/phpunit.xml.dist', 'ORIGINAL');

    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add browser tests?', 'yes')
        ->expectsConfirmation('Install Playwright browsers now?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->expectsConfirmation('Overwrite phpunit.xml.dist?', 'no')
        ->expectsPromptsWarning('phpunit.xml.dist does not include the Browser testsuite — add it by hand.')
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/phpunit.xml.dist'))->toBe('ORIGINAL')
        ->and($this->root.'/tests/Browser/DummyTest.php')->toBeFile();
});

it('reports failure and records it in the summary when a composer install fails', function () {
    bindInit($this->root, installs: false);

    $this->artisan('package:init')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->expectsPromptsTable(['File', 'Result'], [
            ['pestphp/pest:^5.0', 'failed'],
            ['tests/Unit/.gitkeep', 'written'],
            ['tests/Feature/.gitkeep', 'written'],
            ['phpunit.xml.dist', 'written'],
            ['tests/TestCase.php', 'written'],
            ['tests/Pest.php', 'written'],
            ['testbench.yaml', 'written'],
            ['composer script: test', 'added'],
        ])
        ->expectsPromptsError('Failed to install: pestphp/pest:^5.0')
        ->assertFailed();

    // The rest of the run still happens: files are written despite the failed install.
    expect($this->root.'/phpunit.xml.dist')->toBeFile();
});

it('scaffolds browser tests when accepted', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add browser tests?', 'yes')
        ->expectsConfirmation('Install Playwright browsers now?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
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
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
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
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->assertSuccessful();

    expect($this->root.'/tests/Browser')->not->toBeDirectory();
});

it('scaffolds phpstan when accepted', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'yes')
        ->expectsChoice('PHPStan level', '6', ['5', '6', '7', '8', '9', 'max'])
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
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
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/phpstan.neon.dist'))->toContain('level: 8');
});

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
