<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;
use Symfony\Component\Process\Process;

/**
 * A single subprocess run during apply. $command and $ttyCommand are FULL argv arrays: no testbench
 * prefix is assumed, since playwright's `['npx', 'playwright', 'install']` has none while the
 * testbench commands need `[PHP_BINARY, 'vendor/bin/testbench', ...]` prepended by the caller.
 *
 * $ttyCommand exists because a TTY run may need different argv, not just a different output sink —
 * see BoostRun, which drops --no-interaction there.
 */
final readonly class ProcessStep implements Artifact
{
    /**
     * @param  array<int, string>  $command  run when no TTY is used — output streams through $context->output().
     * @param  array<int, string>|null  $ttyCommand  run instead, via setTty(true) and no output callback, when non-null and Process::isTtySupported().
     * @param  string|null  $ranDetail  overrides the default 'ran' detail on success, e.g. 'composed guideline'.
     */
    public function __construct(
        private string $label,
        private array $command,
        private ?array $ttyCommand = null,
        private ?string $ranDetail = null,
    ) {}

    public function label(): string
    {
        return $this->label;
    }

    /**
     * A subprocess's outcome can only be known by running it, so --check never runs one and
     * reports NotCheckable instead, which the runner omits from the drift table.
     *
     * @return iterable<Result>
     */
    public function drift(Context $context): iterable
    {
        yield new Result($this->label, Status::NotCheckable);
    }

    /** @return iterable<Result> */
    public function apply(Context $context): iterable
    {
        if ($this->ttyCommand !== null && Process::isTtySupported()) {
            $process = new Process($this->ttyCommand, $context->root(), timeout: null);
            $process->setTty(true);
            $process->run();
        } else {
            $process = new Process($this->command, $context->root(), timeout: null);
            $process->run(fn (string $type, string $buffer) => $context->output()->write($buffer));
        }

        $successful = $process->isSuccessful();

        yield new Result($this->label, $successful ? Status::Ran : Status::Failed, $successful ? $this->ranDetail : null);
    }
}
