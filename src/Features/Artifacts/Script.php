<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;

/** A single composer.json scripts entry. */
final readonly class Script implements Artifact
{
    /** @param  string|array<int, string>  $command */
    public function __construct(
        private string $name,
        private string|array $command,
    ) {}

    public function label(): string
    {
        return "composer script: {$this->name}";
    }

    /** @return iterable<Result> */
    public function drift(Context $context): iterable
    {
        $scripts = (array) ($context->composerJson()['scripts'] ?? []);

        if (isset($scripts[$this->name])) {
            // Existence by name is not enough: a `check` wired to an entirely different
            // pipeline would report ok next to the `stan` it never runs.
            if ($this->satisfiedBy($scripts[$this->name])) {
                yield new Result($this->label(), Status::Ok);

                return;
            }

            $this->printDiff($context, $scripts[$this->name]);

            yield new Result($this->label(), Status::Differs);

            return;
        }

        $this->warnAboutRenamedScript($context, $scripts);

        yield new Result($this->label(), Status::Missing);
    }

    /**
     * An array script that carries extra entries around the scaffold's own — a package prepending
     * its git-hooks installer to post-install-cmd — still does what the scaffold wired up, so only
     * a missing scaffold entry counts as drift. String scripts stay an exact match.
     */
    private function satisfiedBy(mixed $existing): bool
    {
        if ($existing === $this->command) {
            return true;
        }

        if (! is_array($this->command) || ! is_array($existing)) {
            return false;
        }

        return array_all($this->command, static fn (string $entry): bool => in_array($entry, $existing, true));
    }

    /** File drift prints a unified diff; a script row saying bare `differs` was undebuggable. */
    private function printDiff(Context $context, mixed $existing): void
    {
        $describe = static fn (mixed $command): string => is_string($command)
            ? $command
            : (string) json_encode($command, JSON_UNESCAPED_SLASHES);

        $context->note("composer script '{$this->name}' differs from the scaffold:");
        $context->output()->writeln('-'.$describe($existing));
        $context->output()->writeln('+'.$describe($this->command));
    }

    /** @return iterable<Result> */
    public function apply(Context $context): iterable
    {
        $scripts = (array) ($context->composerJson()['scripts'] ?? []);

        if (isset($scripts[$this->name])) {
            return;
        }

        $this->warnAboutRenamedScript($context, $scripts);

        $context->composer()->modify(function (array $composer): array {
            $composer['scripts'][$this->name] = $this->command;

            return $composer;
        });

        yield new Result($this->label(), Status::Written, 'added');
    }

    /**
     * Scripts are matched by name, so a package already running the same tool under its own name
     * (`analyse` for our `stan`) ends up with both. We still add ours — renaming someone's scripts
     * and the CI that calls them is not ours to do — but the collision gets said out loud.
     *
     * @param  array<string, mixed>  $scripts
     */
    private function warnAboutRenamedScript(Context $context, array $scripts): void
    {
        if (is_array($this->command)) {
            return;
        }

        // Compare basenames: `./vendor/bin/phpstan` is the same tool as our bare `phpstan`, which a
        // raw first-token comparison misses. `@php vendor/bin/x` hides the tool behind the runner.
        $tool = static function (mixed $value): string {
            $first = is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            $tokens = array_values(array_filter(explode(' ', trim($first)), static fn (string $token): bool => $token !== ''));

            if (($tokens[0] ?? '') === '@php') {
                array_shift($tokens);
            }

            return basename($tokens[0] ?? '');
        };

        $ours = $tool($this->command);

        if ($ours === '') {
            return;
        }

        foreach ($scripts as $existing => $existingCommand) {
            if ($tool($existingCommand) === $ours) {
                $context->warn("composer script '{$existing}' already runs {$ours}; adding '{$this->name}' alongside it. The generated `check` script inlines `{$this->command}` rather than calling either one, so drop whichever of the two you do not want.");

                return;
            }
        }
    }
}
