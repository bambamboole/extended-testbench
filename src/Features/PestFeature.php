<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

use Bambamboole\ExtendedTestbench\Features\Artifacts\AutoloadEntry;
use Bambamboole\ExtendedTestbench\Features\Artifacts\NeedsPackage;
use Bambamboole\ExtendedTestbench\Features\Artifacts\PhpunitConfig;
use Bambamboole\ExtendedTestbench\Features\Artifacts\Script;
use Bambamboole\ExtendedTestbench\Features\Artifacts\StubFile;
use Bambamboole\ExtendedTestbench\Features\Artifacts\TestDirectory;

final readonly class PestFeature implements Feature
{
    private const string WORKBENCH_BLOCK = <<<'YAML'

        workbench:
          start: '/'
          welcome: false
          discovers:
            web: true
          build:
            - create-sqlite-db
            - migrate:fresh
        YAML;

    public function flag(): ?Flag
    {
        return null;
    }

    /** @return iterable<Artifact> */
    public function artifacts(Context $context): iterable
    {
        yield new NeedsPackage('pestphp/pest:^5.0', 'pestphp/pest-plugin-laravel:^5.0');

        yield new TestDirectory('tests/Unit');
        yield new TestDirectory('tests/Feature');

        yield new PhpunitConfig($context->enabled('browser'));

        // testNamespace() resolves (and, if missing, registers) the autoload-dev entry the moment
        // it is first called — which the original did while building tests/TestCase.php's
        // replacements. Its row, when unsatisfied, has to land here to match.
        yield new AutoloadEntry('Tests\\', 'tests/');

        yield new StubFile('tests/TestCase.php', 'TestCase.php.stub', [
            'namespace' => rtrim($context->testNamespace(), '\\'),
            'providers' => implode(', ', array_map(
                static fn (string $provider): string => '\\'.ltrim($provider, '\\').'::class',
                $context->providers(),
            )),
        ], onlyIfMissing: true);

        yield new StubFile('tests/Pest.php', 'Pest.php.stub', [
            'test_case' => '\\'.$context->testNamespace().'TestCase',
            'suites' => "'Feature', 'Unit'",
        ], onlyIfMissing: true);

        yield new StubFile('testbench.yaml', 'testbench.yaml.stub', [
            'providers' => $context->providers() === [] ? '' : "\nproviders:\n".implode("\n", array_map(
                static fn (string $provider): string => '  - '.ltrim($provider, '\\'),
                $context->providers(),
            ))."\n",
            'workbench' => $context->enabled('workbench') ? self::WORKBENCH_BLOCK : '',
        ], onlyIfMissing: true);

        yield new Script('test', $context->enabled('browser') ? 'pest --testsuite=Unit,Feature' : 'pest');
    }
}
