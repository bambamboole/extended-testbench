<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Composer;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

final class InitCommand extends Command
{
    private const string BROWSER_TESTSUITE = <<<'XML'

            <testsuite name="Browser">
                <directory>tests/Browser</directory>
            </testsuite>
    XML;

    protected $signature = 'package:init';

    protected $description = 'Scaffold Pest, static analysis and formatting for this package';

    /** @var array<int, array{0: string, 1: string}> */
    private array $results = [];

    /** @var array<int, string> */
    private array $failedInstalls = [];

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
        $playwright = $browser && confirm('Install Playwright browsers now?', default: false);

        $phpstan = confirm('Add PHPStan (Larastan)?', default: true);
        $level = $phpstan ? select('PHPStan level', ['5', '6', '7', '8', '9', 'max'], default: '6') : '6';

        $rector = confirm('Add Rector?', default: true);
        $pint = confirm('Add Pint?', default: true);

        $this->pest($browser);

        if ($browser) {
            $this->browser();
        }

        if ($playwright) {
            $this->playwright();
        }

        if ($phpstan) {
            $this->phpstan($level);
        }

        if ($rector) {
            $this->rector();
        }

        if ($pint) {
            $this->pint();
        }

        if ($this->autoloadChanged) {
            $this->composer->dumpAutoloads();
        }

        table(['File', 'Result'], $this->results);

        if ($this->failedInstalls !== []) {
            error('Failed to install: '.implode(', ', $this->failedInstalls));
        }

        outro('Done.');

        return $this->failedInstalls === [] ? self::SUCCESS : self::FAILURE;
    }

    private function pest(bool $browser): void
    {
        $this->install(['pestphp/pest:^5.0']);

        $this->testDirectory('tests/Unit');
        $this->testDirectory('tests/Feature');

        $this->write('phpunit.xml.dist', 'phpunit.xml.dist.stub', [
            'app_key' => 'base64:'.base64_encode(random_bytes(32)),
            'browser_testsuite' => $browser ? self::BROWSER_TESTSUITE : '',
        ]);

        if ($browser && ! str_contains((string) @file_get_contents($this->root.'/phpunit.xml.dist'), 'name="Browser"')) {
            warning('phpunit.xml.dist does not include the Browser testsuite — add it by hand.');
        }

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

    private function browser(): void
    {
        $this->install(['pestphp/pest-plugin-browser:^5.0']);

        $this->write('tests/Browser/DummyTest.php', 'BrowserDummyTest.php.stub');

        $pest = $this->root.'/tests/Pest.php';

        if (! file_exists($pest) || str_contains((string) file_get_contents($pest), "'Browser'")) {
            return;
        }

        file_put_contents(
            $pest,
            sprintf("\nuses(\\%sTestCase::class)->in('Browser');\n", $this->testNamespace()),
            FILE_APPEND,
        );

        $this->results[] = ['tests/Pest.php', 'browser suite appended'];
    }

    private function playwright(): void
    {
        $process = new Process(['npx', 'playwright', 'install'], $this->root, timeout: null);

        $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));

        $this->results[] = ['npx playwright install', $process->isSuccessful() ? 'ran' : 'failed'];
    }

    private function phpstan(string $level): void
    {
        $this->install(['larastan/larastan:^3.0', 'pestphp/pest-plugin-phpstan:^5.0']);

        $this->write('phpstan.neon.dist', 'phpstan.neon.dist.stub', ['level' => $level]);

        $this->script('stan', 'phpstan analyse');
    }

    private function rector(): void
    {
        $this->install(['rector/rector:^2.0']);

        $this->write('rector.php', 'rector.php.stub');

        $this->script('refactor', 'rector');
    }

    private function pint(): void
    {
        $this->install(['laravel/pint:^1.16']);

        $this->write('pint.json', 'pint.json.stub');

        $this->script('lint', 'pint --format agent');
    }

    private function testDirectory(string $path): void
    {
        $dir = $this->root.'/'.$path;

        if (! is_dir($dir) && ! @mkdir($dir, 0755, recursive: true)) {
            $this->results[] = [$path, 'failed'];

            return;
        }

        $gitkeep = $path.'/.gitkeep';

        if (file_exists($this->root.'/'.$gitkeep)) {
            $this->results[] = [$path, 'skipped (exists)'];

            return;
        }

        if (@file_put_contents($this->root.'/'.$gitkeep, '') === false) {
            $this->results[] = [$gitkeep, 'failed'];

            return;
        }

        $this->results[] = [$gitkeep, 'written'];
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

        if ($this->composer->requirePackages($missing, dev: true, output: $this->output)) {
            return;
        }

        foreach ($missing as $package) {
            $this->results[] = [$package, 'failed'];
        }

        $this->failedInstalls = [...$this->failedInstalls, ...$missing];
    }

    /** @param  array<string, string>  $replacements */
    private function write(string $path, string $stub, array $replacements = [], bool $onlyIfMissing = false): void
    {
        $target = $this->root.'/'.$path;
        $existed = file_exists($target);

        if ($existed) {
            if ($onlyIfMissing) {
                $this->results[] = [$path, 'skipped (exists)'];

                return;
            }

            if (! confirm("Overwrite {$path}?", default: false)) {
                $this->results[] = [$path, 'skipped'];

                return;
            }
        }

        if (! is_dir(dirname($target)) && ! @mkdir(dirname($target), 0755, recursive: true)) {
            $this->results[] = [$path, 'failed'];

            return;
        }

        if (@file_put_contents($target, $this->render($stub, $replacements)) === false) {
            $this->results[] = [$path, 'failed'];

            return;
        }

        $this->results[] = [$path, $existed ? 'overwritten' : 'written'];
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
