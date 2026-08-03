<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features\Artifacts;

use Bambamboole\ExtendedTestbench\Features\Artifact;
use Bambamboole\ExtendedTestbench\Features\Context;
use Bambamboole\ExtendedTestbench\Features\Result;
use Bambamboole\ExtendedTestbench\Features\Status;

/**
 * A `uses(...)->in($suite)` mapping appended to tests/Pest.php — today only the Browser suite,
 * mapped to a dedicated TestCase so the Vite manifest guard runs. $testCase is fully qualified
 * without a leading backslash, e.g. "{$testNamespace}BrowserTestCase".
 */
final readonly class PestSuiteLine implements Artifact
{
    public function __construct(
        private string $suite,
        private string $testCase,
    ) {}

    public function label(): string
    {
        return 'tests/Pest.php';
    }

    /** @return iterable<Result> */
    public function drift(Context $context): iterable
    {
        $pest = $context->path('tests/Pest.php');

        if (! file_exists($pest)) {
            return;
        }

        $contents = (string) file_get_contents($pest);

        yield new Result(
            "{$this->label()}: {$this->suite} suite",
            str_contains($contents, "'{$this->suite}'") ? Status::Ok : Status::Missing,
        );
    }

    /** @return iterable<Result> */
    public function apply(Context $context): iterable
    {
        $pest = $context->path('tests/Pest.php');

        if (! file_exists($pest)) {
            return;
        }

        $contents = (string) file_get_contents($pest);

        if (str_contains($contents, "'{$this->suite}'")) {
            if (! str_contains($contents, $this->shortTestCase())) {
                $context->warn("tests/Pest.php already maps '{$this->suite}' to the base TestCase. Change that line to uses(\\{$this->testCase}::class)->in('{$this->suite}'); so the Vite guard runs.");
            }

            return;
        }

        $context->files()->append(
            $pest,
            sprintf("\nuses(\\%s::class)->in('%s');\n", $this->testCase, $this->suite),
        );

        yield new Result($this->label(), Status::Written, sprintf('%s suite appended', strtolower($this->suite)));
    }

    /** The short class name (no namespace) — matches even when only an aliased `use` import is present. */
    private function shortTestCase(): string
    {
        $segments = explode('\\', $this->testCase);

        return end($segments);
    }
}
