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

it('yields nothing on either drift or apply when an existing entry already maps to tests/, and touches nothing', function () {
    $context = makeContext(['autoload-dev' => ['psr-4' => ['Acme\\Tests\\' => 'tests/']]]);

    expect(iterator_to_array(new AutoloadEntry('Tests\\', 'tests/')->drift($context), false))->toBeEmpty()
        ->and(iterator_to_array(new AutoloadEntry('Tests\\', 'tests/')->apply($context), false))->toBeEmpty();

    $composerJson = $context->composerJson();
    expect($composerJson['autoload-dev']['psr-4'])->toBe(['Acme\\Tests\\' => 'tests/']);
});

it('adds the autoload-dev entry and marks the autoload changed on apply, yielding nothing', function () {
    $context = makeContext();
    $results = iterator_to_array(new AutoloadEntry('Tests\\', 'tests/')->apply($context), false);

    expect($results)->toBeEmpty()
        ->and($context->autoloadChanged())->toBeTrue()
        ->and($context->composerJson()['autoload-dev']['psr-4']['Tests\\'])->toBe('tests/');
});

it('labels itself by the namespace', function () {
    expect(new AutoloadEntry('Tests\\', 'tests/')->label())->toBe('composer autoload-dev: Tests\\');
});
