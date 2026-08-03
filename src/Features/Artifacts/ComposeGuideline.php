<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\BoostJson;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;

/**
 * Composes this package's guideline into CLAUDE.md / AGENTS.md right after BoostRegistration adds
 * it to boost.json — boost:update only discovers new packages behind an interactive multiselect,
 * so a non-interactive run would otherwise register the guideline and never compose it.
 *
 * A Feature cannot branch between artifacts on BoostRegistration's result, so this re-reads
 * boost.json itself: it runs only when the package is registered right now AND
 * $registeredBeforeRun (BoostFeature's snapshot, taken before BoostRegistration could change it)
 * says it was not when the run started.
 */
final readonly class ComposeGuideline implements Artifact
{
    private const string NOTE = 'Run vendor/bin/testbench boost:update to compose this guideline into CLAUDE.md / AGENTS.md.';

    public function __construct(
        private string $package,
        private bool $registeredBeforeRun,
    ) {}

    public function label(): string
    {
        return 'boost:update';
    }

    /** @return array<int, Result> */
    public function drift(Context $context): iterable
    {
        return [new Result($this->label(), Status::NotCheckable)];
    }

    /** @return array<int, Result> */
    public function apply(Context $context): iterable
    {
        if ($this->registeredBeforeRun || ! BoostJson::registers($context, $this->package)) {
            return [];
        }

        if (! is_file($context->path('vendor/bin/testbench'))) {
            $context->note(self::NOTE);

            return [];
        }

        $step = new ProcessStep(
            $this->label(),
            [PHP_BINARY, 'vendor/bin/testbench', 'boost:update', '--no-interaction'],
            ranDetail: 'composed guideline',
        );

        $results = iterator_to_array($step->apply($context), false);

        foreach ($results as $result) {
            if ($result->status === Status::Failed) {
                $context->note(self::NOTE);
            }
        }

        return $results;
    }
}
