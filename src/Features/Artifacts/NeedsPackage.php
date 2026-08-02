<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;

/**
 * One or more require-dev constraints that are installed together in a single `composer require`
 * call — pestphp/pest and its Laravel plugin, larastan/larastan and the Pest PHPStan plugin — so
 * the constraints that ship as a set stay a single install rather than one process per package.
 */
final readonly class NeedsPackage implements Artifact
{
    /** @var array<int, string> */
    private array $constraints;

    public function __construct(string ...$constraints)
    {
        $this->constraints = $constraints;
    }

    public function label(): string
    {
        return $this->constraints[0];
    }

    /** @return iterable<Result> */
    public function drift(Context $context): iterable
    {
        foreach ($this->missing($context) as $constraint) {
            yield new Result($constraint, Status::Missing);
        }
    }

    /** @return iterable<Result> */
    public function apply(Context $context): iterable
    {
        $missing = $this->missing($context);

        if ($missing === []) {
            return;
        }

        if ($context->composer()->requirePackages($missing, dev: true, output: $context->output())) {
            return;
        }

        $context->markInstallFailed(...$missing);

        foreach ($missing as $constraint) {
            yield new Result($constraint, Status::Failed);
        }
    }

    /** @return array<int, string> */
    private function missing(Context $context): array
    {
        return array_values(array_filter(
            $this->constraints,
            fn (string $constraint): bool => ! $context->composer()->hasPackage(explode(':', $constraint)[0]),
        ));
    }
}
