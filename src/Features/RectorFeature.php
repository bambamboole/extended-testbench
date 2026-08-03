<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

use Bambamboole\ExtendedTestbench\Features\Artifacts\NeedsPackage;
use Bambamboole\ExtendedTestbench\Features\Artifacts\Script;
use Bambamboole\ExtendedTestbench\Features\Artifacts\StubFile;

final readonly class RectorFeature implements Feature
{
    public function flag(): Flag
    {
        return new Flag('rector', 'Add Rector?', true, 'Add Rector', 'Skip Rector');
    }

    /** @return iterable<Artifact> */
    public function artifacts(Context $context): iterable
    {
        yield new NeedsPackage('rector/rector:^2.0');

        yield new StubFile('rector.php', 'rector.php.stub', [
            // From the real directory, not the flag: it may exist from an earlier run.
            'workbench_path' => $context->hasWorkbench() ? ", __DIR__.'/workbench/app'" : '',
        ]);

        yield new Script('refactor', 'rector');
    }
}
