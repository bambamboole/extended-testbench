<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

final readonly class Flag
{
    public function __construct(
        public string $name,
        public string $question,
        public bool $default,
    ) {}
}
