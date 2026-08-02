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

/**
 * makeContext() always wires a real Composer, so requirePackages() would shell out to a real
 * `composer require`. This mirrors bindInit() in tests/Feature/InitCommandTest.php: a Composer
 * double that keeps the real hasPackage()/modify() (they only read the temp composer.json) and
 * stubs out just the method that shells out, so a test can dictate whether the install "succeeds".
 *
 * @param  array<string, mixed>  $composerJson
 */
function needsPackageContext(array $composerJson = [], bool $installs = true): Context
{
    $root = sys_get_temp_dir().'/etb-needs-'.bin2hex(random_bytes(4));
    mkdir($root, 0755, true);
    file_put_contents(
        $root.'/composer.json',
        json_encode($composerJson + ['name' => 'acme/demo'], JSON_PRETTY_PRINT),
    );

    /** @var Composer&MockInterface $composer */
    $composer = Mockery::mock(Composer::class.'[requirePackages]', [new Filesystem, $root]);
    $composer->shouldReceive('requirePackages')->andReturn($installs);

    return new Context(
        root: $root,
        composer: $composer,
        output: new BufferedOutput,
        checking: false,
        force: false,
        canPrompt: false,
    );
}

it('yields nothing for a constraint already satisfied', function () {
    $context = needsPackageContext(['require-dev' => ['pestphp/pest' => '^5.0']]);
    $artifact = new NeedsPackage('pestphp/pest:^5.0');

    expect(iterator_to_array($artifact->drift($context)))->toBe([])
        ->and(iterator_to_array($artifact->apply($context)))->toBe([]);
});

it('reports every missing constraint as missing, labelled by the constraint itself', function () {
    $context = needsPackageContext();
    $artifact = new NeedsPackage('pestphp/pest:^5.0', 'pestphp/pest-plugin-laravel:^5.0');

    $results = iterator_to_array($artifact->drift($context));

    expect($results)->toHaveCount(2)
        ->and($results[0]->label)->toBe('pestphp/pest:^5.0')
        ->and($results[0]->status)->toBe(Status::Missing)
        ->and($results[1]->label)->toBe('pestphp/pest-plugin-laravel:^5.0')
        ->and($results[1]->status)->toBe(Status::Missing);
});

it('labels itself by the first constraint', function () {
    expect(new NeedsPackage('pestphp/pest:^5.0', 'pestphp/pest-plugin-laravel:^5.0')->label())
        ->toBe('pestphp/pest:^5.0');
});

it('mixes satisfied and missing constraints, yielding a row only for the missing one', function () {
    $context = needsPackageContext(['require-dev' => ['pestphp/pest' => '^5.0']]);
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

    // The one hard requirement install() must keep: a single `composer require` for every missing
    // constraint, not one process per package. shouldReceive()'s declared return type is the
    // narrower ExpectationInterface, which is missing once()/with() — both exist on the concrete
    // Expectation it actually returns for a named method, same gap InitCommandTest's own
    // `Composer&MockInterface $composer` annotation already works around for this Mockery double.
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
    $context = needsPackageContext(installs: false);
    $artifact = new NeedsPackage('pestphp/pest:^5.0', 'pestphp/pest-plugin-laravel:^5.0');

    $results = iterator_to_array($artifact->apply($context));

    expect($results)->toHaveCount(2)
        ->and($results[0]->label)->toBe('pestphp/pest:^5.0')
        ->and($results[0]->status)->toBe(Status::Failed)
        ->and($results[1]->label)->toBe('pestphp/pest-plugin-laravel:^5.0')
        ->and($results[1]->status)->toBe(Status::Failed);
});

/**
 * InitCommand::$failedInstalls (populated by today's install()) drives the final
 * `error('Failed to install: ...')` message and the command's non-zero exit code. NeedsPackage is
 * its replacement producer, so a failed requirePackages() must record the same constraints, in the
 * same declaration order, onto the Context for Task 11's runner to read back.
 */
it('records a failed install onto the Context in declaration order', function () {
    $context = needsPackageContext(installs: false);
    $artifact = new NeedsPackage('pestphp/pest:^5.0', 'pestphp/pest-plugin-laravel:^5.0');

    iterator_to_array($artifact->apply($context));

    expect($context->failedInstalls())->toBe(['pestphp/pest:^5.0', 'pestphp/pest-plugin-laravel:^5.0']);
});
