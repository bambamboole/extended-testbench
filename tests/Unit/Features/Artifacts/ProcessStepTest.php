<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\ProcessStep;
use Bambamboole\ExtendedTestbench\Features\Status;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

it('labels itself by the given label', function () {
    expect(new ProcessStep('workbench:devtool', ['true'])->label())->toBe('workbench:devtool');
});

it('reports NotCheckable on drift without running anything', function () {
    $context = makeContext();
    $result = first(new ProcessStep('workbench:devtool', ['/bin/does-not-exist'])->drift($context));

    expect($result->label)->toBe('workbench:devtool')
        ->and($result->status)->toBe(Status::NotCheckable);
});

it('runs the given command and yields Ran on success, streaming output through the context', function () {
    $context = makeContext();
    $result = first(new ProcessStep('echo', [PHP_BINARY, '-r', 'echo "hello";'])->apply($context));

    expect($result->status)->toBe(Status::Ran);

    /** @var BufferedOutput $output */
    $output = $context->output();

    expect($output->fetch())->toBe('hello');
});

it('yields Failed when the command exits unsuccessfully', function () {
    $context = makeContext();
    $result = first(new ProcessStep('fail', [PHP_BINARY, '-r', 'exit(1);'])->apply($context));

    expect($result->status)->toBe(Status::Failed);
});

it('runs the full command array verbatim, without assuming any prefix', function () {
    // Proves the "caller decides the full command" contract from the brief: a non-testbench
    // command (like playwright's `npx playwright install`) works exactly like a testbench one —
    // ProcessStep never prepends PHP_BINARY or vendor/bin/testbench itself.
    $context = makeContext();
    $result = first(new ProcessStep('npx playwright install', [PHP_BINARY, '-r', 'echo "npx playwright install";'])->apply($context));

    expect($result->status)->toBe(Status::Ran);

    /** @var BufferedOutput $output */
    $output = $context->output();

    expect($output->fetch())->toBe('npx playwright install');
});

it('uses a tty without an output callback when tty is requested and supported', function () {
    if (! Process::isTtySupported()) {
        $this->markTestSkipped('No TTY support in this environment.');
    }

    $context = makeContext();
    $result = first(new ProcessStep('boost:install', [PHP_BINARY, '-r', 'echo "tty";'], tty: true)->apply($context));

    expect($result->status)->toBe(Status::Ran);

    /** @var BufferedOutput $output */
    $output = $context->output();

    // No output callback is wired when running via TTY, so the buffered context output stays empty.
    expect($output->fetch())->toBe('');
});
