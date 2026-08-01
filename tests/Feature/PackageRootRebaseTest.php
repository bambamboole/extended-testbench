<?php

declare(strict_types=1);

use Bambamboole\BoostTestbench\PackageRootRebase;

test('base path moves to the package root while skeleton paths stay pinned', function () {
    $app = $this->app;
    $packageRoot = dirname(__DIR__, 2);

    $skeleton = [
        'storage' => $app->storagePath(),
        'config' => $app->configPath(),
        'database' => $app->databasePath(),
        'bootstrap' => $app->bootstrapPath(),
        'lang' => $app->langPath(),
        'public' => $app->publicPath(),
    ];

    PackageRootRebase::apply($app, $packageRoot);

    expect($app->basePath())->toBe($packageRoot)
        ->and(base_path('composer.json'))->toBe($packageRoot.DIRECTORY_SEPARATOR.'composer.json')
        ->and($app->storagePath())->toBe($skeleton['storage'])
        ->and($app->configPath())->toBe($skeleton['config'])
        ->and($app->databasePath())->toBe($skeleton['database'])
        ->and($app->bootstrapPath())->toBe($skeleton['bootstrap'])
        ->and($app->langPath())->toBe($skeleton['lang'])
        ->and($app->publicPath())->toBe($skeleton['public']);
});

test('app path points at src when it exists', function () {
    $app = $this->app;
    $packageRoot = dirname(__DIR__, 2);

    PackageRootRebase::apply($app, $packageRoot);

    expect($app->path())->toBe($packageRoot.DIRECTORY_SEPARATOR.'src');
});

test('app path is left alone when the package has no src directory', function () {
    $app = $this->app;

    PackageRootRebase::apply($app, sys_get_temp_dir());

    expect($app->path())->toBe(sys_get_temp_dir().DIRECTORY_SEPARATOR.'app');
});
