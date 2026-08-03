<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\ArtisanShim;
use Bambamboole\ExtendedTestbench\Features\Status;

it('labels itself artisan', function () {
    expect(new ArtisanShim()->label())->toBe('artisan');
});

it('reports a missing artisan as missing and writes it on apply', function () {
    $context = makeContext();
    $artifact = new ArtisanShim;

    expect(first($artifact->drift($context))->status)->toBe(Status::Missing);

    $result = first($artifact->apply($context));

    expect($result->status)->toBe(Status::Written)
        ->and(file_get_contents($context->path('artisan')))
        ->toContain("require __DIR__.'/vendor/bin/testbench';");
});

it('reports a symlink to the testbench binary as differs, with no other row, under drift', function () {
    $context = makeContext();
    mkdir($context->path('vendor/bin'), 0755, true);
    file_put_contents($context->path('vendor/bin/testbench'), "#!/usr/bin/env php\n");
    symlink($context->path('vendor/bin/testbench'), $context->path('artisan'));

    $results = iterator_to_array(new ArtisanShim()->drift($context), false);

    expect($results)->toHaveCount(1)
        ->and($results[0]->status)->toBe(Status::Differs)
        ->and($results[0]->describe())->toBe('differs (symlink, not the committed shim)');
});

it('unlinks a working symlink to the testbench binary, notes it, then writes the shim', function () {
    $context = makeContext();
    mkdir($context->path('vendor/bin'), 0755, true);
    file_put_contents($context->path('vendor/bin/testbench'), "#!/usr/bin/env php\n");
    symlink($context->path('vendor/bin/testbench'), $context->path('artisan'));

    $result = first(new ArtisanShim()->apply($context));

    expect($result->status)->toBe(Status::Written)
        ->and(is_link($context->path('artisan')))->toBeFalse()
        ->and(file_get_contents($context->path('artisan')))
        ->toContain("require __DIR__.'/vendor/bin/testbench';")
        ->and(fetchOutput($context))
        ->toContain('artisan was a symlink to vendor/bin/testbench; replacing it with the committed shim, which survives a fresh clone and works on Windows.');
});

it('replaces a dangling symlink instead of writing through it, without the vendor/bin/testbench note', function () {
    $context = makeContext();
    symlink($context->path('does-not-exist'), $context->path('artisan'));

    $result = first(new ArtisanShim()->apply($context));

    expect($result->status)->toBe(Status::Written)
        ->and(is_link($context->path('artisan')))->toBeFalse()
        ->and(fetchOutput($context))->toBe('');
});

it('leaves a symlink to something else alone and warns, yielding the stub skipped row', function () {
    $context = makeContext();
    file_put_contents($context->path('elsewhere.php'), "<?php // mine\n");
    symlink($context->path('elsewhere.php'), $context->path('artisan'));

    $results = iterator_to_array(new ArtisanShim()->apply($context), false);

    expect($results)->toHaveCount(1)
        ->and($results[0]->status)->toBe(Status::Skipped)
        ->and($results[0]->describe())->toBe('skipped (exists)')
        ->and(is_link($context->path('artisan')))->toBeTrue()
        ->and(fetchOutput($context))
        ->toContain('artisan is a symlink to something other than vendor/bin/testbench, so it was left alone. Replace it with `rm artisan` and rerun if you want the committed shim.');
});

it('warns on the same still-a-symlink case under drift, without touching the filesystem', function () {
    $context = makeContext();
    file_put_contents($context->path('elsewhere.php'), "<?php // mine\n");
    symlink($context->path('elsewhere.php'), $context->path('artisan'));

    $results = iterator_to_array(new ArtisanShim()->drift($context), false);

    expect($results)->toHaveCount(1)
        ->and($results[0]->status)->toBe(Status::Ok)
        ->and(is_link($context->path('artisan')))->toBeTrue()
        ->and(fetchOutput($context))
        ->toContain('artisan is a symlink to something other than vendor/bin/testbench, so it was left alone. Replace it with `rm artisan` and rerun if you want the committed shim.');
});
