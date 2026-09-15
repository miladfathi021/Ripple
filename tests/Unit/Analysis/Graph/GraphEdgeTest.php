<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Graph;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Dependencies\Dependency;
use Ripple\Analysis\Dependencies\DependencyType;
use Ripple\Analysis\Graph\GraphEdge;

final class GraphEdgeTest extends TestCase
{
    public function testCreatesADirectedEdgeFromADependency(): void
    {
        $edge = GraphEdge::fromDependency(new Dependency(
            'App\\Services\\ReservationService::updateStatus',
            'App\\Services\\PaymentService::validate',
            DependencyType::MethodCall,
            [35],
            1,
        ));

        $this->assertSame('App\\Services\\ReservationService::updateStatus', $edge->source);
        $this->assertSame('App\\Services\\PaymentService::validate', $edge->target);
        $this->assertSame(DependencyType::MethodCall, $edge->type);
        $this->assertSame([35], $edge->lines);
        $this->assertSame(1, $edge->occurrences);
    }
}
