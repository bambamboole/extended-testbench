<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

use Bambamboole\ExtendedTestbench\Features\Artifacts\NeedsPackage;
use Bambamboole\ExtendedTestbench\Features\Artifacts\Script;
use Bambamboole\ExtendedTestbench\Features\Artifacts\StubFile;

final readonly class PhpstanFeature implements Feature
{
    public function __construct(private string $level) {}

    public function flag(): Flag
    {
        return new Flag('phpstan', 'Add PHPStan (Larastan)?', true, 'Add PHPStan (Larastan)', 'Skip PHPStan');
    }

    /** @return iterable<Artifact> */
    public function artifacts(Context $context): iterable
    {
        yield new NeedsPackage('larastan/larastan:^3.0', 'pestphp/pest-plugin-phpstan:^5.0');

        yield new StubFile('phpstan.neon.dist', 'phpstan.neon.dist.stub', [
            'level' => $this->level,
            // From the real directories, not enabled('workbench')/enabled('database' equivalent) —
            // the directory may already exist from an earlier run even when this run didn't ask for it.
            'workbench_path' => $context->hasWorkbench() ? "\n        - workbench/app" : '',
            'database_path' => $context->hasDatabase() ? "\n        - database" : '',
        ], shadowedBy: 'phpstan.neon');

        yield new Script('stan', 'phpstan analyse');
    }
}
