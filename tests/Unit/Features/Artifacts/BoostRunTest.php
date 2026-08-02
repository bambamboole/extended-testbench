<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\BoostRun;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Status;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Process\Process;

afterEach(function () {
    Prompt::setOutput(new ConsoleOutput);
});

function fetchBoostRunOutput(Context $context): string
{
    /** @var BufferedOutput $output */
    $output = $context->output();

    return $output->fetch();
}

it('labels itself the given command joined with a space', function () {
    expect(new BoostRun(['boost:install'])->label())->toBe('boost:install')
        ->and(new BoostRun(['boost:update', '--discover'])->label())->toBe('boost:update --discover');
});

it('reports NotCheckable on drift without running anything', function () {
    $context = makeContext();

    $result = first(new BoostRun(['boost:install'])->drift($context));

    expect($result->label)->toBe('boost:install')
        ->and($result->status)->toBe(Status::NotCheckable);
});

it('skips the run and notes the manual command when vendor/bin/testbench is missing', function () {
    $context = makeContext();
    Prompt::setOutput($context->output());

    $result = first(new BoostRun(['boost:install'])->apply($context));

    expect($result->label)->toBe('boost:install')
        ->and($result->status)->toBe(Status::Skipped)
        ->and($result->describe())->toBe('skipped (no vendor/bin/testbench)')
        ->and(fetchBoostRunOutput($context))
        ->toContain('Run vendor/bin/testbench boost:install to compose the guidelines.');
});

it('still notes the missing binary when the consumer only pulls the first result, like first() does', function () {
    $context = makeContext();
    Prompt::setOutput($context->output());

    first(new BoostRun(['boost:install'])->apply($context));

    expect(fetchBoostRunOutput($context))
        ->toContain('Run vendor/bin/testbench boost:install to compose the guidelines.');
});

it('runs the command through vendor/bin/testbench, appending --no-interaction when no TTY is available', function () {
    expect(Process::isTtySupported())->toBeFalse();

    $context = makeContext();
    mkdir($context->path('vendor/bin'), 0755, true);
    file_put_contents($context->path('vendor/bin/testbench'), "<?php\necho implode(' ', array_slice(\$argv, 1));\n");

    $result = first(new BoostRun(['boost:update', '--discover'])->apply($context));

    expect($result->label)->toBe('boost:update --discover')
        ->and($result->status)->toBe(Status::Ran)
        ->and(fetchBoostRunOutput($context))->toBe('boost:update --discover --no-interaction');
});

it('yields Failed and notes APP_ENV=local when the run exits unsuccessfully', function () {
    $context = makeContext();
    Prompt::setOutput($context->output());
    mkdir($context->path('vendor/bin'), 0755, true);
    file_put_contents($context->path('vendor/bin/testbench'), "<?php\nexit(1);\n");

    $result = first(new BoostRun(['boost:install'])->apply($context));

    expect($result->label)->toBe('boost:install')
        ->and($result->status)->toBe(Status::Failed)
        ->and(fetchBoostRunOutput($context))
        ->toContain("Boost's commands are only registered in a local environment. Add APP_ENV=local to the env section of testbench.yaml, then run vendor/bin/testbench boost:install yourself.");
});
