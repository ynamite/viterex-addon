<?php

namespace Ynamite\ViteRex\Tests\Svg;

use PHPUnit\Framework\TestCase;
use Ynamite\ViteRex\Svg\OptimizerFactory;
use Ynamite\ViteRex\Svg\PhpOptimizer;
use Ynamite\ViteRex\Svg\SvgoCli;

final class OptimizerFactoryTest extends TestCase
{
    public function testReturnsNullWhenDisabled(): void
    {
        $this->assertNull(OptimizerFactory::for(stage: 'dev', enabled: false, svgoAvailable: true));
        $this->assertNull(OptimizerFactory::for(stage: 'prod', enabled: false, svgoAvailable: true));
    }

    public function testDevWithSvgoReturnsSvgoCli(): void
    {
        $this->assertInstanceOf(
            SvgoCli::class,
            OptimizerFactory::for(stage: 'dev', enabled: true, svgoAvailable: true),
        );
    }

    public function testDevWithoutSvgoFallsBackToPhpOptimizer(): void
    {
        $this->assertInstanceOf(
            PhpOptimizer::class,
            OptimizerFactory::for(stage: 'dev', enabled: true, svgoAvailable: false),
        );
    }

    public function testStagingAlwaysReturnsPhpOptimizer(): void
    {
        $this->assertInstanceOf(
            PhpOptimizer::class,
            OptimizerFactory::for(stage: 'staging', enabled: true, svgoAvailable: true),
        );
    }

    public function testProdAlwaysReturnsPhpOptimizer(): void
    {
        $this->assertInstanceOf(
            PhpOptimizer::class,
            OptimizerFactory::for(stage: 'prod', enabled: true, svgoAvailable: true),
        );
        $this->assertInstanceOf(
            PhpOptimizer::class,
            OptimizerFactory::for(stage: 'prod', enabled: true, svgoAvailable: false),
        );
    }
}
