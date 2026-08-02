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
 * boost:update --discover, boost:update (compose). $command and $ttyCommand are FULL argv arrays the
 * caller wants run: ProcessStep does not assume a testbench prefix, since playwright's
 * `['npx', 'playwright', 'install']` has none while the testbench commands need
 * `[PHP_BINARY, 'vendor/bin/testbench', ...]` prepended by the caller. This keeps ProcessStep itself
 * a plain, reusable "run this and report" primitive.
 *
 * boost()'s original TTY branch varies the argv itself, not just the output callback: a TTY run
 * drops `--no-interaction` (so Boost's interactive wizard still works on a real terminal) while the
 * non-TTY run keeps it (so a headless run — `timeout: null` means no other way out — never blocks on
 * a prompt nobody can answer). $ttyCommand carries that variant; when it is null, or no TTY is
 * available, $command runs unconditionally with the streaming callback, same as every other caller.
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
