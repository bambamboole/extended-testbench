<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\PestFeature;

it('adds the Browser testsuite and narrows the test script when browser is enabled', function () {
    $context = makeContext(flags: ['browser' => true]);
    $artifacts = iterator_to_array((new PestFeature)->artifacts($context), false);
    $labels = array_map(fn (Artifact $a): string => $a->label(), $artifacts);

    expect($labels)->toContain('phpunit.xml.dist')
        ->and($labels)->toContain('composer script: test');

    foreach ($artifacts as $artifact) {
        iterator_to_array($artifact->apply($context), false);
    }

    expect(file_get_contents($context->path('phpunit.xml.dist')))->toContain('name="Browser"')
        ->and($context->composerJson()['scripts']['test'])->toBe('pest --testsuite=Unit,Feature');
});

it('leaves the Browser testsuite out when browser is disabled', function () {
    $context = makeContext();

    applyAll((new PestFeature)->artifacts($context), $context);

    expect(file_get_contents($context->path('phpunit.xml.dist')))->not->toContain('name="Browser"')
        ->and($context->composerJson()['scripts']['test'])->toBe('pest');
});

it('declares artifacts in row order', function () {
    $context = makeContext();

    $labels = array_map(
        fn (Artifact $artifact): string => $artifact->label(),
        iterator_to_array((new PestFeature)->artifacts($context), false),
    );

    // AutoloadEntry sits right before tests/TestCase.php, whose replacements are the first to
    // call testNamespace() — that call is what registers the entry when it is missing.
    expect($labels)->toBe([
        'pestphp/pest:^5.0',
        'tests/Unit',
        'tests/Feature',
        'phpunit.xml.dist',
        'composer autoload-dev: Tests\\',
        'tests/TestCase.php',
        'tests/Pest.php',
        'testbench.yaml',
        'composer script: test',
    ]);
});

it('is always on', function () {
    expect((new PestFeature)->flag())->toBeNull();
});

it('adds the workbench block and a correctly framed providers list to testbench.yaml', function () {
    $context = makeContext(['extra' => ['laravel' => ['providers' => ['Acme\\Demo\\DemoServiceProvider']]]], flags: ['workbench' => true]);

    applyAll((new PestFeature)->artifacts($context), $context);

    expect(file_get_contents($context->path('testbench.yaml')))
        ->toContain('workbench:')
        ->toContain("\nproviders:\n  - Acme\\Demo\\DemoServiceProvider\n");

    $without = makeContext();

    applyAll((new PestFeature)->artifacts($without), $without);

    expect(file_get_contents($without->path('testbench.yaml')))
        ->not->toContain('workbench:')
        ->not->toContain('providers:');
});

it('adds the autoload-dev Tests namespace when missing', function () {
    $context = makeContext();

    applyAll((new PestFeature)->artifacts($context), $context);

    expect($context->composerJson()['autoload-dev']['psr-4']['Tests\\'])->toBe('tests/');
});

it('never overwrites tests/TestCase.php once it exists', function () {
    $context = makeContext();
    mkdir($context->path('tests'), 0755, true);
    file_put_contents($context->path('tests/TestCase.php'), "<?php\n// hand-written\n");

    applyAll((new PestFeature)->artifacts($context), $context);

    expect(file_get_contents($context->path('tests/TestCase.php')))->toBe("<?php\n// hand-written\n");
});

it('warns that a legacy phpunit.xml shadows the generated phpunit.xml.dist', function () {
    $context = makeContext(force: true);
    file_put_contents($context->path('phpunit.xml'), '<phpunit/>');

    applyAll((new PestFeature)->artifacts($context), $context);

    expect(fetchOutput($context))
        ->toContain('phpunit.xml already exists and takes precedence over phpunit.xml.dist');
});
