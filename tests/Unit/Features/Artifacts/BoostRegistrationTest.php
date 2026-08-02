<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\BoostRegistration;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Status;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Symfony\Component\Console\Output\BufferedOutput;

/** A Context in checking mode — makeContext() always builds a non-checking one. */
function boostCheckingContext(): Context
{
    $root = sys_get_temp_dir().'/etb-boost-'.bin2hex(random_bytes(4));
    mkdir($root, 0755, true);
    file_put_contents($root.'/composer.json', json_encode(['name' => 'acme/demo'], JSON_PRETTY_PRINT));

    return new Context(
        root: $root,
        composer: new Composer(new Filesystem, $root),
        output: new BufferedOutput,
        checking: true,
        force: false,
        canPrompt: false,
    );
}

it('labels itself boost.json', function () {
    expect(new BoostRegistration('bambamboole/extended-testbench')->label())->toBe('boost.json');
});

it('reports missing under check when boost.json does not exist, and yields nothing on apply', function () {
    $artifact = new BoostRegistration('bambamboole/extended-testbench');

    expect(first($artifact->drift(boostCheckingContext()))->status)->toBe(Status::Missing)
        ->and(iterator_to_array($artifact->apply(makeContext()), false))->toBeEmpty();
});

it('reports unreadable json under check with detail "unreadable"', function () {
    $context = boostCheckingContext();
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
    $registered = boostCheckingContext();
    file_put_contents($registered->path('boost.json'), json_encode(['packages' => ['bambamboole/extended-testbench']]));

    $notRegistered = boostCheckingContext();
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

// The Failed write branch (file_put_contents() failing) is not exercised here: simulating a real
// filesystem failure warns noisily and unreliably across platforms. Same gap, same reasoning as
// StubFile's Failed paths (Task 3 ledger); closes when a later task wires this into the command.
