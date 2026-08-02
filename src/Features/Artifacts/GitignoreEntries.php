<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;

/**
 * The .gitignore entries every scaffolded package needs, appended to whatever the package already
 * has rather than replacing it.
 */
final readonly class GitignoreEntries implements Artifact
{
    /** @var array<int, string> */
    private array $entries;

    public function __construct(string ...$entries)
    {
        $this->entries = $entries;
    }

    public function label(): string
    {
        return '.gitignore';
    }

    /** @return iterable<Result> */
    public function drift(Context $context): iterable
    {
        $missing = $this->missing($context);

        if ($missing === []) {
            yield new Result($this->label(), Status::Ok);

            return;
        }

        yield new Result($this->label(), Status::Missing, 'missing '.count($missing).' entries: '.implode(' ', $missing));
    }

    /** @return iterable<Result> */
    public function apply(Context $context): iterable
    {
        $missing = $this->missing($context);

        if ($missing === []) {
            yield new Result($this->label(), Status::Skipped, 'skipped (nothing to add)');

            return;
        }

        $path = $context->path('.gitignore');
        $contents = file_exists($path) ? (string) @file_get_contents($path) : '';
        $prefix = $contents === '' ? '' : rtrim($contents, "\n")."\n";

        if (@file_put_contents($path, $prefix.implode("\n", $missing)."\n") === false) {
            yield new Result($this->label(), Status::Failed);

            return;
        }

        if ($contents === '') {
            yield new Result($this->label(), Status::Written);

            return;
        }

        yield new Result($this->label(), Status::Overwritten, 'updated');
    }

    /** @return array<int, string> */
    private function missing(Context $context): array
    {
        $path = $context->path('.gitignore');
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

        return array_values(array_filter(
            $this->entries,
            static fn (string $entry): bool => ! $covered($entry),
        ));
    }
}
