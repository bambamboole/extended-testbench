<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->root = dirname(__DIR__, 2);
    $this->skeleton = $this->root.'/vendor/orchestra/testbench-core/laravel';

    $this->originalBoostJson = file_exists($this->root.'/boost.json') ? file_get_contents($this->root.'/boost.json') : null;
    $this->originalClaudeMd = file_exists($this->root.'/CLAUDE.md') ? file_get_contents($this->root.'/CLAUDE.md') : null;

    // artisan is tracked in this repository, so it is restored in afterEach rather than deleted;
    // removing it here is what lets the test observe the entrypoint being recreated.
    $this->originalArtisan = file_exists($this->root.'/artisan') ? file_get_contents($this->root.'/artisan') : null;

    file_put_contents($this->root.'/boost.json', json_encode([
        'agents' => ['claude_code'],
        'guidelines' => true,
        'mcp' => false,
        'skills' => [],
    ], JSON_PRETTY_PRINT).PHP_EOL);

    foreach (['/CLAUDE.md', '/artisan'] as $file) {
        @unlink($this->root.$file);
    }

    foreach (['/CLAUDE.md', '/boost.json'] as $file) {
        @unlink($this->skeleton.$file);
    }
});

afterEach(function () {
    @unlink($this->root.'/artisan');

    if ($this->originalArtisan !== null) {
        file_put_contents($this->root.'/artisan', $this->originalArtisan);
    }

    if ($this->originalBoostJson !== null) {
        file_put_contents($this->root.'/boost.json', $this->originalBoostJson);
    } else {
        @unlink($this->root.'/boost.json');
    }

    if ($this->originalClaudeMd !== null) {
        file_put_contents($this->root.'/CLAUDE.md', $this->originalClaudeMd);
    } else {
        @unlink($this->root.'/CLAUDE.md');
    }
});

test('boost:update writes guidelines to the package root, never the skeleton', function () {
    $process = new Process(
        [PHP_BINARY, 'vendor/bin/testbench', 'boost:update', '--no-interaction'],
        $this->root,
        ['APP_ENV' => 'local', 'APP_DEBUG' => 'true'],
    );
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput())
        ->and($this->root.'/CLAUDE.md')->toBeFile()
        ->and(file_get_contents($this->root.'/CLAUDE.md'))->toContain('=== foundation rules ===')
        ->and($this->root.'/artisan')->toBeFile()
        ->and(is_link($this->root.'/artisan'))->toBeFalse()
        ->and(file_get_contents($this->root.'/artisan'))->toContain("require __DIR__.'/vendor/bin/testbench';")
        ->and(file_exists($this->skeleton.'/CLAUDE.md'))->toBeFalse()
        ->and(file_exists($this->skeleton.'/boost.json'))->toBeFalse();
});

test('a dangling artisan symlink left by an older version is replaced with the shim', function () {
    symlink('vendor/bin/nonexistent-target', $this->root.'/artisan');

    $process = new Process(
        [PHP_BINARY, 'vendor/bin/testbench', 'boost:update', '--no-interaction'],
        $this->root,
        ['APP_ENV' => 'local', 'APP_DEBUG' => 'true'],
    );
    $process->run();

    expect(is_link($this->root.'/artisan'))->toBeFalse()
        ->and($this->root.'/artisan')->toBeFile()
        ->and(file_get_contents($this->root.'/artisan'))->toContain("require __DIR__.'/vendor/bin/testbench';");
});

test('an existing artisan entrypoint is left untouched', function () {
    file_put_contents($this->root.'/artisan', "<?php // custom entrypoint\n");

    $process = new Process(
        [PHP_BINARY, 'vendor/bin/testbench', 'boost:update', '--no-interaction'],
        $this->root,
        ['APP_ENV' => 'local', 'APP_DEBUG' => 'true'],
    );
    $process->run();

    expect(file_get_contents($this->root.'/artisan'))->toBe("<?php // custom entrypoint\n");
});
