<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

final readonly class Flag
{
    public function __construct(
        public string $name,
        public string $question,
        public bool $default,
        /** The `--name` help text InitCommand composes its signature from. */
        public string $description,
        /** The `--no-name` help text. */
        public string $skipDescription,
    ) {}
}
