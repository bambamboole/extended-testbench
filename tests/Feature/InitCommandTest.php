<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Commands\InitCommand;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Mockery\MockInterface;

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
 * Binds an InitCommand rooted at the temp package, so `$this->artisan('package:init')` resolves it
 * instead of the service provider's singleton — which is rooted at package_path(), this repository
 * itself, and would write into it and shell out to a real `composer require`.
 *
 * The Composer double keeps the real hasPackage()/modify() (they only touch the temp
 * composer.json) and stubs the two methods that shell out.
 */
function bindInit(string $root, bool $installs = true): void
{
    /** @var Composer&MockInterface $composer */
    $composer = Mockery::mock(Composer::class.'[requirePackages,dumpAutoloads]', [new Filesystem, $root]);
    $composer->shouldReceive('requirePackages')->andReturn($installs);
    $composer->shouldReceive('dumpAutoloads')->andReturn(true);

    app()->instance(InitCommand::class, new InitCommand($composer, $root));
}

/**
 * Brings the temp package to the state a real `package:init` leaves behind, so --check has nothing
 * legitimate to report: the Composer double never writes the require-dev entries it claims to
 * install, and Boost never runs, so both would otherwise show up as drift.
 */
function completeScaffold(string $root): void
{
    $composer = json_decode((string) file_get_contents($root.'/composer.json'), true);

    foreach ([
        'pestphp/pest',
        'pestphp/pest-plugin-laravel',
        'larastan/larastan',
        'pestphp/pest-plugin-phpstan',
        'rector/rector',
        'laravel/pint',
    ] as $package) {
        $composer['require-dev'][$package] = '*';
        // NeedsPackage trusts composer.json only when vendor/ actually holds the package.
        mkdir($root.'/vendor/'.$package, 0755, true);
    }

    $composer['config']['allow-plugins']['pestphp/pest-plugin'] = true;

    file_put_contents($root.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($root.'/boost.json', json_encode([
        'packages' => ['bambamboole/extended-testbench'],
    ], JSON_PRETTY_PRINT));
}

it('registers the package:init command', function () {
    expect(array_keys($this->app[Kernel::class]->all()))
        ->toContain('package:init');
});

it('resolves the InitCommand singleton bound as package:init', function () {
    $command = $this->app->make(InitCommand::class);

    expect($this->app->make(InitCommand::class))->toBe($command)
        ->and($command->getName())->toBe('package:init');
});

it('scaffolds the pest baseline when everything else is declined', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add a workbench app?', 'no')
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
        ->not->toContain('name="Browser"');

    expect(file_get_contents($this->root.'/tests/TestCase.php'))
        ->toContain('namespace Tests;')
        ->toContain('\Acme\Demo\DemoServiceProvider::class');

    expect(file_get_contents($this->root.'/tests/Pest.php'))
        ->toContain('use Tests\TestCase;')
        ->toContain("uses(TestCase::class)->in('Feature', 'Unit');");

    expect(file_get_contents($this->root.'/testbench.yaml'))
        ->toContain("laravel: '@testbench'")
        ->toContain('Acme\Demo\DemoServiceProvider');

    $composerJson = json_decode(file_get_contents($this->root.'/composer.json'), true);

    expect($composerJson['scripts']['test'])->toBe('pest')
        ->and($composerJson['autoload-dev']['psr-4']['Tests\\'])->toBe('tests/')
        ->and($composerJson['scripts'])->not->toHaveKeys(['stan', 'refactor', 'lint']);
});

it('writes an artisan entrypoint that requires vendor/bin/testbench', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add a workbench app?', 'no')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->assertSuccessful();

    expect($this->root.'/artisan')->toBeFile()
        ->and(file_get_contents($this->root.'/artisan'))->toContain("require __DIR__.'/vendor/bin/testbench';");
});

it('leaves an existing artisan entrypoint alone', function () {
    file_put_contents($this->root.'/artisan', "<?php // custom entrypoint\n");

    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add a workbench app?', 'no')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/artisan'))->toBe("<?php // custom entrypoint\n");
});

it('writes a gitattributes that keeps development files out of the dist archive', function () {
    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/.gitattributes'))
        ->toContain('/tests export-ignore')
        ->toContain('/workbench export-ignore')
        ->toContain('/.ai export-ignore');
});

