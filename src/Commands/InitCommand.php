<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Commands;

use Bambamboole\ExtendedTestbench\Features\BoostFeature;
use Bambamboole\ExtendedTestbench\Features\BrowserFeature;
use Bambamboole\ExtendedTestbench\Features\CiFeature;
use Bambamboole\ExtendedTestbench\Features\ComposerScriptsFeature;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\EntrypointFeature;
use Bambamboole\ExtendedTestbench\Features\Feature;
use Bambamboole\ExtendedTestbench\Features\Flag;
use Bambamboole\ExtendedTestbench\Features\GitFeature;
use Bambamboole\ExtendedTestbench\Features\GitignoreFeature;
use Bambamboole\ExtendedTestbench\Features\PestFeature;
use Bambamboole\ExtendedTestbench\Features\PhpstanFeature;
use Bambamboole\ExtendedTestbench\Features\PintFeature;
use Bambamboole\ExtendedTestbench\Features\PlaywrightFeature;
use Bambamboole\ExtendedTestbench\Features\RectorFeature;
use Bambamboole\ExtendedTestbench\Features\Status;
use Bambamboole\ExtendedTestbench\Features\WorkbenchFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Composer;

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
    protected $description = 'Scaffold Pest, static analysis and formatting for this package';

    /** @var array<int, array{0: string, 1: string}> */
    private array $results = [];

    public function __construct(
        private readonly Composer $composer,
        private readonly string $root,
    ) {
        // Every section flag comes from the feature that owns it, so a new feature ships its own
        // --name/--no-name pair rather than needing this string edited alongside it.
        $this->signature = 'package:init
        {--check : Report how this package diverges from the scaffold and write nothing}
        {--defaults : Accept every default without prompting}
        {--force : Replace the generated config files instead of skipping the ones that exist}
        {--phpstan-level=6 : The PHPStan level to write}'.implode('', array_map(
            static fn (Flag $flag): string => "\n        {--{$flag->name} : {$flag->description}}\n        {--no-{$flag->name} : {$flag->skipDescription}}",
            $this->flags(),
        ));

        parent::__construct();
    }

    public function handle(): int
    {
        // The command is a container singleton and Artisan caches the instance it resolves, so a
        // second invocation in the same process (a --check after an init, anything programmatic)
        // would otherwise report the first run's rows on top of its own.
        $this->results = [];

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

        $enabled = [];
        $level = '6';

        foreach ($this->flags() as $flag) {
            // Playwright is gated on browser tests and is not even asked without them: installing
            // browsers nothing will drive is never what the answer meant.
            if ($flag->name === 'playwright' && ! ($enabled['browser'] ?? false)) {
                $enabled['playwright'] = false;

                if ($this->option('playwright') === true) {
                    warning('--playwright has no effect because browser tests resolved false. Pass --browser as well.');
                }

                continue;
            }

            $enabled[$flag->name] = $this->resolve($flag->name, $flag->question, $flag->default);

            // The level question belongs to the phpstan answer, so it is asked right after it
            // rather than after every section has been decided.
            if ($flag->name === 'phpstan') {
                $level = $this->phpstanLevel($enabled['phpstan']);
            }
        }

        $context = new Context(
            root: $this->root,
            composer: $this->composer,
            output: $this->output,
            checking: $this->checking(),
            force: $this->option('force') === true,
            canPrompt: $this->canPrompt(),
            enabled: $enabled,
        );

        $this->scaffold($context, $level);

        if ($context->autoloadChanged()) {
            $this->composer->dumpAutoloads();
        }

        if ($this->checking()) {
            $this->applyCheckIgnores();
        }

        table($this->checking() ? ['File', 'Drift'] : ['File', 'Result'], $this->results);

        if ($this->checking()) {
            return $this->reportDrift();
        }

        if ($context->failedInstalls() !== []) {
            error('Failed to install: '.implode(', ', $context->failedInstalls()));
        }

        outro('Done.');

        return $context->failedInstalls() === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * One feature at a time, one artifact at a time, each applied before the next is asked for.
     * The laziness is load-bearing rather than an optimisation: workbench:devtool creates
     * workbench/app, and PhpstanFeature only reads that directory when its artifact is yielded, so
     * materialising a feature's artifacts up front would drop `- workbench/app` from
     * phpstan.neon.dist on the very run that created it. BoostFeature reads boost.json the same way.
     */
    private function scaffold(Context $context, string $level): void
    {
        foreach ($this->features($level) as $feature) {
            $flag = $feature->flag();

            if ($flag !== null && ! $context->enabled($flag->name)) {
                continue;
            }

            foreach ($feature->artifacts($context) as $artifact) {
                $results = $context->checking() ? $artifact->drift($context) : $artifact->apply($context);

                foreach ($results as $result) {
                    // A subprocess that was never run has nothing to report: the row would be noise
                    // in the drift table and a false positive in the exit code.
                    if ($result->status === Status::NotCheckable) {
                        continue;
                    }

                    $this->results[] = [$result->label, $result->describe()];
                }
            }
        }
    }

    /**
     * The order is the table: it is what every expectsPromptsTable assertion pins, and why
     * `.gitignore` is its own feature — `ci.yml` sits between `.gitattributes` and it.
     *
     * @return array<int, Feature>
     */
    private function features(string $level = '6'): array
    {
        return [
            new EntrypointFeature,
            new GitFeature,
            new CiFeature,
            new GitignoreFeature,
            new PestFeature,
            new WorkbenchFeature,
            new BrowserFeature,
            new PlaywrightFeature,
            new PhpstanFeature($level),
            new RectorFeature,
            new PintFeature,
            new ComposerScriptsFeature,
            new BoostFeature,
        ];
    }

    /** @return array<int, Flag> */
    private function flags(): array
    {
        return array_values(array_filter(array_map(
            static fn (Feature $feature): ?Flag => $feature->flag(),
            $this->features(),
        )));
    }

    /**
     * Whether this run only reports drift. Every mutation is gated on it: no writes, no
     * `composer require`, no composer.json edits, and none of the subprocesses.
     */
    private function checking(): bool
    {
        return $this->option('check') === true;
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
        return array_any($this->flags(), fn (Flag $flag): bool => $this->option($flag->name) === true || $this->option('no-'.$flag->name) === true);
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

    /** @return array<string, mixed> */
    private function composerJson(): array
    {
        return (array) json_decode((string) file_get_contents($this->root.'/composer.json'), true);
    }
}
