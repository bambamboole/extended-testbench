<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;

/**
 * A composer.json config.allow-plugins entry. Has to be applied before the first
 * `composer require` that pulls the plugin in: a non-interactive composer refuses an unlisted
 * plugin outright, which kills the whole batched install. Silent once allowed, like AutoloadEntry.
 */
final readonly class AllowedPlugin implements Artifact
{
    public function __construct(private string $plugin) {}

    public function label(): string
    {
        return "composer allow-plugins: {$this->plugin}";
    }

    /** @return iterable<Result> */
    public function drift(Context $context): iterable
    {
        if ($this->allowed($context)) {
            return [];
        }

        return [new Result($this->label(), Status::Missing)];
    }

    /** @return iterable<Result> */
    public function apply(Context $context): iterable
    {
        if ($this->allowed($context)) {
            return [];
        }

        $context->composer()->modify(function (array $composer): array {
            $composer['config']['allow-plugins'][$this->plugin] = true;

            return $composer;
        });

        return [new Result($this->label(), Status::Written, 'allowed')];
    }

    private function allowed(Context $context): bool
    {
        return ($context->composerJson()['config']['allow-plugins'][$this->plugin] ?? false) === true;
    }
}
