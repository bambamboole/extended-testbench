<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\CiFeature;
use Bambamboole\ExtendedTestbench\Features\EntrypointFeature;
use Bambamboole\ExtendedTestbench\Features\GitFeature;
use Bambamboole\ExtendedTestbench\Features\GitignoreFeature;

/**
 * @param  iterable<Artifact>  $artifacts
 * @return array<int, string>
 */
function artifactLabels(iterable $artifacts): array
{
    return array_map(
        fn (Artifact $artifact): string => $artifact->label(),
        iterator_to_array($artifacts, false),
    );
}

it('declares the gitattributes artifact', function () {
    expect(artifactLabels((new GitFeature)->artifacts(makeContext())))->toBe(['.gitattributes']);
});

it('declares the gitignore artifact', function () {
    expect(artifactLabels((new GitignoreFeature)->artifacts(makeContext())))->toBe(['.gitignore']);
});

it('declares the ci workflow artifact', function () {
    expect(artifactLabels((new CiFeature)->artifacts(makeContext())))->toBe(['.github/workflows/ci.yml']);
});

it('declares the artisan artifact', function () {
    expect(artifactLabels((new EntrypointFeature)->artifacts(makeContext())))->toBe(['artisan']);
});

it('has no flag, because it is always on', function () {
    expect((new CiFeature)->flag())->toBeNull();
});
