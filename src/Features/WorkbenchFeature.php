<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

use Bambamboole\ExtendedTestbench\Features\Artifacts\WorkbenchApp;

final readonly class WorkbenchFeature implements Feature
{
    public function flag(): Flag
    {
        return new Flag('workbench', 'Add a workbench app?', false, 'Scaffold a workbench app', 'Skip the workbench app');
    }

    /** @return iterable<Artifact> */
    public function artifacts(Context $context): iterable
    {
        yield new WorkbenchApp;
    }
}
