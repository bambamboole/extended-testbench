<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\AutoloadEntry;
use Bambamboole\ExtendedTestbench\Features\Status;

it('reports missing under check when no psr-4 entry maps to tests/', function () {
    $context = makeContext();
    $result = first(new AutoloadEntry('Tests\\', 'tests/')->drift($context));

    expect($result->status)->toBe(Status::Missing)
        ->and($result->label)->toBe('composer autoload-dev: Tests\\');
});

it('is a no-op reporting ok when an existing entry already maps to tests/', function () {
    $context = makeContext(['autoload-dev' => ['psr-4' => ['Acme\\Tests\\' => 'tests/']]]);

    expect(first(new AutoloadEntry('Tests\\', 'tests/')->drift($context))->status)->toBe(Status::Ok)
        ->and(iterator_to_array(new AutoloadEntry('Tests\\', 'tests/')->apply($context)))->toHaveCount(1);

    $composerJson = $context->composerJson();
    expect($composerJson['autoload-dev']['psr-4'])->toBe(['Acme\\Tests\\' => 'tests/']);
});

it('adds the autoload-dev entry and marks the autoload changed on apply', function () {
    $context = makeContext();
    $result = first(new AutoloadEntry('Tests\\', 'tests/')->apply($context));

    expect($result->status)->toBe(Status::Ok)
        ->and($context->autoloadChanged())->toBeTrue()
        ->and($context->composerJson()['autoload-dev']['psr-4']['Tests\\'])->toBe('tests/');
});

it('labels itself by the namespace', function () {
    expect(new AutoloadEntry('Tests\\', 'tests/')->label())->toBe('composer autoload-dev: Tests\\');
});
