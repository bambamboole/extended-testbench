<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;
use Symfony\Component\Process\Process;

/**
 * A single subprocess run during apply — workbench:devtool, npx playwright install, boost:install/
 * boost:update --discover, boost:update (compose). $command is the FULL argv the caller wants run:
 * ProcessStep does not assume a testbench prefix, since playwright's `['npx', 'playwright', 'install']`
 * has none while the testbench commands need `[PHP_BINARY, 'vendor/bin/testbench', ...]` prepended
 * by the caller. This keeps ProcessStep itself a plain, reusable "run this and report" primitive.
 */
final readonly class ProcessStep implements Artifact
{
    /** @param  array<int, string>  $command */
    public function __construct(
        private string $label,
        private array $command,
        private bool $tty = false,
    ) {}

    public function label(): string
    {
        return $this->label;
    }

    /**
     * A subprocess's outcome can only be known by running it, so --check never runs one and reports
     * NotCheckable instead — this is what every caller's `if ($this->checking()) { return; }` guard
     * did today.
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
        $process = new Process($this->command, $context->root(), timeout: null);

        if ($this->tty && Process::isTtySupported()) {
            $process->setTty(true);
            $process->run();
        } else {
            $process->run(fn (string $type, string $buffer) => $context->output()->write($buffer));
        }

        yield new Result($this->label, $process->isSuccessful() ? Status::Ran : Status::Failed);
    }
}
