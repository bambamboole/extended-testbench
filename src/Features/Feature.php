<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

interface Feature
{
    public function configure(): void;

    public function check(): Result;

    public function activate(): void;
}
