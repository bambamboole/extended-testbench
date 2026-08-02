<?php

declare(strict_types=1);

function guidelinePath(): string
{
    return dirname(__DIR__, 2).'/resources/boost/guidelines/core.blade.php';
}

it('ships exactly one boost guideline file', function () {
    $files = glob(dirname(__DIR__, 2).'/resources/boost/guidelines/*');

    expect($files)->toHaveCount(1)
        ->and(basename($files[0]))->toBe('core.blade.php');
});

it('covers comments, git and testbench development', function () {
    expect(file_get_contents(guidelinePath()))
        ->toContain('## Comments')
        ->toContain('## Git & pull requests')
        ->toContain('## Package development under Testbench');
});

it('self-hosts the shipped guideline through a symlink', function () {
    $link = dirname(__DIR__, 2).'/.ai/guidelines/core.blade.php';

    expect(is_link($link))->toBeTrue()
        ->and(realpath($link))->toBe(realpath(guidelinePath()));
});
