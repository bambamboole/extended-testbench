<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

use Bambamboole\ExtendedTestbench\Features\Artifacts\NeedsPackage;
use Bambamboole\ExtendedTestbench\Features\Artifacts\Script;
use Bambamboole\ExtendedTestbench\Features\Artifacts\StubFile;

final readonly class PintFeature implements Feature
{
    public function flag(): Flag
    {
        return new Flag('pint', 'Add Pint?', true);
    }

    /** @return iterable<Artifact> */
    public function artifacts(Context $context): iterable
    {
        yield new NeedsPackage('laravel/pint:^1.16');

        yield new StubFile('pint.json', 'pint.json.stub');

        yield new Script('lint', 'pint --format agent');
    }
}
