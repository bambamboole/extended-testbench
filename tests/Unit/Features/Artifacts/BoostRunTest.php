<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\BoostRun;
use Bambamboole\ExtendedTestbench\Features\Status;
use Symfony\Component\Process\Process;

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

    $result = first(new BoostRun(['boost:install'])->apply($context));

    expect($result->label)->toBe('boost:install')
        ->and($result->status)->toBe(Status::Skipped)
        ->and($result->describe())->toBe('skipped (no vendor/bin/testbench)')
        ->and(fetchOutput($context))
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
        ->and(fetchOutput($context))->toBe('boost:update --discover --no-interaction');
});

it('drops --no-interaction and runs via the tty command when a tty is available', function () {
    if (! Process::isTtySupported()) {
        $this->markTestSkipped('No TTY support in this environment.');
    }

    $context = makeContext();
    mkdir($context->path('vendor/bin'), 0755, true);
    file_put_contents($context->path('vendor/bin/testbench'), "<?php\n");

    $result = first(new BoostRun(['boost:install'])->apply($context));

    expect($result->status)->toBe(Status::Ran)
        // No output callback is wired for a tty run, so the buffered output stays empty.
        ->and(fetchOutput($context))->toBe('');
});

it('yields Failed and notes APP_ENV=local when the run exits unsuccessfully', function () {
    $context = makeContext();
    mkdir($context->path('vendor/bin'), 0755, true);
    file_put_contents($context->path('vendor/bin/testbench'), "<?php\nexit(1);\n");

    $result = first(new BoostRun(['boost:install'])->apply($context));

    expect($result->label)->toBe('boost:install')
        ->and($result->status)->toBe(Status::Failed)
        ->and(fetchOutput($context))
        ->toContain("Boost's commands are only registered in a local environment. Add APP_ENV=local to the env section of testbench.yaml, then run vendor/bin/testbench boost:install yourself.");
});
