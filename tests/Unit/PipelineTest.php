<?php

namespace Erebor\Mithril\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Erebor\Mithril\Support\Pipeline;

class PipelineTest extends TestCase
{
    public function testPipelineExecution()
    {
        $pipeline = new Pipeline();
        
        $result = $pipeline->send(0)
            ->through([
                fn($payload, $next) => $next($payload + 1),
                fn($payload, $next) => $next($payload * 2),
            ])
            ->then(fn($payload) => $payload);
            
        $this->assertEquals(2, $result);
    }

    public function testPipelineOrder()
    {
        $pipeline = new Pipeline();
        
        $result = $pipeline->send([])
            ->through([
                function($payload, $next) {
                    $payload[] = 'first';
                    return $next($payload);
                },
                function($payload, $next) {
                    $payload[] = 'second';
                    return $next($payload);
                },
            ])
            ->then(fn($payload) => $payload);
            
        $this->assertEquals(['first', 'second'], $result);
    }

    public function testPipelineWithObject()
    {
        $pipeline = new Pipeline();
        
        $result = $pipeline->send(10)
            ->through([
                new class {
                    public function handle($payload, $next) {
                        return $next($payload + 5);
                    }
                }
            ])
            ->then(fn($payload) => $payload);
            
        $this->assertEquals(15, $result);
    }
}
