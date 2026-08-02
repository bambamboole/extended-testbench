<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

use Bambamboole\ExtendedTestbench\Features\Artifacts\Script;

final readonly class ComposerScriptsFeature implements Feature
{
    public function flag(): ?Flag
    {
        return null;
    }

    /** @return iterable<Artifact> */
    public function artifacts(Context $context): iterable
    {
        yield new Script('check', array_values(array_filter([
            $context->enabled('pint') ? 'pint --test' : null,
            $context->enabled('phpstan') ? 'phpstan analyse' : null,
            $context->enabled('rector') ? 'rector --dry-run' : null,
            '@test',
        ])));

        yield new Script('post-autoload-dump', [
            '@php vendor/bin/testbench package:purge-skeleton --ansi',
            '@php vendor/bin/testbench package:discover --ansi',
        ]);

        yield new Script('boost:refresh', '[ -n "$CI" ] || [ ! -f vendor/bin/testbench ] || [ ! -f boost.json ] || vendor/bin/testbench boost:update --no-interaction || true');
        yield new Script('post-install-cmd', ['@boost:refresh']);
        yield new Script('post-update-cmd', ['@boost:refresh']);
    }
}
