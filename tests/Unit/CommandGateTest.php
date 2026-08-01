<?php

declare(strict_types=1);

use Bambamboole\BoostTestbench\BoostTestbenchServiceProvider;

test('boost and mcp commands activate the bridge', function (array $argv) {
    expect(BoostTestbenchServiceProvider::isBoostCommand($argv))->toBeTrue();
})->with([
    'boost:install' => [['testbench', 'boost:install']],
    'boost:update' => [['testbench', 'boost:update', '--no-interaction']],
    'boost:mcp' => [['testbench', 'boost:mcp']],
    'boost:execute-tool' => [['testbench', 'boost:execute-tool', 'SomeTool', 'e30=']],
    'mcp:start' => [['testbench', 'mcp:start', 'laravel-boost']],
]);

test('other commands do not activate the bridge', function (array $argv) {
    expect(BoostTestbenchServiceProvider::isBoostCommand($argv))->toBeFalse();
})->with([
    'package:test' => [['testbench', 'package:test']],
    'workbench:build' => [['testbench', 'workbench:build']],
    'bare invocation' => [['testbench']],
    'empty argv' => [[]],
]);
