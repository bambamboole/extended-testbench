<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench;

use Bambamboole\ExtendedTestbench\Commands\InitCommand;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Composer;
use Illuminate\Support\ServiceProvider;

use function Orchestra\Testbench\package_path;

class ExtendedTestbenchServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        if ($this->app->runningInConsole() && function_exists('Orchestra\Testbench\package_path')) {
            $this->app->singleton(InitCommand::class, fn (): InitCommand => new InitCommand(
                new Composer(new Filesystem, package_path()),
                package_path(),
            ));

            $this->commands([InitCommand::class]);
        }

        if (! $this->shouldActivate()) {
            return;
        }

        $app = $this->app;
        assert($app instanceof Application);

        PackageRootRebase::apply($app, package_path());
        $this->ensureArtisanEntrypoint();

        if (config('cache.default') === 'database') {
            config(['cache.default' => 'array']);
        }
    }

    /**
     * @param  list<string>  $argv
     */
    public static function isBoostCommand(array $argv): bool
    {
        $command = '';

        foreach (array_slice($argv, 1) as $token) {
            if (! str_starts_with($token, '-')) {
                $command = $token;

                break;
            }
        }

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

        // A dangling symlink is left over from the versions of this package that symlinked the
        // entrypoint; file_exists() reports false for it, so replace it rather than skipping.
        if (is_link($artisan) && ! file_exists($artisan)) {
            @unlink($artisan);
        }

        if (file_exists($artisan)) {
            return;
        }

        $stub = (string) file_get_contents(__DIR__.'/../stubs/artisan.stub');

        if (@file_put_contents($artisan, $stub) === false) {
            fwrite(STDERR, "extended-testbench: could not write {$artisan}; create it manually with: require __DIR__.'/vendor/bin/testbench';".PHP_EOL);
        }
    }
}
