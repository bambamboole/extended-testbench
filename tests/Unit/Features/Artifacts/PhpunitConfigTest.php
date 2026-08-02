<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\PhpunitConfig;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Status;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

afterEach(function () {
    Prompt::setOutput(new ConsoleOutput);
});

function fetchPhpunitOutput(Context $context): string
{
    /** @var BufferedOutput $output */
    $output = $context->output();

    return $output->fetch();
}

it('labels itself phpunit.xml.dist', function () {
    expect(new PhpunitConfig(false)->label())->toBe('phpunit.xml.dist');
});

it('writes the Browser testsuite and does not warn when browser is enabled on a fresh file', function () {
    $context = makeContext();
    Prompt::setOutput($context->output());

    $result = first(new PhpunitConfig(true)->apply($context));

    expect($result->status)->toBe(Status::Written)
        ->and(file_get_contents($context->path('phpunit.xml.dist')))->toContain('name="Browser"')
        ->and(fetchPhpunitOutput($context))->toBe('');
});

it('writes without the Browser testsuite and never warns when browser is disabled', function () {
    $context = makeContext();
    Prompt::setOutput($context->output());

    first(new PhpunitConfig(false)->apply($context));

    expect(file_get_contents($context->path('phpunit.xml.dist')))->not->toContain('name="Browser"')
        ->and(fetchPhpunitOutput($context))->toBe('');
});

it('warns when an existing file is kept on a declined overwrite and lacks the Browser testsuite', function () {
    $context = makeContext();
    Prompt::setOutput($context->output());
    file_put_contents($context->path('phpunit.xml.dist'), 'ORIGINAL, no browser suite');

    // makeContext() hands back canPrompt: false, so the overwrite is skipped without --force —
    // the "headless, no --force" live path the reviewer named collapses onto this same one.
    $result = first(new PhpunitConfig(true)->apply($context));

    expect($result->status)->toBe(Status::Skipped)
        ->and(fetchPhpunitOutput($context))
        ->toContain('phpunit.xml.dist does not include the Browser testsuite — add it by hand.');
});

it('does not warn once --force overwrites the kept file with the Browser testsuite', function () {
    $context = makeContext(force: true);
    Prompt::setOutput($context->output());
    file_put_contents($context->path('phpunit.xml.dist'), 'ORIGINAL, no browser suite');

    $result = first(new PhpunitConfig(true)->apply($context));

    expect($result->status)->toBe(Status::Overwritten)
        ->and(fetchPhpunitOutput($context))->toBe('');
});

// A failed write (mkdir'ing over the target so file_put_contents() always fails against it) is not
// exercised here: simulating a real filesystem failure warns noisily and unreliably across
// platforms, the same gap StubFile's and TestDirectory's own Failed paths already carry (Task 3/5
// ledger). The warning logic itself does not branch on write outcome — warnIfBrowserSuiteMissing()
// only ever reads what's actually on disk afterwards — so the "declined overwrite" and "still
// missing" tests above already exercise the same code path a failed write would take.

it('warns under drift/--check when an existing file lacks the Browser testsuite', function () {
    // --check never writes, so drift() has to read whatever was already on disk before this run —
    // exercised here via a pre-existing file rather than a genuinely absent one, since reading a
    // path that does not exist at all triggers the same noisy, unsuppressable warning documented
    // above; the guard in warnIfBrowserSuiteMissing() is identical for both cases.
    $context = makeContext();
    Prompt::setOutput($context->output());
    file_put_contents($context->path('phpunit.xml.dist'), 'ORIGINAL, no browser suite');

    $result = first(new PhpunitConfig(true)->drift($context));

    expect($result->status)->toBe(Status::Differs)
        ->and(fetchPhpunitOutput($context))
        ->toContain('phpunit.xml.dist does not include the Browser testsuite — add it by hand.');
});

it('does not warn under drift when browser is disabled', function () {
    $context = makeContext();
    Prompt::setOutput($context->output());

    first(new PhpunitConfig(false)->drift($context));

    expect(fetchPhpunitOutput($context))->toBe('');
});

it('still fires the shadow warning from the wrapped StubFile', function () {
    $context = makeContext(force: true);
    Prompt::setOutput($context->output());
    file_put_contents($context->path('phpunit.xml'), '<phpunit/>');

    $result = first(new PhpunitConfig(false)->apply($context));

    expect($result->status)->toBe(Status::Written)
        ->and($result->describe())->toBe('written (shadowed by phpunit.xml)')
        ->and(fetchPhpunitOutput($context))
        ->toContain('phpunit.xml already exists and takes precedence over phpunit.xml.dist');
});

it('still warns when the consumer only pulls the first result, like first() does', function () {
    $context = makeContext();
    Prompt::setOutput($context->output());
    file_put_contents($context->path('phpunit.xml.dist'), 'ORIGINAL, no browser suite');

    $result = first(new PhpunitConfig(true)->apply($context));

    expect($result->status)->toBe(Status::Skipped)
        ->and(fetchPhpunitOutput($context))
        ->toContain('phpunit.xml.dist does not include the Browser testsuite — add it by hand.');
});
