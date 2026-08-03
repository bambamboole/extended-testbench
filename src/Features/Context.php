<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

final class Context
{
    private ?string $testNamespace = null;

    private bool $autoloadChanged = false;

    /** @var array<int, string> */
    private array $failedInstalls = [];

    /** @param array<string, bool> $enabled */
    public function __construct(
        private readonly string $root,
        private readonly Composer $composer,
        private readonly OutputInterface $output,
        private readonly bool $checking,
        private readonly bool $force,
        private readonly bool $canPrompt,
        private readonly array $enabled = [],
        private readonly string $phpstanLevel = '6',
        private readonly Filesystem $files = new Filesystem,
    ) {}

    public function files(): Filesystem
    {
        return $this->files;
    }

    public function phpstanLevel(): string
    {
        return $this->phpstanLevel;
    }

    public function path(string $relative): string
    {
        return $this->root.'/'.$relative;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function composer(): Composer
    {
        return $this->composer;
    }

    public function output(): OutputInterface
    {
        return $this->output;
    }

    public function checking(): bool
    {
        return $this->checking;
    }

    public function force(): bool
    {
        return $this->force;
    }

    public function canPrompt(): bool
    {
        return $this->canPrompt;
    }

    public function enabled(string $flag): bool
    {
        return $this->enabled[$flag] ?? false;
    }

    public function confirm(string $question, bool $default): bool
    {
        return confirm($question, default: $default);
    }

    public function warn(string $message): void
    {
        warning($message);
    }

    public function note(string $message): void
    {
        note($message);
    }

    /** @param array<string, string> $replacements */
    public function render(string $stub, array $replacements): string
    {
        $contents = (string) file_get_contents(__DIR__.'/../../stubs/'.$stub);

        foreach ($replacements as $key => $value) {
            $contents = str_replace('{{ '.$key.' }}', $value, $contents);
        }

        return $contents;
    }

    /** @return array<string, mixed> */
    public function composerJson(): array
    {
        return (array) json_decode((string) file_get_contents($this->path('composer.json')), true);
    }

    /** @return array<int, string> */
    public function providers(): array
    {
        return array_values((array) ($this->composerJson()['extra']['laravel']['providers'] ?? []));
    }

    public function testNamespace(): string
    {
        if ($this->testNamespace !== null) {
            return $this->testNamespace;
        }

        foreach ((array) ($this->composerJson()['autoload-dev']['psr-4'] ?? []) as $namespace => $path) {
            if (rtrim((string) $path, '/') === 'tests') {
                return $this->testNamespace = (string) $namespace;
            }
        }

        return $this->testNamespace = 'Tests\\';
    }

    public function hasWorkbench(): bool
    {
        return is_dir($this->path('workbench/app'));
    }

    public function hasDatabase(): bool
    {
        return is_dir($this->path('database'));
    }

    public function markAutoloadChanged(): void
    {
        $this->autoloadChanged = true;
    }

    public function autoloadChanged(): bool
    {
        return $this->autoloadChanged;
    }

    public function markInstallFailed(string ...$constraints): void
    {
        $this->failedInstalls = [...$this->failedInstalls, ...$constraints];
    }

    /** @return array<int, string> */
    public function failedInstalls(): array
    {
        return $this->failedInstalls;
    }
}
