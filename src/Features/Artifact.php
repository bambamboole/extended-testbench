<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

interface Artifact
{
    /** The first table column, and the string matched against check-ignore. */
    public function label(): string;

    /** @return iterable<Result> */
    public function drift(Context $context): iterable;

    /** @return iterable<Result> */
    public function apply(Context $context): iterable;
}
