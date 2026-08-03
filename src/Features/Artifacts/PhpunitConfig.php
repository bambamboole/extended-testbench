<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;

/**
 * phpunit.xml.dist, plus a warning when browser tests are enabled but the file on disk still lacks
 * the Browser testsuite. That is reachable whenever the wrapped StubFile's write is a no-op or
 * fails: a declined overwrite prompt, a headless run without --force, a failed write, or --check
 * itself — so the check reads what is actually on disk after the write attempt, in both modes.
 */
final readonly class PhpunitConfig implements Artifact
{
    private const string BROWSER_TESTSUITE = <<<'XML'

            <testsuite name="Browser">
                <directory>tests/Browser</directory>
            </testsuite>
    XML;

    private StubFile $stub;

    public function __construct(private bool $browser)
    {
        $this->stub = new StubFile('phpunit.xml.dist', 'phpunit.xml.dist.stub', [
            'browser_testsuite' => $browser ? self::BROWSER_TESTSUITE : '',
        ], shadowedBy: 'phpunit.xml');
    }

    public function label(): string
    {
        return 'phpunit.xml.dist';
    }

    /** @return array<int, Result> */
    public function drift(Context $context): iterable
    {
        $results = iterator_to_array($this->stub->drift($context), false);

        $this->warnIfBrowserSuiteMissing($context);

        return $results;
    }

    /** @return array<int, Result> */
    public function apply(Context $context): iterable
    {
        $results = iterator_to_array($this->stub->apply($context), false);

        $this->warnIfBrowserSuiteMissing($context);

        return $results;
    }

    private function warnIfBrowserSuiteMissing(Context $context): void
    {
        if ($this->browser && ! str_contains((string) @file_get_contents($context->path('phpunit.xml.dist')), 'name="Browser"')) {
            $context->warn('phpunit.xml.dist does not include the Browser testsuite — add it by hand.');
        }
    }
}
