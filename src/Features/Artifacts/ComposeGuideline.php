<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;

/**
 * Composes this package's guideline into CLAUDE.md / AGENTS.md right after BoostRegistration adds
 * it to boost.json — boost:update only discovers new packages behind an interactive multiselect,
 * so a non-interactive run would otherwise register the guideline and never compose it.
 *
 * A Feature cannot branch between artifacts on BoostRegistration's own result, so this artifact
 * re-reads boost.json itself: it only runs when the package is registered right now AND
 * Context::boostRegisteredBeforeRun() says it was NOT registered when this run started — the same
 * "newly added" condition the original expressed via registerGuideline()'s boolean return. Wraps a
 * ProcessStep for the actual subprocess (reusing its Ran/Failed/ranDetail handling), the same way
 * ArtisanShim wraps StubFile — ProcessStep alone cannot express the missing-binary note, which
 * fires with no row at all, unlike BoostRun's skip.
 */
final readonly class ComposeGuideline implements Artifact
{
    private const string NOTE = 'Run vendor/bin/testbench boost:update to compose this guideline into CLAUDE.md / AGENTS.md.';

    public function __construct(private string $package) {}

    public function label(): string
    {
        return 'boost:update';
    }

    /**
     * Never reached under --check in the original either: registerGuideline() always returns
     * false while checking(), so composeGuideline() is never called.
     *
     * @return iterable<Result>
     */
    public function drift(Context $context): iterable
    {
        yield new Result($this->label(), Status::NotCheckable);
    }

    /** @return iterable<Result> */
    public function apply(Context $context): iterable
    {
        if ($context->boostRegisteredBeforeRun() || ! $this->registered($context)) {
            return;
        }

        if (! is_file($context->path('vendor/bin/testbench'))) {
            $context->note(self::NOTE);

            return;
        }

        $step = new ProcessStep(
            $this->label(),
            [PHP_BINARY, 'vendor/bin/testbench', 'boost:update', '--no-interaction'],
            ranDetail: 'composed guideline',
        );

        // Drained eagerly, and the trailing note fired, before anything is yielded: a first()-only
        // consumer must still see the note, the same reason ArtisanShim/WorkbenchApp drain their
        // wrapped step before warning/noting.
        $results = iterator_to_array($step->apply($context), false);

        foreach ($results as $result) {
            if ($result->status === Status::Failed) {
                $context->note(self::NOTE);
            }
        }

        yield from $results;
    }

    /** @return bool Whether $this->package is currently listed in boost.json's packages key. */
    private function registered(Context $context): bool
    {
        $path = $context->path('boost.json');

        if (! file_exists($path)) {
            return false;
        }

        $config = json_decode((string) @file_get_contents($path), true);
        $packages = is_array($config) && is_array($config['packages'] ?? null) ? $config['packages'] : [];

        return in_array($this->package, $packages, true);
    }
}
