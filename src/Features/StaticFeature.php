<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

/** A feature whose artifacts do not depend on the Context. */
final readonly class StaticFeature implements Feature
{
    /** @var array<int, Artifact> */
    private array $artifacts;

    public function __construct(
        private ?Flag $flag,
        Artifact ...$artifacts,
    ) {
        $this->artifacts = $artifacts;
    }

    public function flag(): ?Flag
    {
        return $this->flag;
    }

    /** @return array<int, Artifact> */
    public function artifacts(Context $context): iterable
    {
        return $this->artifacts;
    }
}
