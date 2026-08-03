<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;
use Symfony\Component\Process\Process;

/**
 * Runs `workbench:devtool`, which writes the workbench app's namespaces, directories and composer
 * autoload-dev entries — Testbench's job, not ours. Not a ProcessStep: that only ever emits
 * NotCheckable under drift, while --check can inspect the workbench/app directory the command
 * would produce, so drift() reports on that path and apply() on the command that creates it.
 */
final readonly class WorkbenchApp implements Artifact
{
    public function label(): string
    {
        return 'workbench/app';
    }

    /** @return array<int, Result> */
    public function drift(Context $context): iterable
    {
        return [new Result($this->label(), $context->hasWorkbench() ? Status::Ok : Status::Missing)];
    }

    /** @return array<int, Result> */
    public function apply(Context $context): iterable
    {
        // Same signal drift() reports ok on. Rerunning workbench:devtool over an existing app
        // generates factories, seeders, routes and autoload entries the package never asked for.
        if ($context->hasWorkbench()) {
            return [new Result($this->label(), Status::Skipped, 'skipped (exists)')];
        }

        if (! is_file($context->path('vendor/bin/testbench'))) {
            $context->note('Run vendor/bin/testbench workbench:devtool to finish the workbench setup.');

            return [new Result('workbench:devtool', Status::Skipped, 'skipped (no vendor/bin/testbench)')];
        }

        $process = new Process(
            [PHP_BINARY, 'vendor/bin/testbench', 'workbench:devtool', '--no-interaction'],
            $context->root(),
            timeout: null,
        );

        $process->run(fn (string $type, string $buffer) => $context->output()->write($buffer));

        return [new Result('workbench:devtool', $process->isSuccessful() ? Status::Ran : Status::Failed)];
    }
}
