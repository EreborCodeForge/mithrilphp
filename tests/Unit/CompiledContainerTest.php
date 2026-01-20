<?php

namespace Erebor\Mithril\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Erebor\Mithril\Container\CompiledContainer;
use Erebor\Mithril\Exceptions\ContainerException;

class CompiledContainerTest extends TestCase
{
    public function testHas()
    {
        $container = new CompiledContainer(
            factories: ['factory_service' => fn() => new \stdClass()],
            singletons: ['singleton_service' => fn() => new \stdClass()],
            preloaded: ['instance_service' => new \stdClass()]
        );

        $this->assertTrue($container->has('factory_service'));
        $this->assertTrue($container->has('singleton_service'));
        $this->assertTrue($container->has('instance_service'));
        $this->assertFalse($container->has('non_existent'));
    }

    public function testGetPreloadedInstance()
    {
        $instance = new \stdClass();
        $container = new CompiledContainer(
            factories: [],
            singletons: [],
            preloaded: ['foo' => $instance]
        );

        $this->assertSame($instance, $container->get('foo'));
    }

    public function testGetSingleton()
    {
        $container = new CompiledContainer(
            factories: [],
            singletons: ['foo' => fn() => new \stdClass()]
        );

        $instance1 = $container->get('foo');
        $instance2 = $container->get('foo');

        $this->assertInstanceOf(\stdClass::class, $instance1);
        $this->assertSame($instance1, $instance2);
    }

    public function testGetFactory()
    {
        $container = new CompiledContainer(
            factories: ['bar' => fn() => new \stdClass()],
            singletons: []
        );

        $instance1 = $container->get('bar');
        $instance2 = $container->get('bar');

        $this->assertInstanceOf(\stdClass::class, $instance1);
        $this->assertNotSame($instance1, $instance2);
    }

    public function testNotFound()
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Service [missing] not found');

        $container = new CompiledContainer([], []);
        $container->get('missing');
    }

    public function testInvalidSingletonReturn()
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Compiled singleton [bad] must return object');

        $container = new CompiledContainer(
            factories: [],
            singletons: ['bad' => fn() => 'not an object']
        );
        $container->get('bad');
    }

    public function testInvalidFactoryReturn()
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Compiled factory [bad] must return object');

        $container = new CompiledContainer(
            factories: ['bad' => fn() => 'not an object'],
            singletons: []
        );
        $container->get('bad');
    }
}
