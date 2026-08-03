<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\ComposerScriptsFeature;

it('composes the check script from the enabled tools only', function () {
    $context = makeContext(flags: ['pint' => true, 'rector' => true]);

    applyAll((new ComposerScriptsFeature)->artifacts($context), $context);

    expect($context->composerJson()['scripts']['check'])
        ->toBe(['pint --test', 'rector --dry-run', '@test']);
});

it('is always on', function () {
    expect((new ComposerScriptsFeature)->flag())->toBeNull();
});

it('adds phpstan analyse to check only when phpstan is enabled', function () {
    $context = makeContext(flags: ['phpstan' => true]);

    applyAll((new ComposerScriptsFeature)->artifacts($context), $context);

    expect($context->composerJson()['scripts']['check'])->toBe(['phpstan analyse', '@test']);
});

it('falls back to just @test when no tool is enabled', function () {
    $context = makeContext();

    applyAll((new ComposerScriptsFeature)->artifacts($context), $context);

    expect($context->composerJson()['scripts']['check'])->toBe(['@test']);
});

it('declares the remaining boost/autoload scripts byte-for-byte, in order', function () {
    $context = makeContext();

    applyAll((new ComposerScriptsFeature)->artifacts($context), $context);

    $scripts = $context->composerJson()['scripts'];

    expect($scripts['post-autoload-dump'])->toBe([
        '@php vendor/bin/testbench package:purge-skeleton --ansi',
        '@php vendor/bin/testbench package:discover --ansi',
    ])
        ->and($scripts['boost:refresh'])->toBe('[ -n "$CI" ] || [ ! -f vendor/bin/testbench ] || [ ! -f boost.json ] || vendor/bin/testbench boost:update --no-interaction || true')
        ->and($scripts['post-install-cmd'])->toBe(['@boost:refresh'])
        ->and($scripts['post-update-cmd'])->toBe(['@boost:refresh']);
});

it('declares the five scripts in order', function () {
    expect(labels((new ComposerScriptsFeature)->artifacts(makeContext())))->toBe([
        'composer script: check',
        'composer script: post-autoload-dump',
        'composer script: boost:refresh',
        'composer script: post-install-cmd',
        'composer script: post-update-cmd',
    ]);
});
