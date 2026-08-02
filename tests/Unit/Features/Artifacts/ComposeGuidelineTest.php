<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\ComposeGuideline;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Status;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

const BOOST_GUIDELINE_PACKAGE = 'bambamboole/extended-testbench';

afterEach(function () {
    Prompt::setOutput(new ConsoleOutput);
});

function fetchComposeGuidelineOutput(Context $context): string
{
    /** @var BufferedOutput $output */
    $output = $context->output();

    return $output->fetch();
}

/** A Context whose boost.json already lists $package, as if BoostRegistration had just run. */
function registeredContext(bool $registeredBeforeRun): Context
{
    $context = makeContext();
    file_put_contents($context->path('boost.json'), json_encode(['packages' => [BOOST_GUIDELINE_PACKAGE]]));
    $context->markBoostRegisteredBeforeRun($registeredBeforeRun);

    return $context;
}

it('labels itself boost:update', function () {
    expect(new ComposeGuideline(BOOST_GUIDELINE_PACKAGE)->label())->toBe('boost:update');
});

it('reports NotCheckable on drift without running anything', function () {
    expect(first(new ComposeGuideline(BOOST_GUIDELINE_PACKAGE)->drift(makeContext()))->status)->toBe(Status::NotCheckable);
});

it('yields nothing when the package was already registered before this run started', function () {
    $context = registeredContext(registeredBeforeRun: true);

    expect(iterator_to_array(new ComposeGuideline(BOOST_GUIDELINE_PACKAGE)->apply($context), false))->toBeEmpty();
});

it('yields nothing when the package is not registered even now', function () {
    $context = makeContext();
    $context->markBoostRegisteredBeforeRun(false);

    expect(iterator_to_array(new ComposeGuideline(BOOST_GUIDELINE_PACKAGE)->apply($context), false))->toBeEmpty();
});

it('notes the manual command and yields nothing when vendor/bin/testbench is missing', function () {
    $context = registeredContext(registeredBeforeRun: false);
    Prompt::setOutput($context->output());

    expect(iterator_to_array(new ComposeGuideline(BOOST_GUIDELINE_PACKAGE)->apply($context), false))->toBeEmpty()
        ->and(fetchComposeGuidelineOutput($context))
        ->toContain('Run vendor/bin/testbench boost:update to compose this guideline into CLAUDE.md / AGENTS.md.');
});

it('runs boost:update --no-interaction and yields Ran with the composed guideline detail on success', function () {
    $context = registeredContext(registeredBeforeRun: false);
    mkdir($context->path('vendor/bin'), 0755, true);
    file_put_contents($context->path('vendor/bin/testbench'), "<?php\necho 'composed';\n");

    $result = first(new ComposeGuideline(BOOST_GUIDELINE_PACKAGE)->apply($context));

    expect($result->label)->toBe('boost:update')
        ->and($result->status)->toBe(Status::Ran)
        ->and($result->describe())->toBe('composed guideline');
});

it('yields Failed and notes the manual command when boost:update exits unsuccessfully', function () {
    $context = registeredContext(registeredBeforeRun: false);
    Prompt::setOutput($context->output());
    mkdir($context->path('vendor/bin'), 0755, true);
    file_put_contents($context->path('vendor/bin/testbench'), "<?php\nexit(1);\n");

    $result = first(new ComposeGuideline(BOOST_GUIDELINE_PACKAGE)->apply($context));

    expect($result->label)->toBe('boost:update')
        ->and($result->status)->toBe(Status::Failed)
        ->and(fetchComposeGuidelineOutput($context))
        ->toContain('Run vendor/bin/testbench boost:update to compose this guideline into CLAUDE.md / AGENTS.md.');
});
