<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\BoostFeature;

it('is always on', function () {
    expect((new BoostFeature)->flag())->toBeNull();
});

it('declares boost:install, boost.json and boost:update in order when boost.json does not exist yet', function () {
    expect(labels((new BoostFeature)->artifacts(makeContext())))->toBe([
        'boost:install',
        'boost.json',
        'boost:update',
    ]);
});

it('declares boost:update --discover as the run label once boost.json already exists', function () {
    $context = makeContext();
    file_put_contents($context->path('boost.json'), json_encode(['packages' => []]));

    expect(labels((new BoostFeature)->artifacts($context)))->toBe([
        'boost:update --discover',
        'boost.json',
        'boost:update',
    ]);
});

it('runs boost:install, registers the package and composes the guideline end to end on a fresh package', function () {
    $context = makeContext();
    mkdir($context->path('vendor/bin'), 0755, true);

    // A fake vendor/bin/testbench: `boost:install` creates boost.json (as the real command would).
    // `boost:update --no-interaction` with no --discover is ComposeGuideline's own compose call —
    // it drops a marker so the test can prove that call actually happened, not just that
    // boost.json ended up with the right content (which would pass even if compose never ran).
    file_put_contents($context->path('vendor/bin/testbench'), <<<'PHP'
        <?php
        if ($argv[1] === 'boost:install') {
            file_put_contents(__DIR__.'/../../boost.json', json_encode(['packages' => []]));
        }
        if ($argv[1] === 'boost:update' && ! in_array('--discover', $argv, true)) {
            file_put_contents(__DIR__.'/../../compose-ran.marker', '1');
        }
        PHP);

    applyAll((new BoostFeature)->artifacts($context), $context);

    $boost = json_decode((string) file_get_contents($context->path('boost.json')), true);

    expect($boost['packages'])->toBe(['bambamboole/extended-testbench'])
        ->and(file_exists($context->path('compose-ran.marker')))->toBeTrue();
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

    applyAll((new BoostFeature)->artifacts($context), $context);

    expect(file_exists($context->path('compose-ran.marker')))->toBeFalse();
});
