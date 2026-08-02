<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;

/**
 * phpunit.xml.dist, plus the warning pest() fires when browser tests are enabled but the file that
 * ends up on disk still lacks the Browser testsuite. That is reachable whenever the wrapped
 * StubFile's write is a no-op or fails: an existing file kept on a declined overwrite prompt, a
 * headless run without --force, a failed write, or --check itself (which never writes at all) —
 * so the check has to read whatever is actually on disk after the write attempt, in both drift()
 * and apply(), the same way the original pest() ran it unconditionally after write().
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

    /**
     * Drained eagerly, and the trailing warning checked, before anything is yielded: a caller that
     * only pulls the first result (as first() does) must still see the warning, the same way
     * ArtisanShim drains its wrapped StubFile before warning.
     *
     * @return iterable<Result>
     */
    public function drift(Context $context): iterable
    {
        $results = iterator_to_array($this->stub->drift($context), false);

        $this->warnIfBrowserSuiteMissing($context);

        yield from $results;
    }

    /** @return iterable<Result> */
    public function apply(Context $context): iterable
    {
        $results = iterator_to_array($this->stub->apply($context), false);

        $this->warnIfBrowserSuiteMissing($context);

        yield from $results;
    }

    private function warnIfBrowserSuiteMissing(Context $context): void
    {
        if ($this->browser && ! str_contains((string) @file_get_contents($context->path('phpunit.xml.dist')), 'name="Browser"')) {
            $context->warn('phpunit.xml.dist does not include the Browser testsuite — add it by hand.');
        }
    }
}
