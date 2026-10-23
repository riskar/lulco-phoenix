<?php

declare(strict_types=1);

namespace Phoenix\Database\Adapter;

use Phoenix\Config\EnvironmentConfig;
use Phoenix\Exception\InvalidArgumentValueException;

/**
 * Factory of one adapter, shipped by an adapter package and registered in AdapterFactory.
 */
interface AdapterFactoryInterface
{
    /**
     * @return string name accepted as the "adapter" config value, e.g. "mysql"
     */
    public function getAdapterName(): string;

    /**
     * @throws InvalidArgumentValueException when the configuration is not usable
     */
    public function create(EnvironmentConfig $config): AdapterInterface;
}
