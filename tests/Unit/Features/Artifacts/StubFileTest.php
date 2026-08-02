<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\StubFile;
use Bambamboole\ExtendedTestbench\Features\Status;

it('reports a missing file as missing and writes it on apply', function () {
    $context = makeContext();
    $artifact = new StubFile('artisan', 'artisan.stub');

    expect(first($artifact->drift($context))->status)->toBe(Status::Missing);

    $result = first($artifact->apply($context));

    expect($result->status)->toBe(Status::Written)
        ->and(file_get_contents($context->path('artisan')))
        ->toContain("require __DIR__.'/vendor/bin/testbench';");
});

it('treats a reformatted file as ok', function () {
    $context = makeContext();
    $artifact = new StubFile('pint.json', 'pint.json.stub');
    first($artifact->apply($context));

    file_put_contents(
        $context->path('pint.json'),
        preg_replace('/\s+/', '', (string) file_get_contents($context->path('pint.json'))),
    );

    expect(first($artifact->drift($context))->status)->toBe(Status::Ok);
});

it('reports a genuinely edited file as differing', function () {
    $context = makeContext();
    $artifact = new StubFile('pint.json', 'pint.json.stub');
    first($artifact->apply($context));
    file_put_contents($context->path('pint.json'), '{"preset":"symfony"}');

    expect(first($artifact->drift($context))->status)->toBe(Status::Differs);
});

it('checks only existence for a file that holds hand-written code', function () {
    $context = makeContext();
    $artifact = new StubFile('tests/TestCase.php', 'TestCase.php.stub', [
        'namespace' => 'Tests',
        'providers' => '',
    ], onlyIfMissing: true);
    first($artifact->apply($context));
    file_put_contents($context->path('tests/TestCase.php'), '<?php // mine');

    expect(first($artifact->drift($context))->status)->toBe(Status::Ok);
});

it('skips an existing file without force and replaces it with force', function () {
    $context = makeContext();
    file_put_contents($context->path('pint.json'), 'mine');

    $skipped = first(new StubFile('pint.json', 'pint.json.stub')->apply($context));

    expect($skipped->status)->toBe(Status::Skipped)
        ->and($skipped->describe())->toBe('skipped (exists, --force to replace)')
        ->and(file_get_contents($context->path('pint.json')))->toBe('mine');

    $forced = makeContext(force: true);
    file_put_contents($forced->path('pint.json'), 'mine');

    expect(first(new StubFile('pint.json', 'pint.json.stub')->apply($forced))->status)
        ->toBe(Status::Overwritten);
});

it('warns and rewrites the row when a legacy file shadows the generated one, regardless of write outcome', function () {
    $context = makeContext(force: true);
    file_put_contents($context->path('phpunit.xml'), '<phpunit/>');

    $artifact = new StubFile('phpunit.xml.dist', 'phpunit.xml.dist.stub', ['browser_testsuite' => ''], shadowedBy: 'phpunit.xml');

    $result = first($artifact->apply($context));

    expect($result->status)->toBe(Status::Written)
        ->and($result->describe())->toBe('written (shadowed by phpunit.xml)');

    $onlyIfMissing = makeContext(force: true);
    file_put_contents($onlyIfMissing->path('phpunit.xml'), '<phpunit/>');
    file_put_contents($onlyIfMissing->path('pint.json'), 'mine');

    $skipped = new StubFile('pint.json', 'pint.json.stub', onlyIfMissing: true, shadowedBy: 'phpunit.xml');

    // shadowedBy only matters relative to its own path, and onlyIfMissing files never get their
    // detail rewritten even when some other shadow exists — proves the rewrite is gated on
    // Written/Overwritten, not merely "a shadow exists".
    $skippedResult = first($skipped->apply($onlyIfMissing));

    expect($skippedResult->status)->toBe(Status::Skipped)
        ->and($skippedResult->describe())->toBe('skipped (exists)');
});
