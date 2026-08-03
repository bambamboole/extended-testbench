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

it('runs the given command array verbatim, without assuming any prefix', function () {
    // A genuinely non-PHP argv: a PHP_BINARY-based command would pass even if ProcessStep
    // secretly prepended PHP_BINARY, which proves nothing.
    $context = makeContext();
    $result = first(new ProcessStep('npx playwright install', ['/bin/echo', 'npx playwright install'])->apply($context));

    expect($result->status)->toBe(Status::Ran);

    /** @var BufferedOutput $output */
    $output = $context->output();

    expect($output->fetch())->toBe("npx playwright install\n");
});

it('overrides the default "ran" detail with the given ranDetail on success', function () {
    $context = makeContext();
    $result = first(new ProcessStep('boost:update', [PHP_BINARY, '-r', 'echo "ok";'], ranDetail: 'composed guideline')->apply($context));

    expect($result->status)->toBe(Status::Ran)
        ->and($result->describe())->toBe('composed guideline');
});

it('ignores ranDetail and reports the plain Failed status when the command fails', function () {
    $context = makeContext();
    $result = first(new ProcessStep('boost:update', [PHP_BINARY, '-r', 'exit(1);'], ranDetail: 'composed guideline')->apply($context));

    expect($result->status)->toBe(Status::Failed)
        ->and($result->describe())->toBe('failed');
});

it('ignores ttyCommand and runs the plain command through the callback when no TTY is available', function () {
    // This environment (test runner, CI) has no attached TTY, so Process::isTtySupported() is
    // false here regardless of platform — exercising the "ttyCommand given but unused" branch
    // without needing a real terminal.
    expect(Process::isTtySupported())->toBeFalse();

    $context = makeContext();
    $result = first(new ProcessStep(
        'boost:install',
        [PHP_BINARY, '-r', 'echo "no-tty";'],
        ttyCommand: [PHP_BINARY, '-r', 'echo "tty";'],
    )->apply($context));

    expect($result->status)->toBe(Status::Ran);

    /** @var BufferedOutput $output */
    $output = $context->output();

    expect($output->fetch())->toBe('no-tty');
});

it('uses the ttyCommand without an output callback when tty is requested and supported', function () {
    if (! Process::isTtySupported()) {
        $this->markTestSkipped('No TTY support in this environment.');
    }

    $context = makeContext();
    $result = first(new ProcessStep(
        'boost:install',
        [PHP_BINARY, '-r', 'echo "no-tty";'],
        ttyCommand: [PHP_BINARY, '-r', 'echo "tty";'],
    )->apply($context));

    expect($result->status)->toBe(Status::Ran);

    /** @var BufferedOutput $output */
    $output = $context->output();

    // No output callback is wired when running via TTY, so the buffered context output stays empty.
    expect($output->fetch())->toBe('');
});
