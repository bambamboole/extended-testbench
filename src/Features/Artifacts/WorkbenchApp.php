<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;
use Symfony\Component\Process\Process;

/**
 * Runs `workbench:devtool`, which is what actually writes the workbench app's namespaces,
 * directories and composer autoload-dev entries — Testbench's own job, not ours. ProcessStep
 * cannot express this: it only ever emits NotCheckable under drift and has no notion of a
 * prerequisite binary that, when missing, changes both the row's label ('workbench:devtool' ->
 * skipped) and the text of a note(), so this is a small dedicated artifact instead.
 *
 * label()/drift() report on 'workbench/app' — the directory workbench:devtool would produce —
 * because that is the only thing --check can actually inspect. apply()'s own row is
 * 'workbench:devtool', matching the original's two different row names for check vs. run.
 */
final readonly class WorkbenchApp implements Artifact
{
    public function label(): string
    {
        return 'workbench/app';
    }

    /** @return iterable<Result> */
    public function drift(Context $context): iterable
    {
        yield new Result($this->label(), $context->hasWorkbench() ? Status::Ok : Status::Missing);
    }

    /** @return iterable<Result> */
    public function apply(Context $context): iterable
    {
        if (! is_file($context->path('vendor/bin/testbench'))) {
            // Noted before anything is yielded, not after: a consumer that only pulls the first
            // result (as first() does) must still see it, the same reason ArtisanShim and
            // PhpunitConfig drain their wrapped StubFile eagerly before warning.
            $context->note('Run vendor/bin/testbench workbench:devtool to finish the workbench setup.');

            yield new Result('workbench:devtool', Status::Skipped, 'skipped (no vendor/bin/testbench)');

            return;
        }

        $process = new Process(
            [PHP_BINARY, 'vendor/bin/testbench', 'workbench:devtool', '--no-interaction'],
            $context->root(),
            timeout: null,
        );

        $process->run(fn (string $type, string $buffer) => $context->output()->write($buffer));

        yield new Result('workbench:devtool', $process->isSuccessful() ? Status::Ran : Status::Failed);
    }
}
