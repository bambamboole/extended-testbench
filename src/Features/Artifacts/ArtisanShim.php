<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;

/**
 * Replaces a symlinked entrypoint with the committed shim. StubFile's onlyIfMissing would skip a
 * link that still resolves, and that is the common case: the widespread
 * `ln -s vendor/bin/testbench artisan` recipe works locally but breaks on a fresh clone and on
 * Windows. A link pointing anywhere else is the user's own and only gets a warning.
 */
final readonly class ArtisanShim implements Artifact
{
    private StubFile $stub;

    public function __construct()
    {
        $this->stub = new StubFile('artisan', 'artisan.stub', onlyIfMissing: true);
    }

    public function label(): string
    {
        return 'artisan';
    }

    /** @return iterable<Result> */
    public function drift(Context $context): iterable
    {
        if ($this->symlinkedToTestbench($context)) {
            yield new Result($this->label(), Status::Differs, 'differs (symlink, not the committed shim)');

            return;
        }

        yield from $this->stub->drift($context);

        $this->warnIfStillSymlinked($context);
    }

    /** @return iterable<Result> */
    public function apply(Context $context): iterable
    {
        if ($this->symlinkedToTestbench($context)) {
            @unlink($context->path('artisan'));
            $context->note('artisan was a symlink to vendor/bin/testbench; replacing it with the committed shim, which survives a fresh clone and works on Windows.');
        }

        yield from $this->stub->apply($context);

        $this->warnIfStillSymlinked($context);
    }

    private function symlinkedToTestbench(Context $context): bool
    {
        $path = $context->path('artisan');
        $binary = realpath($context->path('vendor/bin/testbench'));

        return is_link($path) && $binary !== false && realpath($path) === $binary;
    }

    private function warnIfStillSymlinked(Context $context): void
    {
        if (is_link($context->path('artisan'))) {
            $context->warn('artisan is a symlink to something other than vendor/bin/testbench, so it was left alone. Replace it with `rm artisan` and rerun if you want the committed shim.');
        }
    }
}
