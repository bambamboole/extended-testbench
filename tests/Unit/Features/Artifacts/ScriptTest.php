<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\Script;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Status;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

afterEach(function () {
    // Prompt::$output is a process-wide static; reset it to the real console so a redirect below
    // never leaks into whichever test runs next.
    Prompt::setOutput(new ConsoleOutput);
});

/** makeContext() always hands back a BufferedOutput; narrows the OutputInterface for fetch(). */
function fetchOutput(Context $context): string
{
    /** @var BufferedOutput $output */
    $output = $context->output();

    return $output->fetch();
}

it('reports a missing script and adds it on apply', function () {
    $context = makeContext();
    $artifact = new Script('lint', 'pint --format agent');

    expect(first($artifact->drift($context))->status)->toBe(Status::Missing)
        ->and(first($artifact->apply($context))->describe())->toBe('added')
        ->and($context->composerJson()['scripts']['lint'])->toBe('pint --format agent');
});

it('reports a script wired to a different command as differing', function () {
    $context = makeContext(['scripts' => ['check' => ['echo "mine"']]]);

    expect(first(new Script('check', ['pint --test', '@test'])->drift($context))->status)
        ->toBe(Status::Differs);
});

it('reports a script already wired to the same command as ok and touches nothing on apply', function () {
    $context = makeContext(['scripts' => ['lint' => 'pint --format agent']]);
    $artifact = new Script('lint', 'pint --format agent');

    expect(first($artifact->drift($context))->status)->toBe(Status::Ok)
        ->and(iterator_to_array($artifact->apply($context)))->toBe([]);
});

/**
 * Context::warn() delegates to Laravel Prompts' warning(), which writes to Prompt's own static
 * output rather than the injected BufferedOutput — so fetch()ing $context->output() only captures
 * it once Prompt::setOutput() has been pointed at that same buffer, as done here. Verified this is
 * necessary (and sufficient) by hand before relying on it; Context::warn() itself is untouched.
 */
it('warns when an existing script runs the same tool under another name', function () {
    $context = makeContext(['scripts' => ['analyse' => './vendor/bin/phpstan analyse']]);

    Prompt::setOutput($context->output());

    iterator_to_array(new Script('stan', 'phpstan analyse')->apply($context));

    expect(fetchOutput($context))->toContain("composer script 'analyse' already runs phpstan");
});

it('adds its own script alongside a same-tool collision instead of skipping it', function () {
    $context = makeContext(['scripts' => ['analyse' => './vendor/bin/phpstan analyse']]);

    iterator_to_array(new Script('stan', 'phpstan analyse')->apply($context));

    expect($context->composerJson()['scripts'])
        ->toHaveKey('analyse', './vendor/bin/phpstan analyse')
        ->toHaveKey('stan', 'phpstan analyse');
});

it('never warns about a collision when the new command is an array', function () {
    $context = makeContext(['scripts' => ['analyse' => './vendor/bin/phpstan analyse']]);

    Prompt::setOutput($context->output());

    iterator_to_array(new Script('stan', ['phpstan analyse', '@test'])->apply($context));

    expect(fetchOutput($context))->toBe('');
});
