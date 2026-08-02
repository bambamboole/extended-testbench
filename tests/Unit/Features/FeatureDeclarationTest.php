<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\CiFeature;
use Bambamboole\ExtendedTestbench\Features\GitFeature;

it('declares the gitattributes and gitignore artifacts', function () {
    $labels = array_map(
        fn (Artifact $artifact): string => $artifact->label(),
        iterator_to_array((new GitFeature)->artifacts(makeContext()), false),
    );

    expect($labels)->toBe(['.gitattributes', '.gitignore']);
});

it('has no flag, because it is always on', function () {
    expect((new CiFeature)->flag())->toBeNull();
});
