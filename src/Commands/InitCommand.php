<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Composer;

final class InitCommand extends Command
{
    protected $signature = 'package:init';

    protected $description = 'Scaffold Pest, static analysis and formatting for this package';

    public function __construct(
        private readonly Composer $composer,
        private readonly string $root,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
