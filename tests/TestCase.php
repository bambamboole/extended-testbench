<?php

declare(strict_types=1);

namespace Tests;

use Bambamboole\ExtendedTestbench\ExtendedTestbenchServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ExtendedTestbenchServiceProvider::class];
    }
}
