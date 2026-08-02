<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;

/**
 * Registers this package in boost.json's packages key. Registration has to follow Boost's own
 * install/update run — boost:install is what creates boost.json in the first place, and Boost
 * composes the guidelines during that same run, before our name is in the packages key. Without a
 * second pass the guideline would be registered and never composed.
 */
final readonly class BoostRegistration implements Artifact
{
    public function __construct(private string $package) {}

    public function label(): string
    {
        return 'boost.json';
    }

    /** @return iterable<Result> */
    public function drift(Context $context): iterable
    {
        $path = $context->path('boost.json');

        if (! file_exists($path)) {
            yield new Result($this->label(), Status::Missing);

            return;
        }

        $config = json_decode((string) @file_get_contents($path), true);

        if (! is_array($config)) {
            yield new Result($this->label(), Status::Failed, 'unreadable');

            return;
        }

        yield new Result('boost.json: packages', $this->registered($config) ? Status::Ok : Status::Missing);
    }

    /**
     * Yields nothing when boost.json is missing (nothing to register yet) or already registered —
     * the original returned false without pushing a row in both cases. The `Written` vs no-yield
     * split is what lets a later feature decide whether the guidelines need composing again: only a
     * newly-added registration returns true from the original registerGuideline().
     *
     * @return iterable<Result>
     */
    public function apply(Context $context): iterable
    {
        $path = $context->path('boost.json');

        if (! file_exists($path)) {
            return;
        }

        $config = json_decode((string) @file_get_contents($path), true);

        if (! is_array($config)) {
            yield new Result($this->label(), Status::Failed, 'failed (unreadable)');

            return;
        }

        if ($this->registered($config)) {
            return;
        }

        $config['packages'] = [...$this->packages($config), $this->package];

        ksort($config);

        if (@file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL) === false) {
            yield new Result($this->label(), Status::Failed);

            return;
        }

        yield new Result($this->label(), Status::Written, 'registered guideline');
    }

    /** @param  array<string, mixed>  $config */
    private function registered(array $config): bool
    {
        return in_array($this->package, $this->packages($config), true);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, mixed>
     */
    private function packages(array $config): array
    {
        return is_array($config['packages'] ?? null) ? $config['packages'] : [];
    }
}
