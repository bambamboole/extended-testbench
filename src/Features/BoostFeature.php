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

        // Registration has to follow the boost run: boost:install is what creates boost.json, but
        // Boost composes the guidelines during that same run, before our name is in the packages
        // key. Snapshotting here — this resumes only once BoostRun has been fully applied — is what
        // lets ComposeGuideline tell "just added" from "was already there".
        $registeredBefore = BoostJson::registers($context, self::PACKAGE);

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
}
