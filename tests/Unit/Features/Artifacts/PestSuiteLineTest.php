<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\Artifacts\PestSuiteLine;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Status;
use Symfony\Component\Console\Output\BufferedOutput;

function writePestFile(Context $context, string $contents): void
{
    mkdir($context->path('tests'), 0755, true);
    file_put_contents($context->path('tests/Pest.php'), $contents);
}

it('labels itself tests/Pest.php', function () {
    expect(new PestSuiteLine('Browser', 'Tests\\BrowserTestCase')->label())->toBe('tests/Pest.php');
});

it('yields nothing on drift and apply when tests/Pest.php does not exist yet', function () {
    $context = makeContext();
    $artifact = new PestSuiteLine('Browser', 'Tests\\BrowserTestCase');

    expect(iterator_to_array($artifact->drift($context), false))->toBeEmpty()
        ->and(iterator_to_array($artifact->apply($context), false))->toBeEmpty();
});

it('reports missing under check when the suite is not yet mapped', function () {
    $context = makeContext();
    writePestFile($context, "<?php\n\nuses(Tests\\TestCase::class)->in('Feature');\n");

    $result = first(new PestSuiteLine('Browser', 'Tests\\BrowserTestCase')->drift($context));

    expect($result->label)->toBe('tests/Pest.php: Browser suite')
        ->and($result->status)->toBe(Status::Missing);
});

it('reports ok under check when the suite is already mapped', function () {
    $context = makeContext();
    writePestFile($context, "<?php\n\nuses(\\Tests\\BrowserTestCase::class)->in('Browser');\n");

    $result = first(new PestSuiteLine('Browser', 'Tests\\BrowserTestCase')->drift($context));

    expect($result->label)->toBe('tests/Pest.php: Browser suite')
        ->and($result->status)->toBe(Status::Ok);
});

it('appends the suite mapping and yields a written result', function () {
    $context = makeContext();
    writePestFile($context, "<?php\n\nuses(Tests\\TestCase::class)->in('Feature');\n");

    $result = first(new PestSuiteLine('Browser', 'Tests\\BrowserTestCase')->apply($context));

    expect($result->label)->toBe('tests/Pest.php')
        ->and($result->status)->toBe(Status::Written)
        ->and($result->describe())->toBe('browser suite appended');

    $pest = (string) file_get_contents($context->path('tests/Pest.php'));

    expect($pest)->toContain("uses(Tests\\TestCase::class)->in('Feature');")
        ->and($pest)->toContain("uses(\\Tests\\BrowserTestCase::class)->in('Browser');")
        ->and(substr_count($pest, "in('Browser')"))->toBe(1);
});

it('derives the appended detail from the suite name rather than hardcoding "browser"', function () {
    $context = makeContext();
    writePestFile($context, "<?php\n\nuses(Tests\\TestCase::class)->in('Feature');\n");

    $result = first(new PestSuiteLine('E2E', 'Tests\\E2ETestCase')->apply($context));

    expect($result->describe())->toBe('e2e suite appended');
});

it('yields nothing when the suite is already mapped to the dedicated test case', function () {
    $context = makeContext();
    writePestFile($context, "<?php\n\nuses(\\Tests\\BrowserTestCase::class)->in('Browser');\n");

    expect(iterator_to_array(new PestSuiteLine('Browser', 'Tests\\BrowserTestCase')->apply($context), false))
        ->toBeEmpty();
});

it('warns instead of silently keeping a mapping to the base TestCase, yielding nothing', function () {
    $context = makeContext();
    writePestFile($context, "<?php\n\ndeclare(strict_types=1);\n\nuses(\\Tests\\TestCase::class)->in('Feature', 'Unit', 'Browser');\n");

    $results = iterator_to_array(new PestSuiteLine('Browser', 'Tests\\BrowserTestCase')->apply($context), false);

    /** @var BufferedOutput $output */
    $output = $context->output();

    expect($results)->toBeEmpty()
        ->and($output->fetch())->toContain("tests/Pest.php already maps 'Browser' to the base TestCase. Change that line to uses(\\Tests\\BrowserTestCase::class)->in('Browser'); so the Vite guard runs.")
        ->and(file_get_contents($context->path('tests/Pest.php')))
        ->toBe("<?php\n\ndeclare(strict_types=1);\n\nuses(\\Tests\\TestCase::class)->in('Feature', 'Unit', 'Browser');\n");
});
