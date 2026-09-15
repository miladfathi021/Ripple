<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\Semantics\Laravel;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\Index\RepositoryIndexHolder;
use Ripple\Analysis\Semantics\Laravel\LaravelSemanticAnnotationProvider;
use Ripple\Tests\Support\IndexedPhp;

final class LaravelSemanticAnnotationProviderTest extends TestCase
{
    public function testReturnsEmptyAnnotationsUntilAnIndexIsAvailable(): void
    {
        $provider = new LaravelSemanticAnnotationProvider(new RepositoryIndexHolder());

        $this->assertSame([], $provider->getAnnotations());
    }

    public function testDoesNotInventGraphNodesForUnknownLaravelBases(): void
    {
        $index = IndexedPhp::index([
            'app/Http/Controllers/Controller.php' => <<<'PHP'
<?php
namespace App\Http\Controllers;
class Controller extends \Illuminate\Routing\Controller {}
PHP,
            'app/Http/Controllers/ReservationController.php' => <<<'PHP'
<?php
namespace App\Http\Controllers;
class ReservationController extends Controller
{
    public function update(): void {}
}
PHP,
        ]);

        $this->assertFalse($index->hasSymbol('Illuminate\\Routing\\Controller'));
        $holder = new RepositoryIndexHolder();
        $holder->set($index);
        $annotations = (new LaravelSemanticAnnotationProvider($holder))->getAnnotations();

        $this->assertContains(
            ['App\\Http\\Controllers\\ReservationController::update', 'api_entrypoint'],
            array_map(
                static fn ($annotation): array => [$annotation->symbolId, $annotation->type->value],
                $annotations,
            ),
        );
    }
}
