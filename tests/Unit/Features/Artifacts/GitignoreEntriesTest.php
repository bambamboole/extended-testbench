<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\GitignoreEntries;
use Bambamboole\ExtendedTestbench\Features\Status;

it('reports missing when the gitignore does not exist yet', function () {
    $context = makeContext();

    expect(first(new GitignoreEntries('/vendor/')->drift($context))->status)->toBe(Status::Missing);
});

it('treats an entry as present when an ancestor is already ignored', function () {
    $context = makeContext();
    file_put_contents($context->path('.gitignore'), ".claude\n");

    expect(first(new GitignoreEntries('/.claude/skills/')->drift($context))->status)->toBe(Status::Ok);
});

it('matches regardless of leading or trailing slashes', function () {
    $context = makeContext();
    file_put_contents($context->path('.gitignore'), "vendor\n");

    expect(first(new GitignoreEntries('/vendor/')->drift($context))->status)->toBe(Status::Ok);
});

it('lists what it would append', function () {
    $context = makeContext();
    file_put_contents($context->path('.gitignore'), "/vendor/\n");

    expect(first(new GitignoreEntries('/vendor/', '/.codex/')->drift($context))->describe())
        ->toBe('missing 1 entries: /.codex/');
});

it('writes a fresh gitignore when none existed', function () {
    $context = makeContext();
    $result = first(new GitignoreEntries('/vendor/', '/.codex/')->apply($context));

    expect($result->status)->toBe(Status::Written)
        ->and(file_get_contents($context->path('.gitignore')))->toBe("/vendor/\n/.codex/\n");
});

it('appends to an existing gitignore and reports it as updated', function () {
    $context = makeContext();
    file_put_contents($context->path('.gitignore'), "/vendor/\n");

    $result = first(new GitignoreEntries('/vendor/', '/.codex/')->apply($context));

    expect($result->status)->toBe(Status::Overwritten)
        ->and($result->describe())->toBe('updated')
        ->and(file_get_contents($context->path('.gitignore')))->toBe("/vendor/\n/.codex/\n");
});

it('skips writing when nothing is missing', function () {
    $context = makeContext();
    file_put_contents($context->path('.gitignore'), "/vendor/\n");

    $result = first(new GitignoreEntries('/vendor/')->apply($context));

    expect($result->status)->toBe(Status::Skipped)
        ->and($result->describe())->toBe('skipped (nothing to add)')
        ->and(file_get_contents($context->path('.gitignore')))->toBe("/vendor/\n");
});

it('labels itself .gitignore', function () {
    expect(new GitignoreEntries('/vendor/')->label())->toBe('.gitignore');
});