it('creates the Unit and Feature test directories with a .gitkeep so PHPUnit does not fail to boot', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add a workbench app?', 'no')
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
        ->expectsConfirmation('Add a workbench app?', 'no')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->expectsPromptsTable(['File', 'Result'], [
            ['artisan', 'written'],
            ['.gitattributes', 'written'],
            ['.github/workflows/ci.yml', 'written'],
            ['.gitignore', 'written'],
            ['composer allow-plugins: pestphp/pest-plugin', 'allowed'],
            ['tests/Unit', 'skipped (exists)'],
            ['tests/Feature', 'skipped (exists)'],
            ['phpunit.xml.dist', 'written'],
            ['tests/TestCase.php', 'written'],
            ['tests/Pest.php', 'written'],
            ['testbench.yaml', 'written'],
            ['composer script: test', 'added'],
            ['composer script: check', 'added'],
            ['composer script: post-autoload-dump', 'added'],
            ['composer script: boost:refresh', 'added'],
            ['composer script: post-install-cmd', 'added'],
            ['composer script: post-update-cmd', 'added'],
            ['boost:install', 'skipped (no vendor/bin/testbench)'],
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
        ->expectsConfirmation('Add a workbench app?', 'no')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->expectsPromptsTable(['File', 'Result'], [
            ['artisan', 'written'],
            ['.gitattributes', 'written'],
            ['.github/workflows/ci.yml', 'written'],
            ['.gitignore', 'written'],
            ['composer allow-plugins: pestphp/pest-plugin', 'allowed'],
            ['tests/Unit', 'failed'],
            ['tests/Feature', 'failed'],
            ['phpunit.xml.dist', 'written'],
            ['tests/TestCase.php', 'failed'],
            ['tests/Pest.php', 'failed'],
            ['testbench.yaml', 'written'],
            ['composer script: test', 'added'],
            ['composer script: check', 'added'],
            ['composer script: post-autoload-dump', 'added'],
            ['composer script: boost:refresh', 'added'],
            ['composer script: post-install-cmd', 'added'],
            ['composer script: post-update-cmd', 'added'],
            ['boost:install', 'skipped (no vendor/bin/testbench)'],
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
        ->expectsConfirmation('Add a workbench app?', 'no')
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
        ->expectsConfirmation('Add a workbench app?', 'no')
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
        ->expectsConfirmation('Add a workbench app?', 'no')
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
        ->expectsConfirmation('Add a workbench app?', 'no')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->expectsPromptsTable(['File', 'Result'], [
            ['artisan', 'written'],
            ['.gitattributes', 'written'],
            ['.github/workflows/ci.yml', 'written'],
            ['.gitignore', 'written'],
            ['composer allow-plugins: pestphp/pest-plugin', 'allowed'],
            ['pestphp/pest:^5.0', 'failed'],
            ['pestphp/pest-plugin-laravel:^5.0', 'failed'],
            ['tests/Unit/.gitkeep', 'written'],
            ['tests/Feature/.gitkeep', 'written'],
            ['phpunit.xml.dist', 'written'],
            ['tests/TestCase.php', 'written'],
            ['tests/Pest.php', 'written'],
            ['testbench.yaml', 'written'],
            ['composer script: test', 'added'],
            ['composer script: check', 'added'],
            ['composer script: post-autoload-dump', 'added'],
            ['composer script: boost:refresh', 'added'],
            ['composer script: post-install-cmd', 'added'],
            ['composer script: post-update-cmd', 'added'],
            ['boost:install', 'skipped (no vendor/bin/testbench)'],
        ])
        ->expectsPromptsError('Failed to install: pestphp/pest:^5.0, pestphp/pest-plugin-laravel:^5.0')
        ->assertFailed();

    // The rest of the run still happens: files are written despite the failed install.
    expect($this->root.'/phpunit.xml.dist')->toBeFile();
});

it('scaffolds browser tests when accepted', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add a workbench app?', 'no')
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
        ->toContain("->in('Feature', 'Unit');")
        ->toContain("uses(\\Tests\\BrowserTestCase::class)->in('Browser');");

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
        ->expectsConfirmation('Add a workbench app?', 'no')
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

it('warns instead of silently keeping a 0.2.0-era Browser mapping to the base TestCase', function () {
    mkdir($this->root.'/tests', 0755, true);
    file_put_contents(
        $this->root.'/tests/Pest.php',
        "<?php\n\ndeclare(strict_types=1);\n\nuses(\\Tests\\TestCase::class)->in('Feature', 'Unit', 'Browser');\n",
    );

    bindInit($this->root);

    $this->artisan('package:init', [
        '--no-interaction' => true,
        '--browser' => true,
        '--no-playwright' => true,
        '--defaults' => true,
    ])
        ->expectsOutputToContain("tests/Pest.php already maps 'Browser' to the base TestCase")
        ->assertSuccessful();

    expect($this->root.'/tests/BrowserTestCase.php')->toBeFile();

    $pest = (string) file_get_contents($this->root.'/tests/Pest.php');

    expect($pest)->toBe("<?php\n\ndeclare(strict_types=1);\n\nuses(\\Tests\\TestCase::class)->in('Feature', 'Unit', 'Browser');\n")
        ->and($pest)->not->toContain('BrowserTestCase');
});

it('does not scaffold browser tests when declined', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add a workbench app?', 'no')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->assertSuccessful();

    expect($this->root.'/tests/Browser')->not->toBeDirectory();
});

it('writes a browser test case that guards the vite manifest', function () {
    bindInit($this->root);

    $this->artisan('package:init', [
        '--no-interaction' => true,
        '--browser' => true,
        '--no-playwright' => true,
        '--defaults' => true,
    ])->assertSuccessful();

    expect($this->root.'/tests/BrowserTestCase.php')->toBeFile();

    $testCase = (string) file_get_contents($this->root.'/tests/BrowserTestCase.php');

    expect($testCase)->toContain('namespace Tests;')
        ->toContain('abstract class BrowserTestCase extends TestCase')
        ->toContain('vite.config')
        ->toContain('manifest.json');

    expect(file_get_contents($this->root.'/tests/Pest.php'))
        ->toContain("uses(\\Tests\\BrowserTestCase::class)->in('Browser');");
});

it('does not write a browser test case when browser tests are declined', function () {
    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    expect($this->root.'/tests/BrowserTestCase.php')->not->toBeFile();
});

it('scaffolds phpstan when accepted', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add a workbench app?', 'no')
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
        ->expectsConfirmation('Add a workbench app?', 'no')
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
        ->expectsConfirmation('Add a workbench app?', 'no')
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
        ->toBe(['preset' => 'laravel', 'rules' => [
            'declare_strict_types' => true,
            'blank_line_after_opening_tag' => false,
        ]]);

    $composerJson = json_decode(file_get_contents($this->root.'/composer.json'), true);

    expect($composerJson['scripts']['refactor'])->toBe('rector')
        ->and($composerJson['scripts']['lint'])->toBe('pint --format agent');
});

it('skips the rector rule that strips reflection-resolved laravel parameters', function () {
    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/rector.php'))
        ->toContain('RemoveUnusedPublicMethodParameterRector');
});

it('writes the gitignore entries that init and boost cause to exist', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add a workbench app?', 'no')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/.gitignore'))
        ->toContain('/vendor/')
        ->toContain('/composer.lock')
        ->toContain('/CLAUDE.md')
        ->toContain('/AGENTS.md')
        ->toContain('/.mcp.json')
        ->toContain('/.claude/skills/')
        ->toContain('/.agents/')
        ->toContain('/.junie/')
        ->toContain('/.codex/')
        ->toContain('/.superpowers/')
        ->toContain('/docs/superpowers/')
        ->not->toContain('/artisan');
});

