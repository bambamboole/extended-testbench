<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;

/**
 * The composer.json autoload-dev.psr-4 entry the test namespace resolves to. A no-op once any
 * entry already maps to tests/ — including one under a different namespace than ours, since the
 * point is a working autoloader for tests/, not a specific key.
 */
final readonly class AutoloadEntry implements Artifact
{
    public function __construct(
        private string $namespace,
        private string $path,
    ) {}

    public function label(): string
    {
        return "composer autoload-dev: {$this->namespace}";
    }

    /** @return iterable<Result> */
    public function drift(Context $context): iterable
    {
        if ($this->satisfied($context)) {
            return;
        }

        yield new Result($this->label(), Status::Missing);
    }

    /** @return iterable<Result> */
    public function apply(Context $context): iterable
    {
        if ($this->satisfied($context)) {
            return [];
        }

        $context->composer()->modify(function (array $composer): array {
            $composer['autoload-dev']['psr-4'][$this->namespace] = $this->path;

            return $composer;
        });

        $context->markAutoloadChanged();

        return [];
    }

    private function satisfied(Context $context): bool
    {
        return array_any(
            (array) ($context->composerJson()['autoload-dev']['psr-4'] ?? []),
            fn ($path): bool => rtrim((string) $path, '/') === 'tests',
        );
    }
}
