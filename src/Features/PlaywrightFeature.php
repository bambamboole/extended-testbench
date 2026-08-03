<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

use Bambamboole\ExtendedTestbench\Features\Artifacts\ProcessStep;

final readonly class PlaywrightFeature implements Feature
{
    public function flag(): Flag
    {
        return new Flag('playwright', 'Install Playwright browsers now?', false, 'Install Playwright browsers', 'Skip installing Playwright browsers');
    }

    /** @return iterable<Artifact> */
    public function artifacts(Context $context): iterable
    {
        yield new ProcessStep('npx playwright install', ['npx', 'playwright', 'install']);
    }
}
