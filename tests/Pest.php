<?php

declare(strict_types=1);
use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Laravel\Prompts\Prompt;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

afterEach(function () {
    // Prompt::$output is a process-wide static; reset it so makeContext()'s redirect never leaks
    // into whichever test runs next.
    Prompt::setOutput(new ConsoleOutput);
});

/**
 * A Context rooted at a fresh temp package. Laravel Prompts writes to its own static output rather
 * than the injected one, so both are pointed at the same buffer here — without that, every
 * warn()/note() assertion would silently pass against an empty string.
 *
 * $installs swaps the real Composer for a double stubbing only the shelling-out requirePackages(),
 * so a test can dictate whether the install "succeeds" without running `composer require`.
 *
 * @param  array<string, mixed>  $composerJson
 * @param  array<string, bool>  $flags
 */
function makeContext(
    array $composerJson = [],
    array $flags = [],
    bool $force = false,
    bool $checking = false,
    ?bool $installs = null,
    string $phpstanLevel = '6',
): Context {
    $root = sys_get_temp_dir().'/etb-ctx-'.bin2hex(random_bytes(4));
    mkdir($root, 0755, true);
    file_put_contents($root.'/composer.json', json_encode($composerJson + ['name' => 'acme/demo'], JSON_PRETTY_PRINT));

    $output = new BufferedOutput;
    Prompt::setOutput($output);

    return new Context(
        root: $root,
        composer: $installs === null ? new Composer(new Filesystem, $root) : mockComposer($root, $installs),
        output: $output,
        checking: $checking,
        force: $force,
        canPrompt: false,
        enabled: $flags,
        phpstanLevel: $phpstanLevel,
    );
}

function mockComposer(string $root, bool $installs): Composer
{
    /** @var Composer&MockInterface $composer */
    $composer = Mockery::mock(Composer::class.'[requirePackages]', [new Filesystem, $root]);
    $composer->shouldReceive('requirePackages')->andReturn($installs);

    return $composer;
}

/** makeContext() always hands back a BufferedOutput; narrows the OutputInterface for fetch(). */
function fetchOutput(Context $context): string
{
    /** @var BufferedOutput $output */
    $output = $context->output();

    return $output->fetch();
}

/**
 * @param  iterable<Artifact>  $artifacts
 * @return array<int, string>
 */
function labels(iterable $artifacts): array
{
    return array_map(fn (Artifact $artifact): string => $artifact->label(), [...$artifacts]);
}

/**
 * apply() may be a generator, so calling it without iterating executes none of its body.
 *
 * @param  iterable<Artifact>  $artifacts
 */
function applyAll(iterable $artifacts, Context $context): void
{
    foreach ($artifacts as $artifact) {
        iterator_to_array($artifact->apply($context), false);
    }
}

/** @param iterable<Result> $results */
function first(iterable $results): Result
{
    foreach ($results as $result) {
        return $result;
    }

    throw new RuntimeException('artifact yielded no result');
}