it('ignores every agent planning artifact the shipped guideline names', function () {
    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    $gitignore = (string) file_get_contents($this->root.'/.gitignore');
    $guideline = (string) file_get_contents(__DIR__.'/../../resources/boost/guidelines/core.blade.php');

    preg_match('/Never commit agent planning artifacts\.[^\n]+/', $guideline, $matches);
    preg_match_all('/`([^`]+)`/', $matches[0] ?? '', $artifacts);

    expect($artifacts[1])->not->toBeEmpty();

    foreach ($artifacts[1] as $artifact) {
        expect($gitignore)->toContain(trim($artifact, '/'));
    }
});

it('appends only the gitignore entries that are missing', function () {
    file_put_contents($this->root.'/.gitignore', "/vendor/\n/CLAUDE.md\n");

    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add a workbench app?', 'no')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->assertSuccessful();

    $gitignore = file_get_contents($this->root.'/.gitignore');

    expect(substr_count((string) $gitignore, '/vendor/'))->toBe(1)
        ->and(substr_count((string) $gitignore, '/CLAUDE.md'))->toBe(1)
        ->and($gitignore)->toStartWith("/vendor/\n/CLAUDE.md\n")
        ->and($gitignore)->toContain('/.junie/');
});

it('composes a check script from the accepted tools', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add a workbench app?', 'no')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'yes')
        ->expectsChoice('PHPStan level', '6', ['5', '6', '7', '8', '9', 'max'])
        ->expectsConfirmation('Add Rector?', 'yes')
        ->expectsConfirmation('Add Pint?', 'yes')
        ->assertSuccessful();

    $composerJson = json_decode((string) file_get_contents($this->root.'/composer.json'), true);

    expect($composerJson['scripts']['check'])->toBe([
        'pint --test',
        'phpstan analyse',
        'rector --dry-run',
        '@test',
    ]);
});

it('leaves the declined tools out of the check script', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add a workbench app?', 'no')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->assertSuccessful();

    $composerJson = json_decode((string) file_get_contents($this->root.'/composer.json'), true);

    expect($composerJson['scripts']['check'])->toBe(['@test']);
});

it('adds the workbench block to testbench.yaml when a workbench app is accepted', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add a workbench app?', 'yes')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/testbench.yaml'))
        ->toContain('workbench:')
        ->toContain("start: '/'")
        ->toContain('- create-sqlite-db')
        ->toContain('Acme\Demo\DemoServiceProvider');
});

