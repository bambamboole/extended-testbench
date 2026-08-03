<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

use Bambamboole\ExtendedTestbench\Features\Artifacts\StubFile;

/**
 * The GitHub workflow. Not a StaticFeature: with browser tests accepted it appends a dedicated
 * browser job — one PHP version, Playwright Chromium installed once, assets built when the package
 * has a package.json — while the php × dependencies matrix keeps running `pest
 * --exclude-testsuite=Browser` through the composer scripts and never needs a browser.
 */
final readonly class CiFeature implements Feature
{
    private const string BROWSER_JOB = <<<'YAML'


          browser:
            runs-on: ubuntu-latest

            steps:
              - uses: actions/checkout@v5

              - uses: shivammathur/setup-php@v2
                with:
                  php-version: '8.4'
                  coverage: none

              - uses: ramsey/composer-install@v3
        {{ npm_steps }}
              - run: npx playwright install --with-deps chromium

              - run: ./vendor/bin/pest --testsuite=Browser
        YAML;

    private const string NPM_STEPS = <<<'YAML'

              - uses: actions/setup-node@v4
                with:
                  node-version: 22
                  cache: npm

              - run: npm ci

              - run: npm run build

        YAML;

    public function flag(): ?Flag
    {
        return null;
    }

    /** @return iterable<Artifact> */
    public function artifacts(Context $context): iterable
    {
        $browser = $context->enabled('browser');

        // A workflow that predates the browser answer keeps running without the Browser suite;
        // merging a job into someone's existing YAML is not ours to do, so it gets said out loud.
        if ($browser && file_exists($context->path('.github/workflows/ci.yml'))
            && ! str_contains((string) file_get_contents($context->path('.github/workflows/ci.yml')), '--testsuite=Browser')) {
            $context->warn('.github/workflows/ci.yml already exists and runs no Browser suite. Add a job that installs Playwright Chromium and runs `./vendor/bin/pest --testsuite=Browser` — the generated workflow in this package\'s stubs/ci.yml.stub shows the shape.');
        }

        yield new StubFile('.github/workflows/ci.yml', 'ci.yml.stub', [
            'browser_job' => $browser ? $this->browserJob($context) : '',
        ], onlyIfMissing: true);
    }

    private function browserJob(Context $context): string
    {
        return str_replace(
            '{{ npm_steps }}',
            file_exists($context->path('package.json')) ? self::NPM_STEPS : '',
            self::BROWSER_JOB,
        );
    }
}
