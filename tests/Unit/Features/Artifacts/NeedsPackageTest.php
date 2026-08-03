<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\NeedsPackage;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Status;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Mockery\Expectation;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;

it('yields nothing for a constraint already satisfied', function () {
    $context = makeContext(['require-dev' => ['pestphp/pest' => '^5.0']], installs: true);
    mkdir($context->path('vendor/pestphp/pest'), 0755, true);
    $artifact = new NeedsPackage('pestphp/pest:^5.0');

    expect(iterator_to_array($artifact->drift($context)))->toBe([])
        ->and(iterator_to_array($artifact->apply($context)))->toBe([]);
});

it('reports every missing constraint as missing, labelled by the constraint itself', function () {
    $context = makeContext(installs: true);
    $artifact = new NeedsPackage('pestphp/pest:^5.0', 'pestphp/pest-plugin-laravel:^5.0');

    $results = iterator_to_array($artifact->drift($context));

    expect($results)->toHaveCount(2)
        ->and($results[0]->label)->toBe('pestphp/pest:^5.0')
        ->and($results[0]->status)->toBe(Status::Missing)
        ->and($results[1]->label)->toBe('pestphp/pest-plugin-laravel:^5.0')
        ->and($results[1]->status)->toBe(Status::Missing);
});

it('treats a constraint a failed install left in composer.json as still missing', function () {
    // `composer require` can write the constraint and then die (an unallowed plugin, a network
    // failure): composer.json claims the package while vendor/ holds nothing. Trusting the entry
    // made that state unrecoverable — reruns retried nothing and --check reported no drift.
    $context = makeContext(['require-dev' => ['pestphp/pest' => '^5.0']], installs: true);
    $artifact = new NeedsPackage('pestphp/pest:^5.0');

    $results = iterator_to_array($artifact->drift($context));

    expect($results)->toHaveCount(1)
        ->and($results[0]->status)->toBe(Status::Missing);
});

it('labels itself by the first constraint', function () {
    expect(new NeedsPackage('pestphp/pest:^5.0', 'pestphp/pest-plugin-laravel:^5.0')->label())
        ->toBe('pestphp/pest:^5.0');
});

it('mixes satisfied and missing constraints, yielding a row only for the missing one', function () {
    $context = makeContext(['require-dev' => ['pestphp/pest' => '^5.0']], installs: true);
    mkdir($context->path('vendor/pestphp/pest'), 0755, true);
    $artifact = new NeedsPackage('pestphp/pest:^5.0', 'pestphp/pest-plugin-laravel:^5.0');

    $results = iterator_to_array($artifact->drift($context));

    expect($results)->toHaveCount(1)
        ->and($results[0]->label)->toBe('pestphp/pest-plugin-laravel:^5.0');
});

it('batches every missing constraint into a single requirePackages call and yields nothing on success', function () {
    $root = sys_get_temp_dir().'/etb-needs-'.bin2hex(random_bytes(4));
    mkdir($root, 0755, true);
    file_put_contents($root.'/composer.json', json_encode(['name' => 'acme/demo'], JSON_PRETTY_PRINT));

    /** @var Composer&MockInterface $composer */
    $composer = Mockery::mock(Composer::class.'[requirePackages]', [new Filesystem, $root]);
    $output = new BufferedOutput;

    // shouldReceive()'s declared return type is the narrower ExpectationInterface, which is
    // missing once()/with(); both exist on the concrete Expectation it returns for a named method.
    /** @var Expectation $expectation */
    $expectation = $composer->shouldReceive('requirePackages');
    $expectation->once()
        ->with(['pestphp/pest:^5.0', 'pestphp/pest-plugin-laravel:^5.0'], true, $output)
        ->andReturn(true);

    $context = new Context(
        root: $root,
        composer: $composer,
        output: $output,
        checking: false,
        force: false,
        canPrompt: false,
    );
    $artifact = new NeedsPackage('pestphp/pest:^5.0', 'pestphp/pest-plugin-laravel:^5.0');

    expect(iterator_to_array($artifact->apply($context)))->toBe([])
        ->and($context->failedInstalls())->toBe([]);
});

it('yields a Failed result per missing constraint when the single requirePackages call fails', function () {
    $context = makeContext(installs: false);
    $artifact = new NeedsPackage('pestphp/pest:^5.0', 'pestphp/pest-plugin-laravel:^5.0');

    $results = iterator_to_array($artifact->apply($context));

    expect($results)->toHaveCount(2)
        ->and($results[0]->label)->toBe('pestphp/pest:^5.0')
        ->and($results[0]->status)->toBe(Status::Failed)
        ->and($results[1]->label)->toBe('pestphp/pest-plugin-laravel:^5.0')
        ->and($results[1]->status)->toBe(Status::Failed);
});

/** InitCommand reads these back for its `Failed to install: ...` message and its exit code. */
it('records a failed install onto the Context in declaration order', function () {
    $context = makeContext(installs: false);
    $artifact = new NeedsPackage('pestphp/pest:^5.0', 'pestphp/pest-plugin-laravel:^5.0');

    iterator_to_array($artifact->apply($context));

    expect($context->failedInstalls())->toBe(['pestphp/pest:^5.0', 'pestphp/pest-plugin-laravel:^5.0']);
});
