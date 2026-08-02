<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;
use Symfony\Component\Process\Process;

/**
 * Runs `boost:install` (no boost.json yet) or `boost:update --discover` (boost.json already
 * exists) — the same choice BoostFeature's boostCommand() makes. ProcessStep cannot express this:
 * it always runs its command, but the original guards on vendor/bin/testbench existing first —
 * when it is missing, the row is 'skipped' and a note fires instead of running anything, and a run
 * that DOES execute but fails gets a second note pointing at APP_ENV=local. Same shape as
 * WorkbenchApp.
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

        if (Process::isTtySupported()) {
            $process = new Process([PHP_BINARY, 'vendor/bin/testbench', ...$this->command], $context->root(), timeout: null);
            $process->setTty(true);
            $process->run();
        } else {
            $process = new Process([PHP_BINARY, 'vendor/bin/testbench', ...$this->command, '--no-interaction'], $context->root(), timeout: null);
            $process->run(fn (string $type, string $buffer) => $context->output()->write($buffer));
        }

        $successful = $process->isSuccessful();

        if (! $successful) {
            $context->note("Boost's commands are only registered in a local environment. Add APP_ENV=local to the env section of testbench.yaml, then run vendor/bin/testbench {$this->label()} yourself.");
        }

        yield new Result($this->label(), $successful ? Status::Ran : Status::Failed);
    }
}
