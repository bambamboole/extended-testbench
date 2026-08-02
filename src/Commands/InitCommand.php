<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Composer;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
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

    /** @var array<int, string> */
    private const array GITIGNORE_ENTRIES = [
        '/vendor/',
        '/composer.lock',
        '/.phpunit.cache/',
        '/CLAUDE.md',
        '/AGENTS.md',
        '/.mcp.json',
        '/.claude/skills/',
        '/.agents/',
        '/.junie/',
    ];

    protected $signature = 'package:init
        {--defaults : Accept every default without prompting}
        {--workbench : Scaffold a workbench app}
        {--no-workbench : Skip the workbench app}
        {--browser : Add browser tests}
        {--no-browser : Skip browser tests}
        {--playwright : Install Playwright browsers}
        {--no-playwright : Skip installing Playwright browsers}
        {--phpstan : Add PHPStan (Larastan)}
        {--no-phpstan : Skip PHPStan}
        {--phpstan-level=6 : The PHPStan level to write}
        {--rector : Add Rector}
        {--no-rector : Skip Rector}
        {--pint : Add Pint}
        {--no-pint : Skip Pint}';

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
        if (! $this->input->isInteractive() && ! $this->option('defaults') && ! $this->hasSectionFlag()) {
            error('package:init needs an interactive terminal, --defaults, or explicit section flags.');
            note('Flags: --workbench --browser --playwright --phpstan --phpstan-level=6 --rector --pint, and --no-* for each.');

            return self::FAILURE;
        }

        intro('extended-testbench: package init');

        $workbench = $this->resolve('workbench', 'Add a workbench app?', false);

        $browser = $this->resolve('browser', 'Add browser tests?', false);
        $playwright = $browser && $this->resolve('playwright', 'Install Playwright browsers now?', false);

        $phpstan = $this->resolve('phpstan', 'Add PHPStan (Larastan)?', true);
        $level = $this->phpstanLevel($phpstan);

        $rector = $this->resolve('rector', 'Add Rector?', true);
        $pint = $this->resolve('pint', 'Add Pint?', true);

        $this->write('artisan', 'artisan.stub', onlyIfMissing: true);
        $this->gitignore();

        $this->pest($browser, $workbench);

        if ($workbench) {
            $this->workbench();
        }

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

        $this->script('check', array_values(array_filter([
            $pint ? 'pint --test' : null,
            $phpstan ? 'phpstan analyse' : null,
            $rector ? 'rector --dry-run' : null,
            'pest',
        ])));

        if ($this->autoloadChanged) {
            $this->composer->dumpAutoloads();
        }

        $this->boost();

        table(['File', 'Result'], $this->results);

        if ($this->failedInstalls !== []) {
            error('Failed to install: '.implode(', ', $this->failedInstalls));
        }

        outro('Done.');

        return $this->failedInstalls === [] ? self::SUCCESS : self::FAILURE;
    }

    private function resolve(string $name, string $question, bool $default): bool
    {
        if ($this->option($name) === true) {
            return true;
        }

        if ($this->option('no-'.$name) === true) {
            return false;
        }

        return $this->input->isInteractive()
            ? confirm($question, default: $default)
            : $default;
    }

    private function hasSectionFlag(): bool
    {
        return array_any(['workbench', 'browser', 'playwright', 'phpstan', 'rector', 'pint'], fn (string $section): bool => $this->option($section) === true || $this->option('no-'.$section) === true);
    }

    private function phpstanLevel(bool $phpstan): string
    {
        if (! $phpstan) {
            return '6';
        }

        if ($this->input->hasParameterOption('--phpstan-level')) {
            return (string) $this->option('phpstan-level');
        }

        return $this->input->isInteractive()
            ? select('PHPStan level', ['5', '6', '7', '8', '9', 'max'], default: '6')
            : '6';
    }

    private function pest(bool $browser, bool $workbench): void
    {
        $this->install(['pestphp/pest:^5.0', 'pestphp/pest-plugin-laravel:^5.0']);

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
            'workbench' => $workbench ? self::WORKBENCH_BLOCK : '',
        ], onlyIfMissing: true);

        $this->script('test', 'pest');
    }

    /**
     * Namespaces, directories and the composer autoload-dev entries a workbench app needs are
     * Testbench's own job — workbench:devtool writes them, and it resolves package_path() itself.
     */
    private function workbench(): void
    {
        $binary = $this->root.'/vendor/bin/testbench';

        if (! is_file($binary)) {
            $this->results[] = ['workbench:devtool', 'skipped (no vendor/bin/testbench)'];
            note('Run vendor/bin/testbench workbench:devtool to finish the workbench setup.');

            return;
        }

        $process = new Process(
            [PHP_BINARY, 'vendor/bin/testbench', 'workbench:devtool', '--no-interaction'],
            $this->root,
            timeout: null,
        );

        $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));

        $this->results[] = ['workbench:devtool', $process->isSuccessful() ? 'ran' : 'failed'];
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

        $this->write('phpstan.neon.dist', 'phpstan.neon.dist.stub', [
            'level' => $level,
            'workbench_path' => $this->hasWorkbench() ? "\n        - workbench/app" : '',
        ]);

        $this->script('stan', 'phpstan analyse');
    }

    private function rector(): void
    {
        $this->install(['rector/rector:^2.0']);

        $this->write('rector.php', 'rector.php.stub', [
            'workbench_path' => $this->hasWorkbench() ? ", __DIR__.'/workbench/app'" : '',
        ]);

        $this->script('refactor', 'rector');
    }

    private function hasWorkbench(): bool
    {
        return is_dir($this->root.'/workbench/app');
    }

    private function gitignore(): void
    {
        $path = $this->root.'/.gitignore';
        $contents = file_exists($path) ? (string) @file_get_contents($path) : '';
        $present = array_map(trim(...), preg_split('/\R/', $contents) ?: []);
        $missing = array_values(array_diff(self::GITIGNORE_ENTRIES, $present));

        if ($missing === []) {
            $this->results[] = ['.gitignore', 'skipped (nothing to add)'];

            return;
        }

        $prefix = $contents === '' ? '' : rtrim($contents, "\n")."\n";

        if (@file_put_contents($path, $prefix.implode("\n", $missing)."\n") === false) {
            $this->results[] = ['.gitignore', 'failed'];

            return;
        }

        $this->results[] = ['.gitignore', $contents === '' ? 'written' : 'updated'];
    }

    private function pint(): void
    {
        $this->install(['laravel/pint:^1.16']);

        $this->write('pint.json', 'pint.json.stub');

        $this->script('lint', 'pint --format agent');
    }

    private function boost(): void
    {
        $command = $this->boostCommand();
        $label = implode(' ', $command);

        if (! is_file($this->root.'/vendor/bin/testbench')) {
            $this->results[] = [$label, 'skipped (no vendor/bin/testbench)'];
            note("Run vendor/bin/testbench {$label} to compose the guidelines.");

            return;
        }

        if (Process::isTtySupported()) {
            $process = new Process([PHP_BINARY, 'vendor/bin/testbench', ...$command], $this->root, timeout: null);
            $process->setTty(true);
            $process->run();
        } else {
            $process = new Process([PHP_BINARY, 'vendor/bin/testbench', ...$command, '--no-interaction'], $this->root, timeout: null);
            $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));
        }

        if (! $process->isSuccessful()) {
            note("Boost's commands are only registered in a local environment. Add APP_ENV=local to the env section of testbench.yaml, then run vendor/bin/testbench {$label} yourself.");
        }

        $this->results[] = [$label, $process->isSuccessful() ? 'ran' : 'failed'];
    }

    /** @return array<int, string> */
    private function boostCommand(): array
    {
        return file_exists($this->root.'/boost.json')
            ? ['boost:update', '--discover']
            : ['boost:install'];
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

    /**
     * @param  string|array<int, string>  $command
     */
    private function script(string $name, string|array $command): void
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
