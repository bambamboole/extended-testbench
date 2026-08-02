<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\BoostFeature;

/**
 * @param  iterable<Artifact>  $artifacts
 * @return array<int, string>
 */
function boostFeatureLabels(iterable $artifacts): array
{
    return array_map(fn (Artifact $artifact): string => $artifact->label(), iterator_to_array($artifacts, false));
}

it('is always on', function () {
    expect((new BoostFeature)->flag())->toBeNull();
});

it('declares boost:install, boost.json and boost:update in order when boost.json does not exist yet', function () {
    expect(boostFeatureLabels((new BoostFeature)->artifacts(makeContext())))->toBe([
        'boost:install',
        'boost.json',
        'boost:update',
    ]);
});

it('declares boost:update --discover as the run label once boost.json already exists', function () {
    $context = makeContext();
    file_put_contents($context->path('boost.json'), json_encode(['packages' => []]));

    expect(boostFeatureLabels((new BoostFeature)->artifacts($context)))->toBe([
        'boost:update --discover',
        'boost.json',
        'boost:update',
    ]);
});

it('marks the context as already registered when boost.json lists the package before this run', function () {
    $context = makeContext();
    file_put_contents($context->path('boost.json'), json_encode(['packages' => ['bambamboole/extended-testbench']]));

    iterator_to_array((new BoostFeature)->artifacts($context), false);

    expect($context->boostRegisteredBeforeRun())->toBeTrue();
});

it('marks the context as not yet registered when boost.json is missing or lacks the package', function () {
    $withoutFile = makeContext();
    iterator_to_array((new BoostFeature)->artifacts($withoutFile), false);
    expect($withoutFile->boostRegisteredBeforeRun())->toBeFalse();

    $withOtherPackages = makeContext();
    file_put_contents($withOtherPackages->path('boost.json'), json_encode(['packages' => ['acme/other']]));
    iterator_to_array((new BoostFeature)->artifacts($withOtherPackages), false);
    expect($withOtherPackages->boostRegisteredBeforeRun())->toBeFalse();
});

it('runs boost:install, registers the package and composes the guideline end to end on a fresh package', function () {
    $context = makeContext();
    mkdir($context->path('vendor/bin'), 0755, true);

    // A fake vendor/bin/testbench: `boost:install` creates boost.json (as the real command would),
    // `boost:update` (compose) just needs to succeed.
    file_put_contents($context->path('vendor/bin/testbench'), <<<'PHP'
        <?php
        if ($argv[1] === 'boost:install') {
            file_put_contents(__DIR__.'/../../boost.json', json_encode(['packages' => []]));
        }
        PHP);

    foreach ((new BoostFeature)->artifacts($context) as $artifact) {
        iterator_to_array($artifact->apply($context), false);
    }

    $boost = json_decode((string) file_get_contents($context->path('boost.json')), true);

    expect($boost['packages'])->toBe(['bambamboole/extended-testbench'])
        ->and($context->boostRegisteredBeforeRun())->toBeFalse();
});

it('does not recompose the guideline when the package was already registered', function () {
    $context = makeContext();
    mkdir($context->path('vendor/bin'), 0755, true);
    file_put_contents($context->path('boost.json'), json_encode(['packages' => ['bambamboole/extended-testbench']]));

    // BoostRun still unconditionally invokes `boost:update --discover` (boost.json already
    // exists), so the marker can't just key off the subcommand name — it has to tell that call
    // apart from ComposeGuideline's own `boost:update --no-interaction` (no --discover) by args.
    file_put_contents($context->path('vendor/bin/testbench'), <<<'PHP'
        <?php
        if (($argv[1] ?? null) === 'boost:update' && ! in_array('--discover', $argv, true)) {
            file_put_contents(__DIR__.'/../../compose-ran.marker', '1');
        }
        PHP);

    foreach ((new BoostFeature)->artifacts($context) as $artifact) {
        iterator_to_array($artifact->apply($context), false);
    }

    expect($context->boostRegisteredBeforeRun())->toBeTrue()
        ->and(file_exists($context->path('compose-ran.marker')))->toBeFalse();
});
