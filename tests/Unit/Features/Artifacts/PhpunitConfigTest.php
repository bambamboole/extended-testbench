<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\PhpunitConfig;
use Bambamboole\ExtendedTestbench\Features\Status;

it('labels itself phpunit.xml.dist', function () {
    expect(new PhpunitConfig(false)->label())->toBe('phpunit.xml.dist');
});

it('writes the Browser testsuite and does not warn when browser is enabled on a fresh file', function () {
    $context = makeContext();

    $result = first(new PhpunitConfig(true)->apply($context));

    expect($result->status)->toBe(Status::Written)
        ->and(file_get_contents($context->path('phpunit.xml.dist')))->toContain('name="Browser"')
        ->and(fetchOutput($context))->toBe('');
});

it('writes without the Browser testsuite and never warns when browser is disabled', function () {
    $context = makeContext();

    first(new PhpunitConfig(false)->apply($context));

    expect(file_get_contents($context->path('phpunit.xml.dist')))->not->toContain('name="Browser"')
        ->and(fetchOutput($context))->toBe('');
});

it('warns when an existing file is kept on a declined overwrite and lacks the Browser testsuite', function () {
    $context = makeContext();
    file_put_contents($context->path('phpunit.xml.dist'), 'ORIGINAL, no browser suite');

    // makeContext() hands back canPrompt: false, so the overwrite is skipped without --force.
    $result = first(new PhpunitConfig(true)->apply($context));

    expect($result->status)->toBe(Status::Skipped)
        ->and(fetchOutput($context))
        ->toContain('phpunit.xml.dist does not include the Browser testsuite — add it by hand.');
});

it('does not warn once --force overwrites the kept file with the Browser testsuite', function () {
    $context = makeContext(force: true);
    file_put_contents($context->path('phpunit.xml.dist'), 'ORIGINAL, no browser suite');

    $result = first(new PhpunitConfig(true)->apply($context));

    expect($result->status)->toBe(Status::Overwritten)
        ->and(fetchOutput($context))->toBe('');
});

it('warns under drift/--check when an existing file lacks the Browser testsuite', function () {
    // Exercised via a pre-existing file rather than an absent one: reading a path that does not
    // exist raises an unsuppressable warning, and the guard is identical for both cases.
    $context = makeContext();
    file_put_contents($context->path('phpunit.xml.dist'), 'ORIGINAL, no browser suite');

    $result = first(new PhpunitConfig(true)->drift($context));

    expect($result->status)->toBe(Status::Differs)
        ->and(fetchOutput($context))
        ->toContain('phpunit.xml.dist does not include the Browser testsuite — add it by hand.');
});

it('does not warn under drift when browser is disabled', function () {
    $context = makeContext();

    first(new PhpunitConfig(false)->drift($context));

    expect(fetchOutput($context))->toBe('');
});

it('still fires the shadow warning from the wrapped StubFile', function () {
    $context = makeContext(force: true);
    file_put_contents($context->path('phpunit.xml'), '<phpunit/>');

    $result = first(new PhpunitConfig(false)->apply($context));

    expect($result->status)->toBe(Status::Written)
        ->and($result->describe())->toBe('written (shadowed by phpunit.xml)')
        ->and(fetchOutput($context))
        ->toContain('phpunit.xml already exists and takes precedence over phpunit.xml.dist');
});
