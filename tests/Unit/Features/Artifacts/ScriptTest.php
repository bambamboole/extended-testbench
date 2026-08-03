<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\Script;
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
it('reports a missing script and adds it on apply', function () {
    $context = makeContext();
    $artifact = new Script('lint', 'pint --format agent');

    expect(first($artifact->drift($context))->status)->toBe(Status::Missing)
        ->and(first($artifact->apply($context))->describe())->toBe('added')
        ->and($context->composerJson()['scripts']['lint'])->toBe('pint --format agent');
});

it('treats an array script that contains every scaffold entry as ok', function () {
    // A package prepending its own hook installer still runs what the scaffold wired up.
    $context = makeContext(['scripts' => ['post-install-cmd' => ['@hooks:install', '@boost:refresh']]]);

    expect(first(new Script('post-install-cmd', ['@boost:refresh'])->drift($context))->status)
        ->toBe(Status::Ok);
});

it('still reports an array script missing a scaffold entry as differing', function () {
    $context = makeContext(['scripts' => ['check' => ['pint --test', '@test']]]);

    expect(first(new Script('check', ['pint --test', 'phpstan analyse', '@test'])->drift($context))->status)
        ->toBe(Status::Differs);
});

it('never accepts a superset match for string scripts', function () {
    $context = makeContext(['scripts' => ['test' => 'pest --coverage']]);

    expect(first(new Script('test', 'pest')->drift($context))->status)->toBe(Status::Differs);
});

it('prints the current and scaffolded command when a script differs', function () {
    $context = makeContext(['scripts' => ['test' => 'phpunit']]);

    first(new Script('test', 'pest')->drift($context));

    expect(fetchOutput($context))
        ->toContain("composer script 'test' differs from the scaffold:")
        ->toContain('-phpunit')
        ->toContain('+pest');
});

it('prints array commands as json when a script differs', function () {
    $context = makeContext(['scripts' => ['check' => ['echo "mine"']]]);

    first(new Script('check', ['pint --test', '@test'])->drift($context));

    expect(fetchOutput($context))
        ->toContain('-["echo \\"mine\\""]')
        ->toContain('+["pint --test","@test"]');
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

it('warns when an existing script runs the same tool under another name', function () {
    $context = makeContext(['scripts' => ['analyse' => './vendor/bin/phpstan analyse']]);

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

    iterator_to_array(new Script('stan', ['phpstan analyse', '@test'])->apply($context));

    expect(fetchOutput($context))->toBe('');
});
