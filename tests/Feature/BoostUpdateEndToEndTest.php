<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->root = dirname(__DIR__, 2);
    $this->skeleton = $this->root.'/vendor/orchestra/testbench-core/laravel';

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
    foreach (['/boost.json', '/CLAUDE.md', '/artisan'] as $file) {
        @unlink($this->root.$file);
    }

    File::deleteDirectory($this->root.'/.ai/skills');
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
        ->and(is_link($this->root.'/artisan'))->toBeTrue()
        ->and(readlink($this->root.'/artisan'))->toBe('vendor/bin/testbench')
        ->and(file_exists($this->skeleton.'/CLAUDE.md'))->toBeFalse()
        ->and(file_exists($this->skeleton.'/boost.json'))->toBeFalse();
});

test('a dangling artisan symlink is left untouched and warns on stderr', function () {
    symlink('vendor/bin/nonexistent-target', $this->root.'/artisan');

    $process = new Process(
        [PHP_BINARY, 'vendor/bin/testbench', 'boost:update', '--no-interaction'],
        $this->root,
        ['APP_ENV' => 'local', 'APP_DEBUG' => 'true'],
    );
    $process->run();

    expect($process->getErrorOutput())->toContain('dangling symlink')
        ->and(is_link($this->root.'/artisan'))->toBeTrue()
        ->and(readlink($this->root.'/artisan'))->toBe('vendor/bin/nonexistent-target');
});
