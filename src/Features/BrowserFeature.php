<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

use Bambamboole\ExtendedTestbench\Features\Artifacts\NeedsPackage;
use Bambamboole\ExtendedTestbench\Features\Artifacts\PestSuiteLine;
use Bambamboole\ExtendedTestbench\Features\Artifacts\Script;
use Bambamboole\ExtendedTestbench\Features\Artifacts\StubFile;

final readonly class BrowserFeature implements Feature
{
    public function flag(): Flag
    {
        return new Flag('browser', 'Add browser tests?', false);
    }

    /** @return iterable<Artifact> */
    public function artifacts(Context $context): iterable
    {
        yield new NeedsPackage('pestphp/pest-plugin-browser:^5.0');

        yield new StubFile('tests/BrowserTestCase.php', 'BrowserTestCase.php.stub', [
            'namespace' => rtrim($context->testNamespace(), '\\'),
        ], onlyIfMissing: true);

        yield new StubFile('tests/Browser/DummyTest.php', 'BrowserDummyTest.php.stub');

        yield new PestSuiteLine('Browser', $context->testNamespace().'BrowserTestCase');

        yield new Script('test:browser', file_exists($context->path('package.json'))
            ? ['npm run build', 'pest --testsuite=Browser']
            : 'pest --testsuite=Browser');
    }
}
