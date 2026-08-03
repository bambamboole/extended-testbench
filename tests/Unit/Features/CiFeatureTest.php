<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Features\CiFeature;

it('is always on', function () {
    expect((new CiFeature)->flag())->toBeNull();
});

it('scaffolds the matrix workflow without a browser job when browser tests were declined', function () {
    $context = makeContext();

    applyAll((new CiFeature)->artifacts($context), $context);

    $workflow = (string) file_get_contents($context->path('.github/workflows/ci.yml'));

    expect($workflow)
        ->toContain("php: ['8.4', '8.5']")
        ->toContain('dependencies: [highest, lowest]')
        ->toContain('php vendor/bin/testbench package:init --check')
        ->not->toContain('browser:')
        ->not->toContain('playwright');
});

it('appends a single browser job that installs Chromium once, outside the php matrix', function () {
    $context = makeContext(flags: ['browser' => true]);

    applyAll((new CiFeature)->artifacts($context), $context);

    $workflow = (string) file_get_contents($context->path('.github/workflows/ci.yml'));

    expect($workflow)
        ->toContain("\n  browser:\n")
        ->toContain('npx playwright install --with-deps chromium')
        ->toContain('./vendor/bin/pest --testsuite=Browser')
        ->not->toContain('npm ci')
        // One browser job, not one per matrix leg: chromium install must not sit in `check`.
        ->and(substr_count($workflow, 'playwright install'))->toBe(1)
        ->and(explode('browser:', $workflow)[0])->not->toContain('playwright');
});

it('builds frontend assets in the browser job when the package has a package.json', function () {
    $context = makeContext(flags: ['browser' => true]);
    file_put_contents($context->path('package.json'), '{}');

    applyAll((new CiFeature)->artifacts($context), $context);

    $workflow = (string) file_get_contents($context->path('.github/workflows/ci.yml'));
    $browserJob = explode('browser:', $workflow)[1];

    expect($browserJob)
        ->toContain('actions/setup-node')
        ->toContain('npm ci')
        ->toContain('npm run build')
        // Assets are built before the suite that serves them.
        ->and(strpos($browserJob, 'npm run build'))->toBeLessThan(strpos($browserJob, '--testsuite=Browser'));
});

it('leaves an existing workflow alone and warns that it runs no Browser suite', function () {
    $context = makeContext(flags: ['browser' => true]);
    mkdir($context->path('.github/workflows'), 0755, true);
    file_put_contents($context->path('.github/workflows/ci.yml'), "name: CI\njobs: {}\n");

    applyAll((new CiFeature)->artifacts($context), $context);

    expect(file_get_contents($context->path('.github/workflows/ci.yml')))->toBe("name: CI\njobs: {}\n")
        ->and(fetchOutput($context))
        ->toContain('.github/workflows/ci.yml already exists and runs no Browser suite.');
});

it('does not warn about an existing workflow that already runs the Browser suite', function () {
    $context = makeContext(flags: ['browser' => true]);
    mkdir($context->path('.github/workflows'), 0755, true);
    file_put_contents($context->path('.github/workflows/ci.yml'), "jobs:\n  browser:\n    steps:\n      - run: ./vendor/bin/pest --testsuite=Browser\n");

    applyAll((new CiFeature)->artifacts($context), $context);

    expect(fetchOutput($context))->toBe('');
});
