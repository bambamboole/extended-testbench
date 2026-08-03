<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\GitignoreEntries;
use Bambamboole\ExtendedTestbench\Features\Artifacts\StubFile;
use Bambamboole\ExtendedTestbench\Features\Flag;
use Bambamboole\ExtendedTestbench\Features\StaticFeature;

it('hands back the artifacts it was given, in order', function () {
    $feature = new StaticFeature(
        null,
        new StubFile('.gitattributes', 'gitattributes.stub'),
        new StubFile('.github/workflows/ci.yml', 'ci.yml.stub'),
    );

    expect(labels($feature->artifacts(makeContext())))
        ->toBe(['.gitattributes', '.github/workflows/ci.yml']);
});

it('is always on when constructed without a flag', function () {
    expect(new StaticFeature(null, GitignoreEntries::defaults())->flag())->toBeNull();
});

it('carries the flag it was given', function () {
    $flag = new Flag('demo', 'Add demo?', true, 'Add demo', 'Skip demo');

    expect(new StaticFeature($flag)->flag())->toBe($flag);
});

it('defaults the gitignore entries to the agent scratch trees and build output', function () {
    $context = makeContext();

    applyAll([GitignoreEntries::defaults()], $context);

    expect(file_get_contents($context->path('.gitignore')))
        ->toContain('/vendor/')
        ->toContain('/.claude/skills/')
        ->toContain('/docs/superpowers/');
});
