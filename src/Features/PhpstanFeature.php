<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

use Bambamboole\ExtendedTestbench\Features\Artifacts\NeedsPackage;
use Bambamboole\ExtendedTestbench\Features\Artifacts\Script;
use Bambamboole\ExtendedTestbench\Features\Artifacts\StubFile;

final readonly class PhpstanFeature implements Feature
{
    public function flag(): Flag
    {
        return new Flag('phpstan', 'Add PHPStan (Larastan)?', true, 'Add PHPStan (Larastan)', 'Skip PHPStan');
    }

    /** @return iterable<Artifact> */
    public function artifacts(Context $context): iterable
    {
        yield new NeedsPackage('larastan/larastan:^3.0', 'pestphp/pest-plugin-phpstan:^5.0');

        yield new StubFile('phpstan.neon.dist', 'phpstan.neon.dist.stub', [
            'level' => $context->phpstanLevel(),
            // From the real directories, not the flags: they may exist from an earlier run.
            'workbench_path' => $context->hasWorkbench() ? "\n        - workbench/app" : '',
            'database_path' => $context->hasDatabase() ? "\n        - database" : '',
        ], shadowedBy: 'phpstan.neon');

        yield new Script('stan', 'phpstan analyse');
    }
}
