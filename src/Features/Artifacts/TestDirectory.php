<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;

/**
 * A test directory (tests/Unit, tests/Feature) that needs a .gitkeep so PHPUnit does not fail to
 * boot on a package that has no tests yet.
 */
final readonly class TestDirectory implements Artifact
{
    public function __construct(private string $path) {}

    public function label(): string
    {
        return $this->path;
    }

    /** @return iterable<Result> */
    public function drift(Context $context): iterable
    {
        yield new Result($this->path, is_dir($context->path($this->path)) ? Status::Ok : Status::Missing);
    }

    /** @return iterable<Result> */
    public function apply(Context $context): iterable
    {
        $dir = $context->path($this->path);

        if (! is_dir($dir) && ! @$context->files()->makeDirectory($dir, 0755, recursive: true)) {
            yield new Result($this->path, Status::Failed);

            return;
        }

        $gitkeep = $this->path.'/.gitkeep';

        if (file_exists($context->path($gitkeep))) {
            yield new Result($this->path, Status::Skipped, 'skipped (exists)');

            return;
        }

        if (@$context->files()->put($context->path($gitkeep), '') === false) {
            yield new Result($gitkeep, Status::Failed);

            return;
        }

        yield new Result($gitkeep, Status::Written);
    }
}
