<?php

declare(strict_types=1);

use Bambamboole\ExtendedTestbench\Commands\InitCommand;
use Illuminate\Contracts\Console\Kernel;

it('registers the package:init command', function () {
    expect(array_keys($this->app[Kernel::class]->all()))
        ->toContain('package:init');
});

it('builds the command with the package root and a composer instance', function () {
    $command = $this->app->make(InitCommand::class);

    expect($command)->toBeInstanceOf(InitCommand::class)
        ->and($command->getName())->toBe('package:init');
});
