<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\TestDirectory;
use Bambamboole\ExtendedTestbench\Features\Status;

it('labels itself by the directory path', function () {
    expect(new TestDirectory('tests/Unit')->label())->toBe('tests/Unit');
});

it('reports missing under check when the directory does not exist', function () {
    $context = makeContext();

    expect(first(new TestDirectory('tests/Unit')->drift($context))->status)->toBe(Status::Missing);
});

it('reports ok under check when the directory exists', function () {
    $context = makeContext();
    mkdir($context->path('tests/Unit'), 0755, true);

    expect(first(new TestDirectory('tests/Unit')->drift($context))->status)->toBe(Status::Ok);
});

it('creates the directory and writes a .gitkeep, labelled by the gitkeep path', function () {
    $context = makeContext();
    $result = first(new TestDirectory('tests/Unit')->apply($context));

    expect($context->path('tests/Unit'))->toBeDirectory()
        ->and($context->path('tests/Unit/.gitkeep'))->toBeFile()
        ->and($result->label)->toBe('tests/Unit/.gitkeep')
        ->and($result->status)->toBe(Status::Written);
});

it('reports skipped labelled by the directory path when the gitkeep already exists', function () {
    $context = makeContext();
    mkdir($context->path('tests/Unit'), 0755, true);
    file_put_contents($context->path('tests/Unit/.gitkeep'), '');

    $result = first(new TestDirectory('tests/Unit')->apply($context));

    expect($result->label)->toBe('tests/Unit')
        ->and($result->status)->toBe(Status::Skipped)
        ->and($result->describe())->toBe('skipped (exists)');
});

// The two Failed branches (mkdir() failing, then file_put_contents() failing on the .gitkeep) are
// not exercised here: simulating a real filesystem failure warns noisily and unreliably across
// platforms. Same gap, same reasoning as StubFile's Failed paths (Task 3 ledger); both close when
// Task 11 wires this into the Feature-covered command.