it('analyses workbench/app when the package has one', function () {
    mkdir($this->root.'/workbench/app', 0755, true);

    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add a workbench app?', 'no')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'yes')
        ->expectsChoice('PHPStan level', '6', ['5', '6', '7', '8', '9', 'max'])
        ->expectsConfirmation('Add Rector?', 'yes')
        ->expectsConfirmation('Add Pint?', 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/phpstan.neon.dist'))->toContain('- workbench/app')
        ->and(file_get_contents($this->root.'/rector.php'))->toContain("__DIR__.'/workbench/app'");
});

it('leaves workbench/app out of the analysed paths when the package has none', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add a workbench app?', 'no')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'yes')
        ->expectsChoice('PHPStan level', '6', ['5', '6', '7', '8', '9', 'max'])
        ->expectsConfirmation('Add Rector?', 'yes')
        ->expectsConfirmation('Add Pint?', 'no')
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/phpstan.neon.dist'))->not->toContain('workbench')
        ->and(file_get_contents($this->root.'/rector.php'))->not->toContain('workbench');
});

it('analyses the database directory when the package has one', function () {
    mkdir($this->root.'/database/factories', 0755, true);

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/phpstan.neon.dist'))->toContain('- database');
});

it('leaves the database directory out when the package has none', function () {
    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/phpstan.neon.dist'))->not->toContain('database');
});

it('selects boost:install when the package has no boost.json', function () {
    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add a workbench app?', 'no')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->expectsOutputToContain('boost:install')
        ->assertSuccessful();
});

it('selects boost:update --discover when the package already has boost.json', function () {
    file_put_contents($this->root.'/boost.json', '{"guidelines":true}');

    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add a workbench app?', 'no')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->expectsOutputToContain('boost:update --discover')
        ->assertSuccessful();
});

it('refuses to run headless without flags', function () {
    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true])->assertFailed();

    expect($this->root.'/phpunit.xml.dist')->not->toBeFile()
        ->and($this->root.'/artisan')->not->toBeFile();
});

it('runs headless with --defaults', function () {
    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    expect($this->root.'/phpunit.xml.dist')->toBeFile()
        ->and($this->root.'/phpstan.neon.dist')->toBeFile()
        ->and($this->root.'/rector.php')->toBeFile()
        ->and($this->root.'/pint.json')->toBeFile()
        ->and($this->root.'/tests/Browser')->not->toBeDirectory();
});

it('does not prompt at all when --defaults is passed without --no-interaction', function () {
    // No expectsConfirmation()/expectsChoice() is scripted below on purpose: if resolve() or
    // phpstanLevel() asked anything, Laravel's PendingCommand would fail on the unexpected
    // prompt. Passing --defaults alone reaching assertSuccessful() is the proof.
    bindInit($this->root);

    $this->artisan('package:init', ['--defaults' => true])
        ->assertSuccessful();

    expect($this->root.'/phpunit.xml.dist')->toBeFile()
        ->and($this->root.'/phpstan.neon.dist')->toBeFile();
});

it('takes section flags over the defaults when headless', function () {
    bindInit($this->root);

    $this->artisan('package:init', [
        '--no-interaction' => true,
        '--workbench' => true,
        '--no-phpstan' => true,
        '--no-rector' => true,
        '--no-pint' => true,
        '--phpstan-level' => '8',
    ])->assertSuccessful();

    expect(file_get_contents($this->root.'/testbench.yaml'))->toContain('workbench:')
        ->and($this->root.'/phpstan.neon.dist')->not->toBeFile()
        ->and($this->root.'/rector.php')->not->toBeFile()
        ->and($this->root.'/pint.json')->not->toBeFile();
});

it('writes the phpstan level given on the command line', function () {
    bindInit($this->root);

    $this->artisan('package:init', [
        '--no-interaction' => true,
        '--phpstan' => true,
        '--phpstan-level' => '8',
        '--no-rector' => true,
        '--no-pint' => true,
    ])->assertSuccessful();

    expect(file_get_contents($this->root.'/phpstan.neon.dist'))->toContain('level: 8');
});

it('refuses an invalid --phpstan-level before writing anything', function () {
    bindInit($this->root);

    $this->artisan('package:init', [
        '--no-interaction' => true,
        '--defaults' => true,
        '--phpstan-level' => '99',
    ])->assertFailed();

    expect($this->root.'/phpunit.xml.dist')->not->toBeFile()
        ->and($this->root.'/artisan')->not->toBeFile();
});

it('warns when --playwright is passed but browser tests resolve false', function () {
    bindInit($this->root);

    $this->artisan('package:init', [
        '--no-interaction' => true,
        '--defaults' => true,
        '--playwright' => true,
    ])
        ->expectsOutputToContain('--playwright has no effect because browser tests resolved false')
        ->assertSuccessful();

    expect($this->root.'/tests/Browser')->not->toBeDirectory();
});

