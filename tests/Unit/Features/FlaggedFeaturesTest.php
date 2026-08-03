<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\BrowserFeature;
use Bambamboole\ExtendedTestbench\Features\Feature;
use Bambamboole\ExtendedTestbench\Features\PhpstanFeature;
use Bambamboole\ExtendedTestbench\Features\PintFeature;
use Bambamboole\ExtendedTestbench\Features\PlaywrightFeature;
use Bambamboole\ExtendedTestbench\Features\RectorFeature;
use Bambamboole\ExtendedTestbench\Features\WorkbenchFeature;
use Symfony\Component\Console\Output\BufferedOutput;

it('writes the requested phpstan level', function () {
    $context = makeContext(phpstanLevel: '8');

    foreach ((new PhpstanFeature)->artifacts($context) as $artifact) {
        applyAll([$artifact], $context);
    }

    expect(file_get_contents($context->path('phpstan.neon.dist')))->toContain('level: 8');
});

it('declares its flag with the right default', function () {
    expect((new PintFeature)->flag()->name)->toBe('pint')
        ->and((new PintFeature)->flag()->default)->toBeTrue();
});

it('declares each flag with the exact name, question and default', function (Feature $feature, string $name, string $question, bool $default) {
    $flag = $feature->flag();

    expect($flag)->not->toBeNull()
        ->and($flag->name)->toBe($name)
        ->and($flag->question)->toBe($question)
        ->and($flag->default)->toBe($default);
})->with([
    'workbench' => [new WorkbenchFeature, 'workbench', 'Add a workbench app?', false],
    'browser' => [new BrowserFeature, 'browser', 'Add browser tests?', false],
    'playwright' => [new PlaywrightFeature, 'playwright', 'Install Playwright browsers now?', false],
    'phpstan' => [new PhpstanFeature, 'phpstan', 'Add PHPStan (Larastan)?', true],
    'rector' => [new RectorFeature, 'rector', 'Add Rector?', true],
    'pint' => [new PintFeature, 'pint', 'Add Pint?', true],
]);

it('declares a single workbench/app artifact', function () {
    expect(labels((new WorkbenchFeature)->artifacts(makeContext())))->toBe(['workbench/app']);
});

it('declares the browser artifacts in row order', function () {
    // Pinned here because browser is declined in all four expectsPromptsTable assertions in
    // InitCommandTest.php, so nothing else guards this order.
    expect(labels((new BrowserFeature)->artifacts(makeContext())))->toBe([
        'pestphp/pest-plugin-browser:^5.0',
        'tests/BrowserTestCase.php',
        'tests/Browser/DummyTest.php',
        'composer script: test:browser',
        'tests/Pest.php',
    ]);
});

it('declares a single npx playwright install artifact', function () {
    expect(labels((new PlaywrightFeature)->artifacts(makeContext())))->toBe(['npx playwright install']);
});

it('declares the phpstan artifacts in row order', function () {
    expect(labels((new PhpstanFeature)->artifacts(makeContext())))->toBe([
        'larastan/larastan:^3.0',
        'phpstan.neon.dist',
        'composer script: stan',
    ]);
});

it('declares the rector artifacts in row order', function () {
    expect(labels((new RectorFeature)->artifacts(makeContext())))->toBe([
        'rector/rector:^2.0',
        'rector.php',
        'composer script: refactor',
    ]);
});

it('declares the pint artifacts in row order', function () {
    expect(labels((new PintFeature)->artifacts(makeContext())))->toBe([
        'laravel/pint:^1.16',
        'pint.json',
        'composer script: lint',
    ]);
});

it('derives phpstan workbench_path and database_path from the real directories, not the flags', function () {
    $context = makeContext(flags: ['workbench' => false]);
    mkdir($context->path('workbench/app'), 0755, true);
    mkdir($context->path('database'), 0755, true);

    $artifacts = iterator_to_array((new PhpstanFeature)->artifacts($context), false);
    applyAll([$artifacts[1]], $context); // phpstan.neon.dist, skipping the network-touching NeedsPackage

    $contents = (string) file_get_contents($context->path('phpstan.neon.dist'));

    expect($contents)->toContain('- workbench/app')
        ->toContain('- database');
});

it('omits phpstan workbench_path and database_path when the directories do not exist, even if the flags are true', function () {
    $context = makeContext(flags: ['workbench' => true]);

    $artifacts = iterator_to_array((new PhpstanFeature)->artifacts($context), false);
    applyAll([$artifacts[1]], $context);

    $contents = (string) file_get_contents($context->path('phpstan.neon.dist'));

    expect($contents)->not->toContain('workbench/app')
        ->not->toContain('- database');
});

it('warns that a legacy phpstan.neon shadows the generated phpstan.neon.dist', function () {
    $context = makeContext(force: true);
    file_put_contents($context->path('phpstan.neon'), 'includes: []');

    $artifacts = iterator_to_array((new PhpstanFeature)->artifacts($context), false);
    applyAll([$artifacts[1]], $context);

    /** @var BufferedOutput $output */
    $output = $context->output();

    expect($output->fetch())->toContain('phpstan.neon already exists and takes precedence over phpstan.neon.dist');
});

it('derives rector workbench_path from the real directory, not the flag', function () {
    $context = makeContext(flags: ['workbench' => false]);
    mkdir($context->path('workbench/app'), 0755, true);

    $artifacts = iterator_to_array((new RectorFeature)->artifacts($context), false);
    applyAll([$artifacts[1]], $context); // rector.php StubFile

    expect(file_get_contents($context->path('rector.php')))->toContain("__DIR__.'/workbench/app'");
});

it('omits rector workbench_path when the directory does not exist, even if the flag is true', function () {
    $context = makeContext(flags: ['workbench' => true]);

    $artifacts = iterator_to_array((new RectorFeature)->artifacts($context), false);
    applyAll([$artifacts[1]], $context);

    expect(file_get_contents($context->path('rector.php')))->not->toContain('workbench/app');
});

it('runs npm run build before the browser suite when package.json exists', function () {
    $context = makeContext();
    file_put_contents($context->path('package.json'), '{}');

    $artifacts = iterator_to_array((new BrowserFeature)->artifacts($context), false);
    applyAll([$artifacts[3]], $context); // composer script: test:browser

    expect($context->composerJson()['scripts']['test:browser'])->toBe(['npm run build', 'pest --testsuite=Browser']);
});

it('runs pest directly for the browser suite when there is no package.json', function () {
    $context = makeContext();

    $artifacts = iterator_to_array((new BrowserFeature)->artifacts($context), false);
    applyAll([$artifacts[3]], $context);

    expect($context->composerJson()['scripts']['test:browser'])->toBe('pest --testsuite=Browser');
});

it('maps the Browser suite to the BrowserTestCase in the resolved test namespace', function () {
    $context = makeContext();
    mkdir($context->path('tests'), 0755, true);
    file_put_contents(
        $context->path('tests/Pest.php'),
        "<?php\n\ndeclare(strict_types=1);\n\nuses(Tests\\TestCase::class)->in('Feature');\n",
    );

    $artifacts = iterator_to_array((new BrowserFeature)->artifacts($context), false);
    applyAll([$artifacts[4]], $context); // PestSuiteLine

    expect(file_get_contents($context->path('tests/Pest.php')))
        ->toContain("uses(\\Tests\\BrowserTestCase::class)->in('Browser');");
});
