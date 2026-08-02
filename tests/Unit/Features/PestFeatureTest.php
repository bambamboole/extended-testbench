<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\PestFeature;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

afterEach(function () {
    Prompt::setOutput(new ConsoleOutput);
});

function fetchPestFeatureOutput(Context $context): string
{
    /** @var BufferedOutput $output */
    $output = $context->output();

    return $output->fetch();
}

function applyAll(PestFeature $feature, Context $context): void
{
    foreach ($feature->artifacts($context) as $artifact) {
        iterator_to_array($artifact->apply($context), false);
    }
}

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

    applyAll(new PestFeature, $context);

    expect(file_get_contents($context->path('phpunit.xml.dist')))->not->toContain('name="Browser"')
        ->and($context->composerJson()['scripts']['test'])->toBe('pest');
});

it('declares artifacts in the order the original pest() wrote them', function () {
    $context = makeContext();

    $labels = array_map(
        fn (Artifact $artifact): string => $artifact->label(),
        iterator_to_array((new PestFeature)->artifacts($context), false),
    );

    // AutoloadEntry sits right before tests/TestCase.php: the original testNamespace() pushed its
    // row (when unsatisfied) the moment it was first called, which happened while building
    // tests/TestCase.php's replacements — before tests/Pest.php's and testbench.yaml's replacements
    // trigger the (now cached) second and third calls.
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

    applyAll(new PestFeature, $context);

    expect(file_get_contents($context->path('testbench.yaml')))
        ->toContain('workbench:')
        ->toContain("\nproviders:\n  - Acme\\Demo\\DemoServiceProvider\n");

    $without = makeContext();

    applyAll(new PestFeature, $without);

    expect(file_get_contents($without->path('testbench.yaml')))
        ->not->toContain('workbench:')
        ->not->toContain('providers:');
});

it('adds the autoload-dev Tests namespace when missing', function () {
    $context = makeContext();

    applyAll(new PestFeature, $context);

    expect($context->composerJson()['autoload-dev']['psr-4']['Tests\\'])->toBe('tests/');
});

it('never overwrites tests/TestCase.php once it exists', function () {
    $context = makeContext();
    mkdir($context->path('tests'), 0755, true);
    file_put_contents($context->path('tests/TestCase.php'), "<?php\n// hand-written\n");

    applyAll(new PestFeature, $context);

    expect(file_get_contents($context->path('tests/TestCase.php')))->toBe("<?php\n// hand-written\n");
});

it('warns that a legacy phpunit.xml shadows the generated phpunit.xml.dist', function () {
    $context = makeContext(force: true);
    Prompt::setOutput($context->output());
    file_put_contents($context->path('phpunit.xml'), '<phpunit/>');

    applyAll(new PestFeature, $context);

    expect(fetchPestFeatureOutput($context))
        ->toContain('phpunit.xml already exists and takes precedence over phpunit.xml.dist');
});
