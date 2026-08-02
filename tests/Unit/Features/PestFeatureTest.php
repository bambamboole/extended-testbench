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

    foreach ((new PestFeature)->artifacts($context) as $artifact) {
        iterator_to_array($artifact->apply($context), false);
    }

    expect(file_get_contents($context->path('phpunit.xml.dist')))->not->toContain('name="Browser"')
        ->and($context->composerJson()['scripts']['test'])->toBe('pest');
});

it('declares artifacts in the order the original pest() wrote them', function () {
    $context = makeContext();

    $labels = array_map(
        fn (Artifact $artifact): string => $artifact->label(),
        iterator_to_array((new PestFeature)->artifacts($context), false),
    );

    expect($labels)->toBe([
        'pestphp/pest:^5.0',
        'tests/Unit',
        'tests/Feature',
        'phpunit.xml.dist',
        'tests/TestCase.php',
        'tests/Pest.php',
        'testbench.yaml',
        'composer autoload-dev: Tests\\',
        'composer script: test',
    ]);
});

it('is always on', function () {
    expect((new PestFeature)->flag())->toBeNull();
});

it('adds the workbench block to testbench.yaml only when workbench is enabled', function () {
    $context = makeContext(flags: ['workbench' => true]);

    foreach ((new PestFeature)->artifacts($context) as $artifact) {
        iterator_to_array($artifact->apply($context), false);
    }

    expect(file_get_contents($context->path('testbench.yaml')))->toContain('workbench:');

    $without = makeContext();

    foreach ((new PestFeature)->artifacts($without) as $artifact) {
        iterator_to_array($artifact->apply($without), false);
    }

    expect(file_get_contents($without->path('testbench.yaml')))->not->toContain('workbench:');
});

it('adds the autoload-dev Tests namespace when missing', function () {
    $context = makeContext();

    foreach ((new PestFeature)->artifacts($context) as $artifact) {
        iterator_to_array($artifact->apply($context), false);
    }

    expect($context->composerJson()['autoload-dev']['psr-4']['Tests\\'])->toBe('tests/');
});
