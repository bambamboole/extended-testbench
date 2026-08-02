<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\WorkbenchApp;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Status;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

afterEach(function () {
    Prompt::setOutput(new ConsoleOutput);
});

function fetchWorkbenchOutput(Context $context): string
{
    /** @var BufferedOutput $output */
    $output = $context->output();

    return $output->fetch();
}

it('labels itself workbench/app', function () {
    expect(new WorkbenchApp()->label())->toBe('workbench/app');
});

it('reports drift by whether workbench/app exists, regardless of vendor/bin/testbench', function () {
    $context = makeContext();

    expect(first(new WorkbenchApp()->drift($context))->status)->toBe(Status::Missing);

    mkdir($context->path('workbench/app'), 0755, true);

    expect(first(new WorkbenchApp()->drift($context))->status)->toBe(Status::Ok);
});

it('skips workbench:devtool and notes the manual command when vendor/bin/testbench is missing', function () {
    $context = makeContext();
    Prompt::setOutput($context->output());

    $result = first(new WorkbenchApp()->apply($context));

    expect($result->label)->toBe('workbench:devtool')
        ->and($result->status)->toBe(Status::Skipped)
        ->and($result->describe())->toBe('skipped (no vendor/bin/testbench)')
        ->and(fetchWorkbenchOutput($context))
        ->toContain('Run vendor/bin/testbench workbench:devtool to finish the workbench setup.');
});

it('still notes the missing binary when the consumer only pulls the first result, like first() does', function () {
    // Regression guard for the recurring generator-laziness bug (ArtisanShim/PhpunitConfig already
    // hit this): the note has to fire before anything is yielded, not after, or a first()-only
    // consumer would never reach it.
    $context = makeContext();
    Prompt::setOutput($context->output());

    first(new WorkbenchApp()->apply($context));

    expect(fetchWorkbenchOutput($context))
        ->toContain('Run vendor/bin/testbench workbench:devtool to finish the workbench setup.');
});

it('runs workbench:devtool and yields Ran when it succeeds', function () {
    $context = makeContext();
    mkdir($context->path('vendor/bin'), 0755, true);
    file_put_contents($context->path('vendor/bin/testbench'), "<?php\necho 'devtool output';\n");

    $result = first(new WorkbenchApp()->apply($context));

    expect($result->label)->toBe('workbench:devtool')
        ->and($result->status)->toBe(Status::Ran)
        ->and(fetchWorkbenchOutput($context))->toBe('devtool output');
});

it('yields Failed when workbench:devtool exits unsuccessfully', function () {
    $context = makeContext();
    mkdir($context->path('vendor/bin'), 0755, true);
    file_put_contents($context->path('vendor/bin/testbench'), "<?php\nexit(1);\n");

    $result = first(new WorkbenchApp()->apply($context));

    expect($result->label)->toBe('workbench:devtool')
        ->and($result->status)->toBe(Status::Failed);
});