it('keeps the browser suite out of test and check', function () {
    bindInit($this->root);

    $this->artisan('package:init', [
        '--no-interaction' => true,
        '--browser' => true,
        '--no-playwright' => true,
        '--no-phpstan' => true,
        '--no-rector' => true,
        '--no-pint' => true,
    ])->assertSuccessful();

    $scripts = json_decode((string) file_get_contents($this->root.'/composer.json'), true)['scripts'];

    expect($scripts['test'])->toBe('pest --testsuite=Unit,Feature')
        ->and($scripts['test:browser'])->toBe('pest --testsuite=Browser')
        ->and($scripts['check'])->toBe(['@test']);
});

it('writes a plain test script when browser tests are declined', function () {
    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    $scripts = json_decode((string) file_get_contents($this->root.'/composer.json'), true)['scripts'];

    expect($scripts['test'])->toBe('pest')
        ->and($scripts)->not->toHaveKey('test:browser')
        ->and($scripts['check'])->toBe(['pint --test', 'phpstan analyse', 'rector --dry-run', '@test']);
});

it('builds assets before the browser suite when the package has a package.json', function () {
    file_put_contents($this->root.'/package.json', '{"name":"acme"}');

    bindInit($this->root);

    $this->artisan('package:init', [
        '--no-interaction' => true,
        '--browser' => true,
        '--no-playwright' => true,
        '--no-phpstan' => true,
        '--no-rector' => true,
        '--no-pint' => true,
    ])->assertSuccessful();

    $scripts = json_decode((string) file_get_contents($this->root.'/composer.json'), true)['scripts'];

    expect($scripts['test:browser'])->toBe(['npm run build', 'pest --testsuite=Browser']);
});

it('scaffolds the testbench autoload hooks and a guarded boost refresh', function () {
    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    $scripts = json_decode((string) file_get_contents($this->root.'/composer.json'), true)['scripts'];

    expect($scripts['post-autoload-dump'])->toBe([
        '@php vendor/bin/testbench package:purge-skeleton --ansi',
        '@php vendor/bin/testbench package:discover --ansi',
    ])
        ->and($scripts['post-install-cmd'])->toBe(['@boost:refresh'])
        ->and($scripts['post-update-cmd'])->toBe(['@boost:refresh'])
        ->and($scripts['boost:refresh'])->toBe(
            '[ -n "$CI" ] || [ ! -f vendor/bin/testbench ] || [ ! -f boost.json ] || { [ -f package.json ] && [ ! -d node_modules ]; } || vendor/bin/testbench boost:update --no-interaction || true',
        );
});

it('treats gitignore entries as equivalent regardless of a leading slash', function () {
    file_put_contents($this->root.'/.gitignore', "vendor/\ncomposer.lock\n.phpunit.cache/\n");

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    $gitignore = (string) file_get_contents($this->root.'/.gitignore');

    expect(substr_count($gitignore, 'composer.lock'))->toBe(1)
        ->and(substr_count($gitignore, 'vendor/'))->toBe(1)
        ->and(substr_count($gitignore, '.phpunit.cache/'))->toBe(1)
        ->and($gitignore)->toContain('/.junie/');
});

it('treats gitignore entries as equivalent regardless of a trailing slash', function () {
    file_put_contents($this->root.'/.gitignore', "vendor\n.phpunit.cache\n.agents\n");

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    $gitignore = (string) file_get_contents($this->root.'/.gitignore');

    expect(substr_count($gitignore, 'vendor'))->toBe(1)
        ->and(substr_count($gitignore, '.phpunit.cache'))->toBe(1)
        ->and(substr_count($gitignore, '.agents'))->toBe(1)
        ->and($gitignore)->toStartWith("vendor\n.phpunit.cache\n.agents\n");
});

it('registers itself in the boost.json packages key', function () {
    file_put_contents($this->root.'/boost.json', json_encode(['guidelines' => true], JSON_PRETTY_PRINT));

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    $boost = json_decode((string) file_get_contents($this->root.'/boost.json'), true);

    expect($boost['packages'])->toBe(['bambamboole/extended-testbench'])
        ->and($boost['guidelines'])->toBeTrue();
});

it('does not duplicate itself in an existing packages key', function () {
    file_put_contents($this->root.'/boost.json', json_encode([
        'guidelines' => true,
        'packages' => ['bambamboole/extended-testbench', 'acme/other'],
    ], JSON_PRETTY_PRINT));

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    $boost = json_decode((string) file_get_contents($this->root.'/boost.json'), true);

    expect($boost['packages'])->toBe(['bambamboole/extended-testbench', 'acme/other']);
});

it('treats a malformed packages key as empty instead of throwing', function () {
    file_put_contents($this->root.'/boost.json', json_encode([
        'guidelines' => true,
        'packages' => 'foo',
    ], JSON_PRETTY_PRINT));

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    $boost = json_decode((string) file_get_contents($this->root.'/boost.json'), true);

    expect($boost['packages'])->toBe(['bambamboole/extended-testbench']);
});

