<?php

declare(strict_types=1);
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * @param  array<string, mixed>  $composerJson
 * @param  array<string, bool>  $flags
 */
function makeContext(array $composerJson = [], array $flags = [], bool $force = false): Context
{
    $root = sys_get_temp_dir().'/etb-ctx-'.bin2hex(random_bytes(4));
    mkdir($root, 0755, true);
    file_put_contents($root.'/composer.json', json_encode($composerJson + ['name' => 'acme/demo'], JSON_PRETTY_PRINT));

    return new Context(
        root: $root,
        composer: new Composer(new Filesystem, $root),
        output: new BufferedOutput,
        checking: false,
        force: $force,
        canPrompt: false,
        enabled: $flags,
    );
}

/** @param iterable<Result> $results */
function first(iterable $results): Result
{
    foreach ($results as $result) {
        return $result;
    }

    throw new RuntimeException('artifact yielded no result');
}
