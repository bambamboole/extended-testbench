<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

use Bambamboole\ExtendedTestbench\Features\Artifacts\GitignoreEntries;

final readonly class GitignoreFeature implements Feature
{
    /** @var array<int, string> */
    private const array GITIGNORE_ENTRIES = [
        '/vendor/',
        '/composer.lock',
        '/.phpunit.cache/',
        '/CLAUDE.md',
        '/AGENTS.md',
        '/.mcp.json',
        '/.claude/skills/',
        '/.agents/',
        '/.junie/',
        '/.codex/',
        '/.superpowers/',
        '/docs/superpowers/',
    ];

    public function flag(): ?Flag
    {
        return null;
    }

    /** @return iterable<Artifact> */
    public function artifacts(Context $context): iterable
    {
        yield new GitignoreEntries(...self::GITIGNORE_ENTRIES);
    }
}
