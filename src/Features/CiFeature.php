<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

use Bambamboole\ExtendedTestbench\Features\Artifacts\StubFile;

final readonly class CiFeature implements Feature
{
    public function flag(): ?Flag
    {
        return null;
    }

    /** @return iterable<Artifact> */
    public function artifacts(Context $context): iterable
    {
        yield new StubFile('.github/workflows/ci.yml', 'ci.yml.stub', onlyIfMissing: true);
    }
}
