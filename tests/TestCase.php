<?php

declare(strict_types=1);

namespace Tests;

use Bambamboole\BoostTestbench\BoostTestbenchServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BoostTestbenchServiceProvider::class];
    }
}
