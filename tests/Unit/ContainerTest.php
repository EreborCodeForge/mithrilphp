<?php

namespace Erebor\Mithril\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Erebor\Mithril\Container;
use Erebor\Mithril\Exceptions\ContainerException;

class ContainerTest extends TestCase
{
    public function testBindAndResolve()
    {
        $container = new Container();
        $container->bind('foo', fn() => new \stdClass());
        
        $this->assertTrue($container->has('foo'));
        $this->assertInstanceOf(\stdClass::class, $container->get('foo'));
    }

    public function testSingleton()
    {
        $container = new Container();
        $container->singleton('foo', fn() => new \stdClass());
        
        $instance1 = $container->get('foo');
        $instance2 = $container->get('foo');
        
        $this->assertSame($instance1, $instance2);
    }

    public function testFactory()
    {
        $container = new Container();

        $container->factory(TransientService::class, fn() => new TransientService());
        
        $instance1 = $container->get(TransientService::class);
        $instance2 = $container->get(TransientService::class);
        
        $this->assertInstanceOf(TransientService::class, $instance1);
        $this->assertInstanceOf(TransientService::class, $instance2);
        
        $this->assertNotSame($instance1, $instance2);
        $this->assertNotEquals($instance1->id, $instance2->id);

        $container->factory('transient_alias', TransientService::class);

        $aliasInstance1 = $container->get('transient_alias');
        $aliasInstance2 = $container->get('transient_alias');

        $this->assertInstanceOf(TransientService::class, $aliasInstance1);
        $this->assertNotSame($aliasInstance1, $aliasInstance2);
        $this->assertNotEquals($aliasInstance1->id, $aliasInstance2->id);
    }

    public function testAutoResolution()
    {
        $container = new Container();
        $instance = $container->get(ConcreteClass::class);
        
        $this->assertInstanceOf(ConcreteClass::class, $instance);
    }

    public function testDependencyResolution()
    {
        $container = new Container();
        $instance = $container->get(ServiceWithDependency::class);
        
        $this->assertInstanceOf(ServiceWithDependency::class, $instance);
        $this->assertInstanceOf(ConcreteClass::class, $instance->dependency);
    }

    public function testInterfaceBinding()
    {
        $container = new Container();
        $container->bind(DependencyInterface::class, ConcreteClass::class);
        
        $instance = $container->get(ServiceWithInterface::class);
        
        $this->assertInstanceOf(ServiceWithInterface::class, $instance);
        $this->assertInstanceOf(ConcreteClass::class, $instance->dependency);
    }

    public function testContainerException()
    {
        $this->expectException(ContainerException::class);
        $container = new Container();
        $container->get('non_existent_class');
    }
}

interface DependencyInterface {}
class ConcreteClass implements DependencyInterface {}

class ServiceWithDependency
{
    public function __construct(public ConcreteClass $dependency) {}
}

class ServiceWithInterface
{
    public function __construct(public DependencyInterface $dependency) {}
}

class TransientService
{
    public string $id;
    public function __construct()
    {
        $this->id = uniqid('', true);
    }
}
