<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;

/**
 * Runs `boost:install` (no boost.json yet) or `boost:update --discover` (it already exists), as
 * chosen by BoostFeature. Wraps a ProcessStep for the subprocess itself — ProcessStep alone cannot
 * express the missing-binary guard (skip with a note, no process run) or the APP_ENV=local failure
 * note, so those stay here around it.
 */
final readonly class BoostRun implements Artifact
{
    /** @param  array<int, string>  $command */
    public function __construct(private array $command) {}

    public function label(): string
    {
        return implode(' ', $this->command);
    }

    /** @return array<int, Result> */
    public function drift(Context $context): iterable
    {
        return [new Result($this->label(), Status::NotCheckable)];
    }

    /** @return array<int, Result> */
    public function apply(Context $context): iterable
    {
        if (! is_file($context->path('vendor/bin/testbench'))) {
            $context->note("Run vendor/bin/testbench {$this->label()} to compose the guidelines.");

            return [new Result($this->label(), Status::Skipped, 'skipped (no vendor/bin/testbench)')];
        }

        // The TTY branch drops --no-interaction (so Boost's interactive wizard still works on a
        // real terminal); the non-TTY branch keeps it (a headless run, timeout: null, must never
        // block on a prompt nobody can answer).
        $prefix = [PHP_BINARY, 'vendor/bin/testbench', ...$this->command];

        $step = new ProcessStep($this->label(), [...$prefix, '--no-interaction'], ttyCommand: $prefix);

        $results = iterator_to_array($step->apply($context), false);

        foreach ($results as $result) {
            if ($result->status === Status::Failed) {
                $context->note("Boost's commands are only registered in a local environment. Add APP_ENV=local to the env section of testbench.yaml, then run vendor/bin/testbench {$this->label()} yourself.");
            }
        }

        return $results;
    }
}
