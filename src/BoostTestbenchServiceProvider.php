<?php

declare(strict_types=1);

namespace Bambamboole\BoostTestbench;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

use function Orchestra\Testbench\package_path;

class BoostTestbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->shouldActivate()) {
            return;
        }

        $app = $this->app;
        assert($app instanceof Application);

        PackageRootRebase::apply($app, package_path());
        $this->ensureArtisanEntrypoint();

        config(['cache.default' => 'array']);
    }

    /**
     * @param  list<string>  $argv
     */
    public static function isBoostCommand(array $argv): bool
    {
        $command = $argv[1] ?? '';

        return str_starts_with($command, 'boost:') || str_starts_with($command, 'mcp:');
    }

    private function shouldActivate(): bool
    {
        return defined('TESTBENCH_CORE')
            && ! $this->app->runningUnitTests()
            && function_exists('Orchestra\Testbench\package_path')
            && self::isBoostCommand($_SERVER['argv'] ?? []);
    }

    private function ensureArtisanEntrypoint(): void
    {
        $artisan = package_path('artisan');

        if (file_exists($artisan) || is_link($artisan)) {
            return;
        }

        if (! @symlink('vendor'.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'testbench', $artisan)) {
            fwrite(STDERR, 'boost-testbench: could not create the artisan symlink; run: ln -s vendor/bin/testbench artisan'.PHP_EOL);
        }
    }
}