it('records boost.json as failed (unreadable) instead of silently skipping unparseable json', function () {
    file_put_contents($this->root.'/boost.json', '{not valid json');

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->expectsOutputToContain('failed (unreadable)')
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/boost.json'))->toBe('{not valid json');
});

it('keeps boost.json keys sorted the way Boost\'s own writer does after registering the guideline', function () {
    file_put_contents($this->root.'/boost.json', json_encode([
        'guidelines' => true,
        'agents' => ['claude'],
    ], JSON_PRETTY_PRINT));

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    $boost = json_decode((string) file_get_contents($this->root.'/boost.json'), true);

    expect(array_keys($boost))->toBe(['agents', 'guidelines', 'packages']);
});

it('tells the user to rerun boost:update after registering the guideline', function () {
    file_put_contents($this->root.'/boost.json', json_encode(['guidelines' => true], JSON_PRETTY_PRINT));

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->expectsOutputToContain('Run vendor/bin/testbench boost:update to compose this guideline')
        ->assertSuccessful();
});

/**
 * The skeleton's own .env carries this key, but the post-autoload-dump hook this command writes runs
 * package:purge-skeleton, which deletes that file — and Testbench's EnsuresDefaultConfiguration
 * fallback does not actually land (it hands Repository::set() a list of arrays), so app.key ends up
 * null and anything touching the encrypter throws MissingAppKeyException on a cold suite.
 */
it('bakes the skeleton app key into the generated phpunit config', function () {
    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/phpunit.xml.dist'))
        ->toContain('<env name="APP_KEY" value="AckfSECXIvnK5r28GVIWUAxmbBSjTsmF"/>');
});

it('skips existing generated configs and says how to replace them', function () {
    foreach (['phpunit.xml.dist', 'phpstan.neon.dist', 'rector.php', 'pint.json'] as $file) {
        file_put_contents($this->root.'/'.$file, 'mine');
    }

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->expectsOutputToContain('--force to replace')
        ->assertSuccessful();

    foreach (['phpunit.xml.dist', 'phpstan.neon.dist', 'rector.php', 'pint.json'] as $file) {
        expect(file_get_contents($this->root.'/'.$file))->toBe('mine');
    }
});

it('replaces existing generated configs when --force is passed', function () {
    foreach (['phpunit.xml.dist', 'phpstan.neon.dist', 'rector.php', 'pint.json'] as $file) {
        file_put_contents($this->root.'/'.$file, 'mine');
    }

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true, '--force' => true])
        ->expectsOutputToContain('overwritten')
        ->assertSuccessful();

    foreach (['phpunit.xml.dist', 'phpstan.neon.dist', 'rector.php', 'pint.json'] as $file) {
        expect(file_get_contents($this->root.'/'.$file))->not->toBe('mine');
    }
});

it('never lets --force overwrite the files that hold hand-written code', function () {
    mkdir($this->root.'/tests', 0755, true);

    foreach (['tests/TestCase.php', 'tests/Pest.php', 'testbench.yaml', 'artisan', '.gitattributes'] as $file) {
        file_put_contents($this->root.'/'.$file, 'mine');
    }

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true, '--force' => true])
        ->assertSuccessful();

    foreach (['tests/TestCase.php', 'tests/Pest.php', 'testbench.yaml', 'artisan', '.gitattributes'] as $file) {
        expect(file_get_contents($this->root.'/'.$file))->toBe('mine');
    }
});

it('warns when a legacy phpunit.xml will shadow the generated dist config', function () {
    file_put_contents($this->root.'/phpunit.xml', '<phpunit/>');

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->expectsOutputToContain('phpunit.xml already exists and takes precedence over phpunit.xml.dist')
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/phpunit.xml'))->toBe('<phpunit/>')
        ->and($this->root.'/phpunit.xml.dist')->toBeFile();
});

it('warns when artisan is still a symlink instead of the shim', function () {
    // Points at composer.json (already written in beforeEach) rather than vendor/bin/testbench so
    // the symlink resolves to a real file — a *non-dangling* symlink, deliberately, since a dangling
    // one is now replaced instead of warned about (see the "dangling artisan symlink" test below).
    symlink('composer.json', $this->root.'/artisan');

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->expectsOutputToContain('symlink')
        ->assertSuccessful();

    expect(is_link($this->root.'/artisan'))->toBeTrue();
});

it('replaces a dangling artisan symlink instead of writing through it', function () {
    symlink($this->root.'/does-not-exist', $this->root.'/artisan');

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    expect(is_link($this->root.'/artisan'))->toBeFalse()
        ->and(file_get_contents($this->root.'/artisan'))->toContain("require __DIR__.'/vendor/bin/testbench';");
});

