<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Composer;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;

final class InitCommand extends Command
{
    private const BROWSER_TESTSUITE = <<<'XML'


        <testsuite name="Browser">
            <directory>tests/Browser</directory>
        </testsuite>
    XML;

    protected $signature = 'package:init';

    protected $description = 'Scaffold Pest, static analysis and formatting for this package';

    /** @var array<int, array{0: string, 1: string}> */
    private array $results = [];

    private ?string $testNamespace = null;

    private bool $autoloadChanged = false;

    public function __construct(
        private readonly Composer $composer,
        private readonly string $root,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        intro('extended-testbench: package init');

        $browser = confirm('Add browser tests?', default: false);

        $this->pest($browser);

        if ($this->autoloadChanged) {
            $this->composer->dumpAutoloads();
        }

        table(['File', 'Result'], $this->results);

        outro('Done.');

        return self::SUCCESS;
    }

    private function pest(bool $browser): void
    {
        $this->install(['pestphp/pest:^5.0']);

        $this->write('phpunit.xml.dist', 'phpunit.xml.dist.stub', [
            'app_key' => 'base64:'.base64_encode(random_bytes(32)),
            'browser_testsuite' => $browser ? self::BROWSER_TESTSUITE : '',
        ]);

        $this->write('tests/TestCase.php', 'TestCase.php.stub', [
            'namespace' => rtrim($this->testNamespace(), '\\'),
            'providers' => implode(', ', array_map(
                static fn (string $provider): string => '\\'.ltrim($provider, '\\').'::class',
                $this->providers(),
            )),
        ], onlyIfMissing: true);

        $this->write('tests/Pest.php', 'Pest.php.stub', [
            'test_case' => '\\'.$this->testNamespace().'TestCase',
            'suites' => $browser ? "'Feature', 'Unit', 'Browser'" : "'Feature', 'Unit'",
        ], onlyIfMissing: true);

        $this->write('testbench.yaml', 'testbench.yaml.stub', [
            'providers' => $this->providers() === [] ? '' : "\nproviders:\n".implode("\n", array_map(
                static fn (string $provider): string => '  - '.ltrim($provider, '\\'),
                $this->providers(),
            ))."\n",
        ], onlyIfMissing: true);

        $this->script('test', 'pest');
    }

    /** @param  array<int, string>  $packages */
    private function install(array $packages): void
    {
        $missing = array_values(array_filter(
            $packages,
            fn (string $package): bool => ! $this->composer->hasPackage(explode(':', $package)[0]),
        ));

        if ($missing === []) {
            return;
        }

        $this->composer->requirePackages($missing, dev: true, output: $this->output);
    }

    /** @param  array<string, string>  $replacements */
    private function write(string $path, string $stub, array $replacements = [], bool $onlyIfMissing = false): void
    {
        $target = $this->root.'/'.$path;

        if (file_exists($target)) {
            if ($onlyIfMissing) {
                $this->results[] = [$path, 'skipped (exists)'];

                return;
            }

            if (! confirm("Overwrite {$path}?", default: false)) {
                $this->results[] = [$path, 'skipped'];

                return;
            }
        }

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, recursive: true);
        }

        file_put_contents($target, $this->render($stub, $replacements));

        $this->results[] = [$path, 'written'];
    }

    /** @param  array<string, string>  $replacements */
    private function render(string $stub, array $replacements): string
    {
        $contents = (string) file_get_contents(__DIR__.'/../../stubs/'.$stub);

        foreach ($replacements as $key => $value) {
            $contents = str_replace('{{ '.$key.' }}', $value, $contents);
        }

        return $contents;
    }

    private function script(string $name, string $command): void
    {
        if (isset($this->composerJson()['scripts'][$name])) {
            return;
        }

        $this->composer->modify(static function (array $composer) use ($name, $command): array {
            $composer['scripts'][$name] = $command;

            return $composer;
        });

        $this->results[] = ["composer script: {$name}", 'added'];
    }

    /** @return array<string, mixed> */
    private function composerJson(): array
    {
        return (array) json_decode((string) file_get_contents($this->root.'/composer.json'), true);
    }

    /** @return array<int, string> */
    private function providers(): array
    {
        return array_values((array) ($this->composerJson()['extra']['laravel']['providers'] ?? []));
    }

    private function testNamespace(): string
    {
        if ($this->testNamespace !== null) {
            return $this->testNamespace;
        }

        foreach ((array) ($this->composerJson()['autoload-dev']['psr-4'] ?? []) as $namespace => $path) {
            if (rtrim((string) $path, '/') === 'tests') {
                return $this->testNamespace = (string) $namespace;
            }
        }

        $this->composer->modify(static function (array $composer): array {
            $composer['autoload-dev']['psr-4']['Tests\\'] = 'tests/';

            return $composer;
        });

        $this->autoloadChanged = true;

        return $this->testNamespace = 'Tests\\';
    }
}
