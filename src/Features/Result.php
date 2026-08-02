<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

final readonly class Result
{
    public function __construct(
        public string $label,
        public Status $status,
        /** Overrides the status label so a row keeps its exact wording, e.g. `skipped (exists)`. */
        public ?string $detail = null,
    ) {}

    public function describe(): string
    {
        return $this->detail ?? $this->status->label();
    }
}
