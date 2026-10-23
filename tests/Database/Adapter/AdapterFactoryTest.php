<?php

declare(strict_types=1);

namespace Phoenix\Tests\Database\Adapter;

use Phoenix\Config\EnvironmentConfig;
use Phoenix\Database\Adapter\AdapterFactory;
use Phoenix\Database\Adapter\AdapterFactoryInterface;
use Phoenix\Database\Adapter\AdapterInterface;
use Phoenix\Exception\InvalidArgumentValueException;
use PHPUnit\Framework\TestCase;

final class AdapterFactoryTest extends TestCase
{
    private AdapterFactoryInterface $factory;

    private AdapterInterface $adapter;

    protected function setUp(): void
    {
        $this->adapter = $this->createMock(AdapterInterface::class);
        $adapter = $this->adapter;
        $this->factory = new class ($adapter) implements AdapterFactoryInterface {
            public function __construct(private AdapterInterface $adapter)
            {
            }

            public function getAdapterName(): string
            {
                return 'fake';
            }

            public function create(EnvironmentConfig $config): AdapterInterface
            {
                return $this->adapter;
            }
        };
    }

    protected function tearDown(): void
    {
        AdapterFactory::unregisterFactory($this->factory);
    }

    public function testUnknownAdapterWithoutRegisteredFactories(): void
    {
        $this->expectException(InvalidArgumentValueException::class);
        $this->expectExceptionMessage('Unknown adapter "fake". No adapter is registered');
        AdapterFactory::instance(new EnvironmentConfig(['adapter' => 'fake']));
    }

    public function testRegisteredFactoryCreatesAdapter(): void
    {
        AdapterFactory::registerFactory($this->factory);
        $this->assertSame(['fake'], AdapterFactory::getAdapterNames());
        $this->assertSame($this->adapter, AdapterFactory::instance(new EnvironmentConfig(['adapter' => 'fake'])));
    }

    public function testUnknownAdapterListsRegisteredNames(): void
    {
        AdapterFactory::registerFactory($this->factory);
        $this->expectException(InvalidArgumentValueException::class);
        $this->expectExceptionMessage('Unknown adapter "unknown". Use one of value: "fake".');
        AdapterFactory::instance(new EnvironmentConfig(['adapter' => 'unknown']));
    }

    public function testUnregister(): void
    {
        AdapterFactory::registerFactory($this->factory);
        AdapterFactory::unregisterFactory($this->factory);
        $this->assertSame([], AdapterFactory::getAdapterNames());
    }

    public function testNormalizeVersion(): void
    {
        $this->assertNull(AdapterFactory::normalizeVersion(null));
        $this->assertSame('8.0.29', AdapterFactory::normalizeVersion('8.0.29-1.el8'));
        $this->assertSame('14.2', AdapterFactory::normalizeVersion('14.2 (Debian 14.2-1.pgdg110+1)'));
        $this->assertSame('5.4', AdapterFactory::normalizeVersion('5.4'));
    }
}
