<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

use Bambamboole\ExtendedTestbench\Features\Artifacts\BoostRegistration;
use Bambamboole\ExtendedTestbench\Features\Artifacts\BoostRun;
use Bambamboole\ExtendedTestbench\Features\Artifacts\ComposeGuideline;

final readonly class BoostFeature implements Feature
{
    private const string PACKAGE = 'bambamboole/extended-testbench';

    public function flag(): ?Flag
    {
        return null;
    }

    /** @return iterable<Artifact> */
    public function artifacts(Context $context): iterable
    {
        yield new BoostRun($this->boostCommand($context));

        // Registration has to follow the boost run, because boost:install is what creates
        // boost.json in the first place — but Boost composes the guidelines during that same run,
        // before our name is in the packages key. Snapshotting whether we were already registered
        // here, right before BoostRegistration can change that (this resumes only after BoostRun
        // has been fully applied, same as before), is what lets ComposeGuideline tell "just added"
        // apart from "was already there" without this Feature being able to branch on
        // BoostRegistration's own result. A plain local variable, not Context state: nothing else
        // needs it, and Context has no business carrying a field only two artifacts care about.
        $registeredBefore = $this->registered($context);

        yield new BoostRegistration(self::PACKAGE);

        yield new ComposeGuideline(self::PACKAGE, $registeredBefore);
    }

    /** @return array<int, string> */
    private function boostCommand(Context $context): array
    {
        return file_exists($context->path('boost.json'))
            ? ['boost:update', '--discover']
            : ['boost:install'];
    }

    private function registered(Context $context): bool
    {
        $path = $context->path('boost.json');

        if (! file_exists($path)) {
            return false;
        }

        $config = json_decode((string) @file_get_contents($path), true);
        $packages = is_array($config) && is_array($config['packages'] ?? null) ? $config['packages'] : [];

        return in_array(self::PACKAGE, $packages, true);
    }
}
