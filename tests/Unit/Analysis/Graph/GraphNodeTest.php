<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Graph;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\AST\SymbolType;
use Ripple\Analysis\Graph\GraphNode;

final class GraphNodeTest extends TestCase
{
    public function testCreatesANodeFromASymbol(): void
    {
        $symbol = $this->methodSymbol();
        $node = GraphNode::fromSymbol($symbol);

        $this->assertSame('App\\Services\\ReservationService::updateStatus', $node->id);
        $this->assertSame('method', $node->type);
        $this->assertSame('updateStatus', $node->name);
        $this->assertSame('App\\Services\\ReservationService::updateStatus', $node->fullyQualifiedName);
        $this->assertSame('src/Services/ReservationService.php', $node->file);
        $this->assertTrue($node->known);
    }

    public function testNodeIdIsTheSymbolFullyQualifiedName(): void
    {
        $this->assertSame(
            'App\\Services\\ReservationService::updateStatus',
            GraphNode::fromSymbol($this->methodSymbol())->id,
        );
        $this->assertSame(
            'App\\Services\\ReservationService',
            GraphNode::fromSymbol($this->classSymbol())->id,
        );
        $this->assertSame(
            'App\\Helpers\\calculateTotal',
            GraphNode::fromSymbol($this->functionSymbol())->id,
        );
    }

    public function testUnindexedNodeUsesOnlyTheAvailableIdentity(): void
    {
        $node = GraphNode::unindexed('Illuminate\\Database\\Eloquent\\Model::save');

        $this->assertSame('Illuminate\\Database\\Eloquent\\Model::save', $node->id);
        $this->assertNull($node->type);
        $this->assertSame('save', $node->name);
        $this->assertSame('Illuminate\\Database\\Eloquent\\Model::save', $node->fullyQualifiedName);
        $this->assertNull($node->file);
        $this->assertFalse($node->known);
    }

    private function methodSymbol(): Symbol
    {
        return new Symbol(
            SymbolType::Method,
            'updateStatus',
            'App\\Services\\ReservationService::updateStatus',
            'src/Services/ReservationService.php',
            30,
            40,
            'App\\Services\\ReservationService',
            'public',
            false,
        );
    }

    private function classSymbol(): Symbol
    {
        return new Symbol(
            SymbolType::Class_,
            'ReservationService',
            'App\\Services\\ReservationService',
            'src/Services/ReservationService.php',
            10,
            80,
        );
    }

    private function functionSymbol(): Symbol
    {
        return new Symbol(
            SymbolType::Function,
            'calculateTotal',
            'App\\Helpers\\calculateTotal',
            'src/Helpers.php',
            5,
            12,
        );
    }
}
