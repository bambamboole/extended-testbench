<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;
use Symfony\Component\Process\Process;
use Throwable;

/** A single generated file: an entrypoint, a config, or a scaffold like TestCase.php. */
final readonly class StubFile implements Artifact
{
    /** @param  array<string, string>  $replacements */
    public function __construct(
        private string $path,
        private string $stub,
        private array $replacements = [],
        private bool $onlyIfMissing = false,
        /** The legacy file (e.g. 'phpunit.xml') that shadows this one when it also exists. */
        private ?string $shadowedBy = null,
    ) {}

    public function label(): string
    {
        return $this->path;
    }

    /**
     * A file written with onlyIfMissing holds hand-written code, so only its absence is drift —
     * comparing its body against the stub would report every package that has ever edited its own
     * TestCase. For the generated configs the body is the whole point, so those are compared.
     *
     * @return iterable<Result>
     */
    public function drift(Context $context): iterable
    {
        $target = $context->path($this->path);
        $rendered = $context->render($this->stub, $this->replacements);

        if (! file_exists($target)) {
            yield $this->result($context, Status::Missing);

            return;
        }

        // Whitespace-insensitive: a package that wraps withPaths([...]) across lines, or indents its
        // neon differently, has not diverged from the scaffold in any way it can act on. Key order
        // still reads as drift — parsing four config languages to normalise that is not worth it;
        // baseline it with extra.extended-testbench.check-ignore instead.
        $matches = static fn (string $a, string $b): bool => preg_replace('/\s+/', '', $a) === preg_replace('/\s+/', '', $b);

        if ($this->onlyIfMissing || $matches((string) @file_get_contents($target), $rendered)) {
            yield $this->result($context, Status::Ok);

            return;
        }

        $this->diff($context, $target, $rendered);

        yield $this->result($context, Status::Differs);
    }

    /** @return iterable<Result> */
    public function apply(Context $context): iterable
    {
        $target = $context->path($this->path);
        $rendered = $context->render($this->stub, $this->replacements);

        // A dangling symlink makes file_exists() report false, so onlyIfMissing would not trip and
        // file_put_contents() would write through the link, creating whatever it pointed at.
        if (is_link($target) && ! file_exists($target)) {
            @unlink($target);
        }

        $existed = file_exists($target);

        if ($existed) {
            if ($this->onlyIfMissing) {
                yield $this->result($context, Status::Skipped, 'skipped (exists)');

                return;
            }

            // Only the generated config files reach this branch; anything holding hand-written code
            // is written with onlyIfMissing above and stays out of --force's reach. Without a real
            // prompt the answer is no, so a headless run reports the skip instead of asking nobody.
            $overwrite = $context->force();

            if (! $overwrite && $context->canPrompt()) {
                $this->diff($context, $target, $rendered);

                $overwrite = $context->confirm("Overwrite {$this->path}?", false);
            }

            if (! $overwrite) {
                yield $this->result($context, Status::Skipped, 'skipped (exists, --force to replace)');

                return;
            }
        }

        if (! is_dir(dirname($target)) && ! @mkdir(dirname($target), 0755, recursive: true)) {
            yield $this->result($context, Status::Failed);

            return;
        }

        if (@file_put_contents($target, $rendered) === false) {
            yield $this->result($context, Status::Failed);

            return;
        }

        yield $this->result($context, $existed ? Status::Overwritten : Status::Written);
    }

    /**
     * Warns when a legacy config next to a generated `.dist` file shadows it — both PHPUnit and
     * PHPStan prefer the non-`.dist` name, so the scaffold would be silently ignored. Warn only:
     * no rename, no prompt. The row is rewritten only on a successful write, so a failed or
     * skipped one keeps its true status rather than claiming the file was written.
     */
    private function result(Context $context, Status $status, ?string $detail = null): Result
    {
        $result = new Result($this->path, $status, $detail);

        if ($this->shadowedBy === null || ! file_exists($context->path($this->shadowedBy))) {
            return $result;
        }

        $context->warn("{$this->shadowedBy} already exists and takes precedence over {$this->path}, so the generated file will be ignored. Rename it with `git mv {$this->shadowedBy} {$this->path}` if you want the scaffold to apply.");

        if (in_array($status, [Status::Written, Status::Overwritten], true)) {
            return new Result($this->path, $status, "written (shadowed by {$this->shadowedBy})");
        }

        return $result;
    }

    /**
     * ponytail: shells out to POSIX `diff`, so the body of the drift is invisible on a Windows box
     * without one. The row still reports `differs` there; swap in a PHP differ if that matters.
     */
    private function diff(Context $context, string $target, string $rendered): void
    {
        $context->note("{$this->path} differs from the scaffold:");

        // No --label: it is GNU-only, and an unsupported flag would cost the whole diff rather than
        // just its header. `-` is the scaffold arriving on stdin.
        $process = new Process(['diff', '-u', $target, '-'], $context->root());
        $process->setInput($rendered);

        try {
            $process->run();
        } catch (Throwable) {
            return;
        }

        $output = trim($process->getOutput());

        if ($output !== '') {
            $context->output()->writeln($output);
        }
    }
}
