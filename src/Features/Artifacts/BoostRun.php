<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;

/**
 * Runs `boost:install` (no boost.json yet) or `boost:update --discover` (boost.json already
 * exists) — the same choice BoostFeature's boostCommand() makes. Wraps a ProcessStep for the
 * actual subprocess (its `?array $ttyCommand` exists precisely for this TTY/non-TTY argv
 * asymmetry), the same way ComposeGuideline wraps one — ProcessStep alone cannot express the
 * missing-binary guard (skip with a note, no process run at all) or the failure note pointing at
 * APP_ENV=local, so those stay here around it.
 *
 * label() is the given command joined with a space ('boost:install' or 'boost:update --discover'),
 * matching the original's `implode(' ', $command)`.
 */
final readonly class BoostRun implements Artifact
{
    /** @param  array<int, string>  $command */
    public function __construct(private array $command) {}

    public function label(): string
    {
        return implode(' ', $this->command);
    }

    /**
     * The original returns from boost() before doing anything under --check, with no row pushed
     * at all. NotCheckable is what the runner omits from the drift table for that, the same idiom
     * PlaywrightFeature relies on for `npx playwright install`.
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
        if (! is_file($context->path('vendor/bin/testbench'))) {
            // Noted before anything is yielded, not after: a first()-only consumer must still see
            // it, the same reason WorkbenchApp drains eagerly before warning.
            $context->note("Run vendor/bin/testbench {$this->label()} to compose the guidelines.");

            yield new Result($this->label(), Status::Skipped, 'skipped (no vendor/bin/testbench)');

            return;
        }

        // The TTY branch drops --no-interaction (so Boost's interactive wizard still works on a
        // real terminal); the non-TTY branch keeps it (a headless run, timeout: null, must never
        // block on a prompt nobody can answer).
        $prefix = [PHP_BINARY, 'vendor/bin/testbench', ...$this->command];

        $step = new ProcessStep($this->label(), [...$prefix, '--no-interaction'], ttyCommand: $prefix);

        // Drained eagerly, and the failure note fired, before anything is yielded: a first()-only
        // consumer must still see it, same reason ComposeGuideline drains its wrapped step first.
        $results = iterator_to_array($step->apply($context), false);

        foreach ($results as $result) {
            if ($result->status === Status::Failed) {
                $context->note("Boost's commands are only registered in a local environment. Add APP_ENV=local to the env section of testbench.yaml, then run vendor/bin/testbench {$this->label()} yourself.");
            }
        }

        yield from $results;
    }
}
