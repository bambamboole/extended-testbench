<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

use Bambamboole\ExtendedTestbench\Features\Artifacts\StubFile;

final readonly class GitFeature implements Feature
{
    public function flag(): ?Flag
    {
        return null;
    }

    /** @return iterable<Artifact> */
    public function artifacts(Context $context): iterable
    {
        yield new StubFile('.gitattributes', 'gitattributes.stub', onlyIfMissing: true);
    }
}
