<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\ComposeGuideline;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Status;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

afterEach(function () {
    Prompt::setOutput(new ConsoleOutput);
});

function fetchComposeGuidelineOutput(Context $context): string
{
    /** @var BufferedOutput $output */
    $output = $context->output();

    return $output->fetch();
}

/** A Context whose boost.json already lists the package, as if BoostRegistration had just run. */
function registeredContext(): Context
{
    $context = makeContext();
    file_put_contents($context->path('boost.json'), json_encode(['packages' => ['bambamboole/extended-testbench']]));

    return $context;
}

it('labels itself boost:update', function () {
    expect(new ComposeGuideline('bambamboole/extended-testbench', registeredBeforeRun: false)->label())->toBe('boost:update');
});

it('reports NotCheckable on drift without running anything', function () {
    $result = first(new ComposeGuideline('bambamboole/extended-testbench', registeredBeforeRun: false)->drift(makeContext()));

    expect($result->status)->toBe(Status::NotCheckable);
});

it('yields nothing when the package was already registered before this run started', function () {
    $context = registeredContext();

    $artifact = new ComposeGuideline('bambamboole/extended-testbench', registeredBeforeRun: true);

    expect(iterator_to_array($artifact->apply($context), false))->toBeEmpty();
});

it('yields nothing when the package is not registered even now', function () {
    $context = makeContext();

    $artifact = new ComposeGuideline('bambamboole/extended-testbench', registeredBeforeRun: false);

    expect(iterator_to_array($artifact->apply($context), false))->toBeEmpty();
});

it('notes the manual command and yields nothing when vendor/bin/testbench is missing', function () {
    $context = registeredContext();
    Prompt::setOutput($context->output());

    $artifact = new ComposeGuideline('bambamboole/extended-testbench', registeredBeforeRun: false);

    expect(iterator_to_array($artifact->apply($context), false))->toBeEmpty()
        ->and(fetchComposeGuidelineOutput($context))
        ->toContain('Run vendor/bin/testbench boost:update to compose this guideline into CLAUDE.md / AGENTS.md.');
});

it('runs boost:update --no-interaction, with no --discover, and yields Ran with the composed guideline detail on success', function () {
    $context = registeredContext();
    mkdir($context->path('vendor/bin'), 0755, true);
    file_put_contents($context->path('vendor/bin/testbench'), "<?php\necho implode(' ', array_slice(\$argv, 1));\n");

    $artifact = new ComposeGuideline('bambamboole/extended-testbench', registeredBeforeRun: false);
    $result = first($artifact->apply($context));

    expect($result->label)->toBe('boost:update')
        ->and($result->status)->toBe(Status::Ran)
        ->and($result->describe())->toBe('composed guideline');

    /** @var BufferedOutput $output */
    $output = $context->output();

    expect($output->fetch())->toBe('boost:update --no-interaction');
});

it('yields Failed and notes the manual command when boost:update exits unsuccessfully', function () {
    $context = registeredContext();
    Prompt::setOutput($context->output());
    mkdir($context->path('vendor/bin'), 0755, true);
    file_put_contents($context->path('vendor/bin/testbench'), "<?php\nexit(1);\n");

    $artifact = new ComposeGuideline('bambamboole/extended-testbench', registeredBeforeRun: false);
    $result = first($artifact->apply($context));

    expect($result->label)->toBe('boost:update')
        ->and($result->status)->toBe(Status::Failed)
        ->and(fetchComposeGuidelineOutput($context))
        ->toContain('Run vendor/bin/testbench boost:update to compose this guideline into CLAUDE.md / AGENTS.md.');
});
