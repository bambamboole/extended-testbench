<?php

declare(strict_types=1);

namespace Bambamboole\ExtendedTestbench\Features;

/** Reads boost.json's `packages` key, treating a missing or malformed one as empty. */
final readonly class BoostJson
{
    public static function registers(Context $context, string $package): bool
    {
        $path = $context->path('boost.json');

        if (! file_exists($path)) {
            return false;
        }

        $config = json_decode((string) @file_get_contents($path), true);

        return is_array($config) && in_array($package, self::packages($config), true);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, mixed>
     */
    public static function packages(array $config): array
    {
        return is_array($config['packages'] ?? null) ? $config['packages'] : [];
    }
}