it('keeps a blocked phpunit.xml.dist write reported as failed even when a legacy phpunit.xml also shadows it', function () {
    // A directory at the dist target makes file_put_contents() fail deterministically: file_exists()
    // treats it as already there (so the overwrite prompt fires), but writing to a directory path
    // always fails, regardless of the runner's UID. This is the same "block with a real filesystem
    // entry" trick the "records a failed outcome" test above uses for tests/Unit and tests/Feature.
    file_put_contents($this->root.'/phpunit.xml', '<phpunit/>');
    mkdir($this->root.'/phpunit.xml.dist', 0755, true);

    bindInit($this->root);

    $this->artisan('package:init')
        ->expectsConfirmation('Add a workbench app?', 'no')
        ->expectsConfirmation('Add browser tests?', 'no')
        ->expectsConfirmation('Add PHPStan (Larastan)?', 'no')
        ->expectsConfirmation('Add Rector?', 'no')
        ->expectsConfirmation('Add Pint?', 'no')
        ->expectsConfirmation('Overwrite phpunit.xml.dist?', 'yes')
        ->expectsOutputToContain('phpunit.xml already exists and takes precedence over phpunit.xml.dist')
        ->expectsPromptsTable(['File', 'Result'], [
            ['artisan', 'written'],
            ['.gitattributes', 'written'],
            ['.github/workflows/ci.yml', 'written'],
            ['.gitignore', 'written'],
            ['composer allow-plugins: pestphp/pest-plugin', 'allowed'],
            ['tests/Unit/.gitkeep', 'written'],
            ['tests/Feature/.gitkeep', 'written'],
            ['phpunit.xml.dist', 'failed'],
            ['tests/TestCase.php', 'written'],
            ['tests/Pest.php', 'written'],
            ['testbench.yaml', 'written'],
            ['composer script: test', 'added'],
            ['composer script: check', 'added'],
            ['composer script: post-autoload-dump', 'added'],
            ['composer script: boost:refresh', 'added'],
            ['composer script: post-install-cmd', 'added'],
            ['composer script: post-update-cmd', 'added'],
            ['boost:install', 'skipped (no vendor/bin/testbench)'],
        ])
        ->assertSuccessful();

    expect($this->root.'/phpunit.xml.dist')->toBeDirectory()
        ->and(file_get_contents($this->root.'/phpunit.xml'))->toBe('<phpunit/>');
});

it('scaffolds a CI workflow with a php matrix, the check script and the drift gate', function () {
    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/.github/workflows/ci.yml'))
        ->toContain('composer check')
        ->toContain("php: ['8.4', '8.5']")
        ->toContain('php vendor/bin/testbench package:init --check');
});

it('scaffolds a testbench.yaml that keeps the app in the local environment boost needs', function () {
    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/testbench.yaml'))
        ->toContain("env:\n  APP_ENV: local");
});

it('replaces a working artisan symlink pointing at the testbench binary', function () {
    mkdir($this->root.'/vendor/bin', 0755, true);
    file_put_contents($this->root.'/vendor/bin/testbench', "#!/usr/bin/env php\n");
    symlink($this->root.'/vendor/bin/testbench', $this->root.'/artisan');

    expect($this->root.'/artisan')->toBeReadableFile();

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    expect(is_link($this->root.'/artisan'))->toBeFalse()
        ->and(file_get_contents($this->root.'/artisan'))
        ->toContain("require __DIR__.'/vendor/bin/testbench';");
});

it('leaves an artisan symlink pointing somewhere else alone', function () {
    file_put_contents($this->root.'/elsewhere.php', "<?php // mine\n");
    symlink($this->root.'/elsewhere.php', $this->root.'/artisan');

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->expectsOutputToContain('symlink to something other than vendor/bin/testbench')
        ->assertSuccessful();

    expect(is_link($this->root.'/artisan'))->toBeTrue()
        ->and(file_get_contents($this->root.'/artisan'))->toBe("<?php // mine\n");
});

