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
        '/.codex/',
        '/.superpowers/',
        '/docs/superpowers/',
    ];

    protected $signature = 'package:init
        {--check : Report how this package diverges from the scaffold and write nothing}
        {--defaults : Accept every default without prompting}
        {--force : Replace the generated config files instead of skipping the ones that exist}
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

    /**
     * Whether this run only reports drift. Every mutation in this command is gated on it: no writes,
     * no `composer require`, no composer.json edits, and none of the subprocesses.
     */
    private function checking(): bool
    {
        return $this->option('check') === true;
    }

    public function handle(): int
    {
        // The command is a container singleton and Artisan caches the instance it resolves, so a
        // second invocation in the same process (a --check after an init, anything programmatic)
        // would otherwise report the first run's rows on top of its own.
        $this->results = [];
        $this->failedInstalls = [];
        $this->testNamespace = null;
        $this->autoloadChanged = false;

        if (! $this->canPrompt() && ! $this->option('defaults') && ! $this->checking() && ! $this->hasSectionFlag()) {
            error('package:init needs an interactive terminal, --defaults, or explicit section flags.');
            note('Flags: --workbench --browser --playwright --phpstan --rector --pint, and --no-* for each.');

            return self::FAILURE;
        }

        if ($this->input->hasParameterOption('--phpstan-level') && ! in_array((string) $this->option('phpstan-level'), ['5', '6', '7', '8', '9', 'max'], true)) {
            error('--phpstan-level must be one of: 5, 6, 7, 8, 9, max.');

            return self::FAILURE;
        }

        intro($this->checking() ? 'extended-testbench: package drift check' : 'extended-testbench: package init');

        $workbench = $this->resolve('workbench', 'Add a workbench app?', false);

        $browser = $this->resolve('browser', 'Add browser tests?', false);
        $playwright = $browser && $this->resolve('playwright', 'Install Playwright browsers now?', false);

        if ($this->option('playwright') === true && ! $playwright) {
            warning('--playwright has no effect because browser tests resolved false. Pass --browser as well.');
        }

        $phpstan = $this->resolve('phpstan', 'Add PHPStan (Larastan)?', true);
        $level = $this->phpstanLevel($phpstan);

        $rector = $this->resolve('rector', 'Add Rector?', true);
        $pint = $this->resolve('pint', 'Add Pint?', true);

        $this->artisan();
        $this->write('.gitattributes', 'gitattributes.stub', onlyIfMissing: true);
        $this->write('.github/workflows/ci.yml', 'ci.yml.stub', onlyIfMissing: true);

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
            '@test',
        ])));

        $this->script('post-autoload-dump', [
            '@php vendor/bin/testbench package:purge-skeleton --ansi',
            '@php vendor/bin/testbench package:discover --ansi',
        ]);

        $this->script('boost:refresh', '[ -n "$CI" ] || [ ! -f vendor/bin/testbench ] || [ ! -f boost.json ] || vendor/bin/testbench boost:update --no-interaction || true');
        $this->script('post-install-cmd', ['@boost:refresh']);
        $this->script('post-update-cmd', ['@boost:refresh']);

        if ($this->autoloadChanged) {
            $this->composer->dumpAutoloads();
        }

        $this->boost();

        // Registration has to follow boost(), because boost:install is what creates boost.json in
        // the first place — but Boost composes the guidelines during that same run, before our name
        // is in the packages key. Without a second pass the guideline is registered and never
        // composed, which headless callers cannot fix themselves: boost:update only discovers new
        // packages behind an interactive multiselect, so a non-interactive run silently keeps the
        // packages key it already had.
        if ($this->registerGuideline()) {
            $this->composeGuideline();
        }

        if ($this->checking()) {
            $this->applyCheckIgnores();
        }

        table($this->checking() ? ['File', 'Drift'] : ['File', 'Result'], $this->results);

        if ($this->checking()) {
            return $this->reportDrift();
        }

        if ($this->failedInstalls !== []) {
            error('Failed to install: '.implode(', ', $this->failedInstalls));
        }

        outro('Done.');

        return $this->failedInstalls === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * A drift report that cannot be silenced is unusable as a CI gate: a package legitimately has no
     * tests/Unit, or a stricter phpunit.xml.dist, and the only route to green would be degrading
     * both. Rows named in composer.json's extra.extended-testbench.check-ignore stay visible but
     * stop counting, so the exit code tracks unintended drift only.
     */
    private function applyCheckIgnores(): void
    {
        $ignored = array_map(
            static fn (mixed $entry): string => (string) $entry,
            array_values((array) ($this->composerJson()['extra']['extended-testbench']['check-ignore'] ?? [])),
        );

        if ($ignored === []) {
            return;
        }

        foreach ($this->results as $index => $row) {
            if ($row[1] !== 'ok' && in_array($row[0], $ignored, true)) {
                $this->results[$index] = [$row[0], "ignored ({$row[1]})"];
            }
        }
    }

    private function reportDrift(): int
    {
        $drifted = array_values(array_filter(
            $this->results,
            static fn (array $row): bool => $row[1] !== 'ok' && ! str_starts_with($row[1], 'ignored'),
        ));

        if ($drifted === []) {
            outro('No drift: this package matches the scaffold.');

            return self::SUCCESS;
        }

        warning(sprintf('%d of %d checks diverge from the scaffold. Nothing was written.', count($drifted), count($this->results)));

        foreach ($drifted as $row) {
            note("{$row[0]}: {$row[1]}");
        }

        note('Run package:init without --check to scaffold what is missing, adding --force to replace the generated configs that differ.');

        return self::FAILURE;
    }

    /**
     * Replaces a symlinked entrypoint with the committed shim. write()'s onlyIfMissing would skip a
     * link that still resolves, and that is the common case: the widespread
     * `ln -s vendor/bin/testbench artisan` recipe works locally but breaks on a fresh clone and on
     * Windows. A link pointing anywhere else is the user's own and only gets a warning.
     */
    private function artisan(): void
    {
        $path = $this->root.'/artisan';
        $binary = realpath($this->root.'/vendor/bin/testbench');
        $symlinked = is_link($path) && $binary !== false && realpath($path) === $binary;

        if ($symlinked && $this->checking()) {
            $this->results[] = ['artisan', 'differs (symlink, not the committed shim)'];

            return;
        }

        if ($symlinked) {
            @unlink($path);
            note('artisan was a symlink to vendor/bin/testbench; replacing it with the committed shim, which survives a fresh clone and works on Windows.');
        }

        $this->write('artisan', 'artisan.stub', onlyIfMissing: true);

        if (is_link($path)) {
            warning('artisan is a symlink to something other than vendor/bin/testbench, so it was left alone. Replace it with `rm artisan` and rerun if you want the committed shim.');
        }
    }

    private function resolve(string $name, string $question, bool $default): bool
    {
        if ($this->option($name) === true) {
            return true;
        }

        if ($this->option('no-'.$name) === true) {
            return false;
        }

        // --check answers for itself: a drift report that stopped to ask six questions would be
        // useless to the CI job and the agent it exists for. Section flags still narrow it.
        if ($this->option('defaults') === true || $this->checking()) {
            return $default;
        }

        return $this->canPrompt()
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

        if ($this->option('defaults') === true || $this->checking()) {
            return '6';
        }

        return $this->canPrompt()
            ? select('PHPStan level', ['5', '6', '7', '8', '9', 'max'], default: '6')
            : '6';
    }

    /**
     * Whether a prompt will actually reach a user. `$this->input->isInteractive()` alone is not
     * enough: Symfony only flips it false when `--no-interaction` is passed explicitly, so a truly
     * headless caller (a shell script, CI, an agent) that omits the flag would otherwise sail past
     * here and every confirm()/select() would silently fall back to its default. Mirrors the real
     * check Laravel's own ConfiguresPrompts::configurePrompts() uses, minus its runningUnitTests()
     * fallback — keeping that would make this always true in the test suite and defeat the guard.
     */
    private function canPrompt(): bool
    {
        if (! $this->input->isInteractive()) {
            return false;
        }

        return $this->laravel->runningUnitTests() || (defined('STDIN') && stream_isatty(STDIN));
    }

    private function pest(bool $browser, bool $workbench): void
    {
        $this->install(['pestphp/pest:^5.0', 'pestphp/pest-plugin-laravel:^5.0']);

        $this->testDirectory('tests/Unit');
        $this->testDirectory('tests/Feature');

        $this->write('phpunit.xml.dist', 'phpunit.xml.dist.stub', [
            'browser_testsuite' => $browser ? self::BROWSER_TESTSUITE : '',
        ]);

        $this->warnIfShadowed('phpunit.xml.dist');

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
            'suites' => "'Feature', 'Unit'",
        ], onlyIfMissing: true);

        $this->write('testbench.yaml', 'testbench.yaml.stub', [
            'providers' => $this->providers() === [] ? '' : "\nproviders:\n".implode("\n", array_map(
                static fn (string $provider): string => '  - '.ltrim($provider, '\\'),
                $this->providers(),
            ))."\n",
            'workbench' => $workbench ? self::WORKBENCH_BLOCK : '',
        ], onlyIfMissing: true);

        $this->script('test', $browser ? 'pest --testsuite=Unit,Feature' : 'pest');
    }

    /**
     * Namespaces, directories and the composer autoload-dev entries a workbench app needs are
     * Testbench's own job — workbench:devtool writes them, and it resolves package_path() itself.
     */
    private function workbench(): void
    {
        $binary = $this->root.'/vendor/bin/testbench';

        if ($this->checking()) {
            $this->results[] = ['workbench/app', $this->hasWorkbench() ? 'ok' : 'missing'];

            return;
        }

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

        $this->write('tests/BrowserTestCase.php', 'BrowserTestCase.php.stub', [
            'namespace' => rtrim($this->testNamespace(), '\\'),
        ], onlyIfMissing: true);

        $this->write('tests/Browser/DummyTest.php', 'BrowserDummyTest.php.stub');

        $this->script('test:browser', file_exists($this->root.'/package.json')
            ? ['npm run build', 'pest --testsuite=Browser']
            : 'pest --testsuite=Browser');

        $pest = $this->root.'/tests/Pest.php';

        if (! file_exists($pest)) {
            return;
        }

        $contents = (string) file_get_contents($pest);

        if ($this->checking()) {
            $this->results[] = ['tests/Pest.php: Browser suite', str_contains($contents, "'Browser'") ? 'ok' : 'missing'];

            return;
        }

        if (str_contains($contents, "'Browser'")) {
            if (! str_contains($contents, 'BrowserTestCase')) {
                warning("tests/Pest.php already maps 'Browser' to the base TestCase. Change that line to uses(\\{$this->testNamespace()}BrowserTestCase::class)->in('Browser'); so the Vite guard runs.");
            }

            return;
        }

        file_put_contents(
            $pest,
            sprintf("\nuses(\\%sBrowserTestCase::class)->in('Browser');\n", $this->testNamespace()),
            FILE_APPEND,
        );

        $this->results[] = ['tests/Pest.php', 'browser suite appended'];
    }

    private function playwright(): void
    {
        if ($this->checking()) {
            return;
        }

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
            'database_path' => $this->hasDatabase() ? "\n        - database" : '',
        ]);

        $this->warnIfShadowed('phpstan.neon.dist');

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

    private function hasDatabase(): bool
    {
        return is_dir($this->root.'/database');
    }

    private function gitignore(): void
    {
        $path = $this->root.'/.gitignore';
        $contents = file_exists($path) ? (string) @file_get_contents($path) : '';

        // Both slashes go: git treats `vendor`, `/vendor` and `vendor/` as the same intent here, and
        // keeping either one would append a duplicate entry next to the line already ignoring it.
        $normalise = static fn (string $line): string => trim(trim($line), '/');

        $present = array_map($normalise, preg_split('/\R/', $contents) ?: []);

        // An entry is covered by any ancestor already listed: `.claude` ignores `.claude/skills`
        // wholesale, and appending the nested line would be redundant rather than a fix.
        $covered = static function (string $entry) use ($normalise, $present): bool {
            for ($path = $normalise($entry); $path !== '' && $path !== '.'; $path = dirname($path)) {
                if (in_array($path, $present, true)) {
                    return true;
                }

                if (dirname($path) === $path) {
                    break;
                }
            }

            return false;
        };

        $missing = array_values(array_filter(
            self::GITIGNORE_ENTRIES,
            static fn (string $entry): bool => ! $covered($entry),
        ));

        if ($this->checking()) {
            $this->results[] = ['.gitignore', $missing === [] ? 'ok' : 'missing '.count($missing).' entries: '.implode(' ', $missing)];

            return;
        }

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
        if ($this->checking()) {
            return;
        }

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

    /** @return bool Whether the package was newly added and the guidelines need composing again. */
    private function registerGuideline(): bool
    {
        $path = $this->root.'/boost.json';

        if (! file_exists($path)) {
            if ($this->checking()) {
                $this->results[] = ['boost.json', 'missing'];
            }

            return false;
        }

        $config = json_decode((string) @file_get_contents($path), true);

        if (! is_array($config)) {
            $this->results[] = ['boost.json', $this->checking() ? 'unreadable' : 'failed (unreadable)'];

            return false;
        }

        $packages = is_array($config['packages'] ?? null) ? $config['packages'] : [];
        $registered = in_array('bambamboole/extended-testbench', $packages, true);

        if ($this->checking()) {
            $this->results[] = ['boost.json: packages', $registered ? 'ok' : 'missing'];

            return false;
        }

        if ($registered) {
            return false;
        }

        $packages[] = 'bambamboole/extended-testbench';
        $config['packages'] = $packages;

        ksort($config);

        if (@file_put_contents($path, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL) === false) {
            $this->results[] = ['boost.json', 'failed'];

            return false;
        }

        $this->results[] = ['boost.json', 'registered guideline'];

        return true;
    }

    private function composeGuideline(): void
    {
        if (! is_file($this->root.'/vendor/bin/testbench')) {
            note('Run vendor/bin/testbench boost:update to compose this guideline into CLAUDE.md / AGENTS.md.');

            return;
        }

        $process = new Process(
            [PHP_BINARY, 'vendor/bin/testbench', 'boost:update', '--no-interaction'],
            $this->root,
            timeout: null,
        );

        $process->run(fn (string $type, string $buffer) => $this->output->write($buffer));

        if (! $process->isSuccessful()) {
            note('Run vendor/bin/testbench boost:update to compose this guideline into CLAUDE.md / AGENTS.md.');
        }

        $this->results[] = ['boost:update', $process->isSuccessful() ? 'composed guideline' : 'failed'];
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

        if ($this->checking()) {
            $this->results[] = [$path, is_dir($dir) ? 'ok' : 'missing'];

            return;
        }

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

        if ($this->checking()) {
            foreach ($missing as $package) {
                $this->results[] = [$package, 'missing'];
            }

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
        $rendered = $this->render($stub, $replacements);

        if ($this->checking()) {
            $this->results[] = [$path, $this->drift($target, $path, $rendered, $onlyIfMissing)];

            return;
        }

        // A dangling symlink makes file_exists() report false, so onlyIfMissing would not trip and
        // file_put_contents() would write through the link, creating whatever it pointed at.
        if (is_link($target) && ! file_exists($target)) {
            @unlink($target);
        }

        $existed = file_exists($target);

        if ($existed) {
            if ($onlyIfMissing) {
                $this->results[] = [$path, 'skipped (exists)'];

                return;
            }

            // Only the generated config files reach this branch; anything holding hand-written code
            // is written with onlyIfMissing above and stays out of --force's reach. Without a real
            // prompt the answer is no, so a headless run reports the skip instead of asking nobody.
            $overwrite = $this->option('force') === true;

            if (! $overwrite && $this->canPrompt()) {
                $this->diff($target, $path, $rendered);

                $overwrite = confirm("Overwrite {$path}?", default: false);
            }

            if (! $overwrite) {
                $this->results[] = [$path, 'skipped (exists, --force to replace)'];

                return;
            }
        }

        if (! is_dir(dirname($target)) && ! @mkdir(dirname($target), 0755, recursive: true)) {
            $this->results[] = [$path, 'failed'];

            return;
        }

        if (@file_put_contents($target, $rendered) === false) {
            $this->results[] = [$path, 'failed'];

            return;
        }

        $this->results[] = [$path, $existed ? 'overwritten' : 'written'];
    }

    /**
     * A file written with onlyIfMissing holds hand-written code, so only its absence is drift —
     * comparing its body against the stub would report every package that has ever edited its own
     * TestCase. For the generated configs the body is the whole point, so those are compared.
     */
    private function drift(string $target, string $path, string $rendered, bool $onlyIfMissing): string
    {
        if (! file_exists($target)) {
            return 'missing';
        }

        // Whitespace-insensitive: a package that wraps withPaths([...]) across lines, or indents its
        // neon differently, has not diverged from the scaffold in any way it can act on. Key order
        // still reads as drift — parsing four config languages to normalise that is not worth it;
        // baseline it with extra.extended-testbench.check-ignore instead.
        $matches = static fn (string $a, string $b): bool => preg_replace('/\s+/', '', $a) === preg_replace('/\s+/', '', $b);

        if ($onlyIfMissing || $matches((string) @file_get_contents($target), $rendered)) {
            return 'ok';
        }

        $this->diff($target, $path, $rendered);

        return 'differs';
    }

    /**
     * ponytail: shells out to POSIX `diff`, so the body of the drift is invisible on a Windows box
     * without one. The row still reports `differs` there; swap in a PHP differ if that matters.
     */
    private function diff(string $target, string $path, string $rendered): void
    {
        note("{$path} differs from the scaffold:");

        // No --label: it is GNU-only, and an unsupported flag would cost the whole diff rather than
        // just its header. `-` is the scaffold arriving on stdin.
        $process = new Process(['diff', '-u', $target, '-'], $this->root);
        $process->setInput($rendered);

        try {
            $process->run();
        } catch (\Throwable) {
            return;
        }

        $output = trim($process->getOutput());

        if ($output !== '') {
            $this->output->writeln($output);
        }
    }

    /**
     * Warns when a legacy config next to a generated `.dist` file shadows it — both PHPUnit and
     * PHPStan prefer the non-`.dist` name, so the scaffold would be silently ignored. Policy is
     * warn only: no rename, no prompt. The warning fires regardless of the write outcome, but the
     * preceding write() already pushed a row for $distPath, so it's only rewritten in place (never
     * a second row) and only when the write actually succeeded — a failed or skipped write keeps
     * its true row, so the summary never claims a file was written when it was not.
     */
    private function warnIfShadowed(string $distPath): void
    {
        $legacy = str_replace('.dist', '', $distPath);

        if (! file_exists($this->root.'/'.$legacy)) {
            return;
        }

        warning("{$legacy} already exists and takes precedence over {$distPath}, so the generated file will be ignored. Rename it with `git mv {$legacy} {$distPath}` if you want the scaffold to apply.");

        $last = array_key_last($this->results);

        if ($last === null || $this->results[$last][0] !== $distPath) {
            return;
        }

        if (in_array($this->results[$last][1], ['written', 'overwritten'], true)) {
            $this->results[$last] = [$distPath, "written (shadowed by {$legacy})"];
        }
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
        $scripts = (array) ($this->composerJson()['scripts'] ?? []);

        if (isset($scripts[$name])) {
            if ($this->checking()) {
                // Existence by name is not enough: a `check` wired to an entirely different
                // pipeline would report ok next to the `stan` it never runs.
                $this->results[] = ["composer script: {$name}", $scripts[$name] === $command ? 'ok' : 'differs'];
            }

            return;
        }

        $this->warnAboutRenamedScript($name, $command, $scripts);

        if ($this->checking()) {
            $this->results[] = ["composer script: {$name}", 'missing'];

            return;
        }

        $this->composer->modify(static function (array $composer) use ($name, $command): array {
            $composer['scripts'][$name] = $command;

            return $composer;
        });

        $this->results[] = ["composer script: {$name}", 'added'];
    }

    /**
     * script() only ever matched on the name, so a package that already runs the same tool under its
     * own name (`analyse` for our `stan`, `lint:fix` for our `lint`) silently ended up with both,
     * and a `check` wired to whichever one we scaffolded. We still add ours — renaming someone's
     * scripts and the CI that calls them is not ours to do — but the collision gets said out loud.
     *
     * @param  string|array<int, string>  $command
     * @param  array<string, mixed>  $scripts
     */
    private function warnAboutRenamedScript(string $name, string|array $command, array $scripts): void
    {
        if (is_array($command)) {
            return;
        }

        // Compare basenames: a package running `./vendor/bin/phpstan` is running the same tool as our
        // bare `phpstan`, and a raw first-token comparison saw two different strings and stayed
        // quiet. `@php vendor/bin/x` hides the tool behind the runner, so that prefix is dropped.
        $tool = static function (mixed $value): string {
            $first = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            $tokens = array_values(array_filter(explode(' ', trim($first)), static fn (string $token): bool => $token !== ''));

            if (($tokens[0] ?? '') === '@php') {
                array_shift($tokens);
            }

            return basename($tokens[0] ?? '');
        };

        $ours = $tool($command);

        if ($ours === '') {
            return;
        }

        foreach ($scripts as $existing => $existingCommand) {
            if ($tool($existingCommand) === $ours) {
                warning("composer script '{$existing}' already runs {$ours}; adding '{$name}' alongside it. The generated `check` script inlines `{$command}` rather than calling either one, so drop whichever of the two you do not want.");

                return;
            }
        }
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

        if ($this->checking()) {
            $this->results[] = ['composer autoload-dev: Tests\\', 'missing'];

            return $this->testNamespace = 'Tests\\';
        }

        $this->composer->modify(static function (array $composer): array {
            $composer['autoload-dev']['psr-4']['Tests\\'] = 'tests/';

            return $composer;
        });

        $this->autoloadChanged = true;

        return $this->testNamespace = 'Tests\\';
    }
}
