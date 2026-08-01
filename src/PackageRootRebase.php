<?php

declare(strict_types=1);

namespace Bambamboole\BoostTestbench;

use Illuminate\Foundation\Application;

final class PackageRootRebase
{
    public static function apply(Application $app, string $packageRoot): void
    {
        $pins = [
            'useStoragePath' => $app->storagePath(),
            'useConfigPath' => $app->configPath(),
            'useDatabasePath' => $app->databasePath(),
            'useBootstrapPath' => $app->bootstrapPath(),
            'useLangPath' => $app->langPath(),
            'usePublicPath' => $app->publicPath(),
        ];

        $app->setBasePath($packageRoot);

        foreach ($pins as $method => $path) {
            $app->{$method}($path);
        }

        $srcPath = $packageRoot.DIRECTORY_SEPARATOR.'src';

        if (is_dir($srcPath)) {
            $app->useAppPath($srcPath);
        }
    }
}
