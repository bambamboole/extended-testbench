<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\ArtisanShim;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Status;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

afterEach(function () {
    Prompt::setOutput(new ConsoleOutput);
});

function fetchArtisanOutput(Context $context): string
{
    /** @var BufferedOutput $output */
    $output = $context->output();

    return $output->fetch();
}

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
    Prompt::setOutput($context->output());
    mkdir($context->path('vendor/bin'), 0755, true);
    file_put_contents($context->path('vendor/bin/testbench'), "#!/usr/bin/env php\n");
    symlink($context->path('vendor/bin/testbench'), $context->path('artisan'));

    $result = first(new ArtisanShim()->apply($context));

    expect($result->status)->toBe(Status::Written)
        ->and(is_link($context->path('artisan')))->toBeFalse()
        ->and(file_get_contents($context->path('artisan')))
        ->toContain("require __DIR__.'/vendor/bin/testbench';")
        ->and(fetchArtisanOutput($context))
        ->toContain('artisan was a symlink to vendor/bin/testbench; replacing it with the committed shim, which survives a fresh clone and works on Windows.');
});

it('replaces a dangling symlink instead of writing through it, without the vendor/bin/testbench note', function () {
    $context = makeContext();
    Prompt::setOutput($context->output());
    symlink($context->path('does-not-exist'), $context->path('artisan'));

    $result = first(new ArtisanShim()->apply($context));

    expect($result->status)->toBe(Status::Written)
        ->and(is_link($context->path('artisan')))->toBeFalse()
        ->and(fetchArtisanOutput($context))->toBe('');
});

it('leaves a symlink to something else alone and warns, yielding the stub skipped row', function () {
    $context = makeContext();
    Prompt::setOutput($context->output());
    file_put_contents($context->path('elsewhere.php'), "<?php // mine\n");
    symlink($context->path('elsewhere.php'), $context->path('artisan'));

    // Fully drains the generator (rather than first()'s early return) so the warning check that
    // runs after the delegated `yield from` actually executes — a real console runner iterates
    // every result, since it renders each as a table row.
    $results = iterator_to_array(new ArtisanShim()->apply($context), false);

    expect($results)->toHaveCount(1)
        ->and($results[0]->status)->toBe(Status::Skipped)
        ->and($results[0]->describe())->toBe('skipped (exists)')
        ->and(is_link($context->path('artisan')))->toBeTrue()
        ->and(fetchArtisanOutput($context))
        ->toContain('artisan is a symlink to something other than vendor/bin/testbench, so it was left alone. Replace it with `rm artisan` and rerun if you want the committed shim.');
});

it('still warns when the consumer only pulls the first result, like first() does', function () {
    // Regression guard: warnIfStillSymlinked() used to run after `yield from`, so a consumer that
    // stops after the first result (first() does exactly this, and four tests above use it) never
    // reached it and the warning was silently lost. The fix drains the wrapped StubFile eagerly and
    // warns before yielding anything, matching how StubFile itself fires its shadow warning inside
    // result() before yielding.
    $context = makeContext();
    Prompt::setOutput($context->output());
    file_put_contents($context->path('elsewhere.php'), "<?php // mine\n");
    symlink($context->path('elsewhere.php'), $context->path('artisan'));

    $result = first(new ArtisanShim()->apply($context));

    expect($result->status)->toBe(Status::Skipped)
        ->and(fetchArtisanOutput($context))
        ->toContain('artisan is a symlink to something other than vendor/bin/testbench, so it was left alone. Replace it with `rm artisan` and rerun if you want the committed shim.');
});

it('warns on the same still-a-symlink case under drift, without touching the filesystem', function () {
    $context = makeContext();
    Prompt::setOutput($context->output());
    file_put_contents($context->path('elsewhere.php'), "<?php // mine\n");
    symlink($context->path('elsewhere.php'), $context->path('artisan'));

    $results = iterator_to_array(new ArtisanShim()->drift($context), false);

    expect($results)->toHaveCount(1)
        ->and($results[0]->status)->toBe(Status::Ok)
        ->and(is_link($context->path('artisan')))->toBeTrue()
        ->and(fetchArtisanOutput($context))
        ->toContain('artisan is a symlink to something other than vendor/bin/testbench, so it was left alone. Replace it with `rm artisan` and rerun if you want the committed shim.');
});
