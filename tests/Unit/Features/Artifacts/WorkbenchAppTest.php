<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\WorkbenchApp;
use Bambamboole\ExtendedTestbench\Features\Status;

it('labels itself workbench/app', function () {
    expect(new WorkbenchApp()->label())->toBe('workbench/app');
});

it('reports drift by whether workbench/app exists, regardless of vendor/bin/testbench', function () {
    $context = makeContext();

    expect(first(new WorkbenchApp()->drift($context))->status)->toBe(Status::Missing);

    mkdir($context->path('workbench/app'), 0755, true);

    expect(first(new WorkbenchApp()->drift($context))->status)->toBe(Status::Ok);
});

it('skips workbench:devtool entirely when workbench/app already exists', function () {
    $context = makeContext();
    mkdir($context->path('workbench/app'), 0755, true);
    mkdir($context->path('vendor/bin'), 0755, true);
    file_put_contents(
        $context->path('vendor/bin/testbench'),
        "<?php file_put_contents(__DIR__.'/../../devtool-ran.marker', '1');\n",
    );

    $result = first(new WorkbenchApp()->apply($context));

    expect($result->label)->toBe('workbench/app')
        ->and($result->status)->toBe(Status::Skipped)
        ->and($result->describe())->toBe('skipped (exists)')
        ->and(file_exists($context->path('devtool-ran.marker')))->toBeFalse();
});

it('skips workbench:devtool and notes the manual command when vendor/bin/testbench is missing', function () {
    $context = makeContext();

    $result = first(new WorkbenchApp()->apply($context));

    expect($result->label)->toBe('workbench:devtool')
        ->and($result->status)->toBe(Status::Skipped)
        ->and($result->describe())->toBe('skipped (no vendor/bin/testbench)')
        ->and(fetchOutput($context))
        ->toContain('Run vendor/bin/testbench workbench:devtool to finish the workbench setup.');
});

it('runs workbench:devtool and yields Ran when it succeeds', function () {
    $context = makeContext();
    mkdir($context->path('vendor/bin'), 0755, true);
    file_put_contents($context->path('vendor/bin/testbench'), "<?php\necho 'devtool output';\n");

    $result = first(new WorkbenchApp()->apply($context));

    expect($result->label)->toBe('workbench:devtool')
        ->and($result->status)->toBe(Status::Ran)
        ->and(fetchOutput($context))->toBe('devtool output');
});

it('yields Failed when workbench:devtool exits unsuccessfully', function () {
    $context = makeContext();
    mkdir($context->path('vendor/bin'), 0755, true);
    file_put_contents($context->path('vendor/bin/testbench'), "<?php\nexit(1);\n");

    $result = first(new WorkbenchApp()->apply($context));

    expect($result->label)->toBe('workbench:devtool')
        ->and($result->status)->toBe(Status::Failed);
});
