<?php

declare(strict_types=1);

namespace Bambamboole\BoostTestbench;

use Illuminate\Support\ServiceProvider;

class BoostTestbenchServiceProvider extends ServiceProvider
{
    public function register(): void {}

    /**
     * @param  list<string>  $argv
     */
    public static function isBoostCommand(array $argv): bool
    {
        $command = $argv[1] ?? '';

        return str_starts_with($command, 'boost:') || str_starts_with($command, 'mcp:');
    }
}