it('warns when a composer script already runs the same tool under another name', function () {
    $composer = json_decode((string) file_get_contents($this->root.'/composer.json'), true);
    $composer['scripts'] = ['analyse' => 'phpstan analyse --memory-limit=2G'];
    file_put_contents($this->root.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->expectsOutputToContain("composer script 'analyse' already runs phpstan")
        ->assertSuccessful();

    $scripts = json_decode((string) file_get_contents($this->root.'/composer.json'), true)['scripts'];

    expect($scripts)->toHaveKeys(['analyse', 'stan']);
});

it('composes the guideline in the same run instead of only registering it', function () {
    file_put_contents($this->root.'/boost.json', json_encode(['guidelines' => true], JSON_PRETTY_PRINT));

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->expectsOutputToContain('boost:update')
        ->assertSuccessful();

    $boost = json_decode((string) file_get_contents($this->root.'/boost.json'), true);

    expect($boost['packages'])->toBe(['bambamboole/extended-testbench']);
});

it('reports drift and writes absolutely nothing under --check', function () {
    $before = ['composer.json' => (string) file_get_contents($this->root.'/composer.json')];

    bindInit($this->root);

    $this->artisan('package:init', ['--check' => true])
        ->expectsOutputToContain('missing')
        ->assertFailed();

    expect(file_get_contents($this->root.'/composer.json'))->toBe($before['composer.json'])
        ->and($this->root.'/phpunit.xml.dist')->not->toBeFile()
        ->and($this->root.'/artisan')->not->toBeFile()
        ->and($this->root.'/.gitignore')->not->toBeFile()
        ->and($this->root.'/tests')->not->toBeDirectory();
});

it('reports no drift for a package it just scaffolded', function () {
    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    completeScaffold($this->root);
    bindInit($this->root);

    // doesntExpectOutputToContain('written') guards the state reset in handle(): the command is a
    // container singleton whose instance Artisan caches, so without it the scaffold run's rows are
    // still in $results and every one of them is reported as drift by the check that follows.
    $this->artisan('package:init', ['--check' => true])
        ->doesntExpectOutputToContain('written')
        ->expectsOutputToContain('No drift')
        ->assertSuccessful();
});

it('reports a customised generated config as differing rather than missing', function () {
    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    file_put_contents($this->root.'/pint.json', '{"preset":"laravel"}');

    bindInit($this->root);

    $this->artisan('package:init', ['--check' => true])
        ->expectsOutputToContain('differs')
        ->assertFailed();

    expect(file_get_contents($this->root.'/pint.json'))->toBe('{"preset":"laravel"}');
});

it('does not treat a hand-edited TestCase as drift', function () {
    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    file_put_contents($this->root.'/tests/TestCase.php', "<?php\n// heavily customised\n");

    completeScaffold($this->root);
    bindInit($this->root);

    $this->artisan('package:init', ['--check' => true])
        ->expectsOutputToContain('No drift')
        ->assertSuccessful();
});

it('runs --check without a terminal, without --defaults and without section flags', function () {
    bindInit($this->root);

    $this->artisan('package:init', ['--check' => true, '--no-interaction' => true])
        ->doesntExpectOutputToContain('needs an interactive terminal')
        ->assertFailed();
});

it('spots a script collision even when the existing command is a vendor binary path', function () {
    $composer = json_decode((string) file_get_contents($this->root.'/composer.json'), true);
    $composer['scripts'] = ['analyse' => './vendor/bin/phpstan analyse'];
    file_put_contents($this->root.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->expectsOutputToContain("composer script 'analyse' already runs phpstan")
        ->assertSuccessful();
});

it('reports a composer script wired to a different pipeline as differing, not ok', function () {
    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    completeScaffold($this->root);

    $composer = json_decode((string) file_get_contents($this->root.'/composer.json'), true);
    $composer['scripts']['check'] = ['echo "something else entirely"'];
    file_put_contents($this->root.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    bindInit($this->root);

    // One unambiguous substring rather than two: every line carrying 'differs' here also carries
    // 'composer script: check', and Mockery hands each write to the first matching expectation, so
    // the second would never be consumed.
    $this->artisan('package:init', ['--check' => true])
        ->expectsOutputToContain('composer script: check: differs')
        ->assertFailed();
});

it('treats a gitignore entry as covered when a parent directory is already ignored', function () {
    file_put_contents($this->root.'/.gitignore', ".claude\n");

    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    expect(file_get_contents($this->root.'/.gitignore'))->not->toContain('.claude/skills');
});

it('does not report a purely reformatted config as drift', function () {
    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    completeScaffold($this->root);

    // Same content, different formatting: one line instead of four, and no trailing newline.
    $pint = (string) file_get_contents($this->root.'/pint.json');
    file_put_contents($this->root.'/pint.json', preg_replace('/\s+/', '', $pint));

    bindInit($this->root);

    $this->artisan('package:init', ['--check' => true])
        ->expectsOutputToContain('No drift')
        ->assertSuccessful();
});

it('baselines an intentional divergence through the check-ignore list', function () {
    bindInit($this->root);

    $this->artisan('package:init', ['--no-interaction' => true, '--defaults' => true])
        ->assertSuccessful();

    completeScaffold($this->root);
    (new Filesystem)->deleteDirectory($this->root.'/tests/Unit');

    bindInit($this->root);

    $this->artisan('package:init', ['--check' => true])
        ->expectsOutputToContain('diverge')
        ->assertFailed();

    $composer = json_decode((string) file_get_contents($this->root.'/composer.json'), true);
    $composer['extra']['extended-testbench']['check-ignore'] = ['tests/Unit'];
    file_put_contents($this->root.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    bindInit($this->root);

    $this->artisan('package:init', ['--check' => true])
        ->expectsOutputToContain('ignored (missing)')
        ->expectsOutputToContain('No drift')
        ->assertSuccessful();
});
