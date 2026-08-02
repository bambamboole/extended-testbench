<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

interface Feature
{
    /** Null for always-on features that take no flag. */
    public function flag(): ?Flag;

    /** @return iterable<Artifact> */
    public function artifacts(Context $context): iterable;
}
