<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\BoostRegistration;
use Bambamboole\ExtendedTestbench\Features\Status;

it('labels itself boost.json', function () {
    expect(new BoostRegistration('bambamboole/extended-testbench')->label())->toBe('boost.json');
});

it('reports missing under check when boost.json does not exist, and yields nothing on apply', function () {
    $artifact = new BoostRegistration('bambamboole/extended-testbench');

    expect(first($artifact->drift(makeContext(checking: true)))->status)->toBe(Status::Missing)
        ->and(iterator_to_array($artifact->apply(makeContext()), false))->toBeEmpty();
});

it('reports unreadable json under check with detail "unreadable"', function () {
    $context = makeContext(checking: true);
    file_put_contents($context->path('boost.json'), '{not valid json');

    $result = first(new BoostRegistration('bambamboole/extended-testbench')->drift($context));

    expect($result->status)->toBe(Status::Failed)
        ->and($result->describe())->toBe('unreadable');
});

it('reports unreadable json under apply with detail "failed (unreadable)"', function () {
    $context = makeContext();
    file_put_contents($context->path('boost.json'), '{not valid json');

    $result = first(new BoostRegistration('bambamboole/extended-testbench')->apply($context));

    expect($result->status)->toBe(Status::Failed)
        ->and($result->describe())->toBe('failed (unreadable)')
        // Unreadable json is left untouched, not overwritten.
        ->and(file_get_contents($context->path('boost.json')))->toBe('{not valid json');
});

it('reports the packages key under check, ok when registered and missing when not', function () {
    $registered = makeContext(checking: true);
    file_put_contents($registered->path('boost.json'), json_encode(['packages' => ['bambamboole/extended-testbench']]));

    $notRegistered = makeContext(checking: true);
    file_put_contents($notRegistered->path('boost.json'), json_encode(['packages' => ['acme/other']]));

    $artifact = new BoostRegistration('bambamboole/extended-testbench');

    $registeredResult = first($artifact->drift($registered));
    $missingResult = first($artifact->drift($notRegistered));

    expect($registeredResult->label)->toBe('boost.json: packages')
        ->and($registeredResult->status)->toBe(Status::Ok)
        ->and($missingResult->label)->toBe('boost.json: packages')
        ->and($missingResult->status)->toBe(Status::Missing);
});

it('yields nothing on apply when already registered, touching nothing', function () {
    $context = makeContext();
    $original = json_encode(['packages' => ['bambamboole/extended-testbench']]);
    file_put_contents($context->path('boost.json'), $original);

    $artifact = new BoostRegistration('bambamboole/extended-testbench');

    expect(iterator_to_array($artifact->apply($context), false))->toBeEmpty()
        ->and(file_get_contents($context->path('boost.json')))->toBe($original);
});

it('registers the package and keeps boost.json keys sorted on apply', function () {
    $context = makeContext();
    file_put_contents($context->path('boost.json'), json_encode(['guidelines' => true, 'agents' => ['claude']]));

    $result = first(new BoostRegistration('bambamboole/extended-testbench')->apply($context));

    expect($result->status)->toBe(Status::Written)
        ->and($result->describe())->toBe('registered guideline');

    $boost = json_decode((string) file_get_contents($context->path('boost.json')), true);

    expect(array_keys($boost))->toBe(['agents', 'guidelines', 'packages'])
        ->and($boost['packages'])->toBe(['bambamboole/extended-testbench']);
});

it('treats a malformed packages key as empty instead of throwing', function () {
    $context = makeContext();
    file_put_contents($context->path('boost.json'), json_encode(['guidelines' => true, 'packages' => 'foo']));

    first(new BoostRegistration('bambamboole/extended-testbench')->apply($context));

    $boost = json_decode((string) file_get_contents($context->path('boost.json')), true);

    expect($boost['packages'])->toBe(['bambamboole/extended-testbench']);
});

it('does not duplicate an already registered package alongside others', function () {
    $context = makeContext();
    file_put_contents($context->path('boost.json'), json_encode(['packages' => ['bambamboole/extended-testbench', 'acme/other']]));

    expect(iterator_to_array(new BoostRegistration('bambamboole/extended-testbench')->apply($context), false))->toBeEmpty();

    $boost = json_decode((string) file_get_contents($context->path('boost.json')), true);
    expect($boost['packages'])->toBe(['bambamboole/extended-testbench', 'acme/other']);
});
