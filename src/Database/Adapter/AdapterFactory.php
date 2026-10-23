<?php

declare(strict_types=1);

namespace Phoenix\Database\Adapter;

use Phoenix\Config\EnvironmentConfig;
use Phoenix\Exception\InvalidArgumentValueException;

/**
 * Registry of adapter factories.
 *
 * Adapter packages (efabrica/phoenix-mysql, efabrica/phoenix-pgsql, efabrica/phoenix-scylla, ...) register their factories here
 * from a composer "autoload.files" bootstrap, so every adapter installed via composer is available
 * without any further configuration.
 */
final class AdapterFactory
{
    /** @var array<string, AdapterFactoryInterface> adapter name => factory */
    private static array $factories = [];

    public static function registerFactory(AdapterFactoryInterface $factory): void
    {
        self::$factories[$factory->getAdapterName()] = $factory;
    }

    public static function unregisterFactory(AdapterFactoryInterface $factory): void
    {
        $name = $factory->getAdapterName();
        if ((self::$factories[$name] ?? null) === $factory) {
            unset(self::$factories[$name]);
        }
    }

    /**
     * @return string[] names of all registered adapters
     */
    public static function getAdapterNames(): array
    {
        return array_keys(self::$factories);
    }

    /**
     * @throws InvalidArgumentValueException on unknown adapter or unusable configuration
     */
    public static function instance(EnvironmentConfig $config): AdapterInterface
    {
        $adapter = $config->getAdapter();
        if (!isset(self::$factories[$adapter])) {
            $names = self::getAdapterNames();
            $hint = $names === []
                ? 'No adapter is registered, install an adapter package (e.g. efabrica/phoenix-mysql, efabrica/phoenix-pgsql or efabrica/phoenix-scylla).'
                : 'Use one of value: "' . implode('", "', $names) . '".';
            throw new InvalidArgumentValueException('Unknown adapter "' . $adapter . '". ' . $hint);
        }
        return self::$factories[$adapter]->create($config);
    }

    /**
     * Keeps only the major.minor.patch part of a server version string.
     */
    public static function normalizeVersion(?string $version): ?string
    {
        if ($version === null) {
            return null;
        }
        sscanf($version, '%d.%d.%d', $v1, $v2, $v3);
        return implode('.', array_filter([$v1, $v2, $v3], function ($v) {
            return $v !== null;
        }));
    }
}
