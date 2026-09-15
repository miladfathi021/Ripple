<?php

declare(strict_types=1);

namespace Ripple\Tests\Unit\Analysis\AST;

use PHPUnit\Framework\TestCase;
use Ripple\Analysis\AST\AstAnalyzer;
use Ripple\Analysis\AST\AstResult;
use Ripple\Analysis\AST\Symbol;
use Ripple\Analysis\AST\SymbolType;

final class SymbolExtractorTest extends TestCase
{
    public function testExtractsASimpleClass(): void
    {
        $result = $this->analyze('simple-class.php');
        $class = $this->symbol($result, 'Ripple\\Tests\\Fixtures\\SimpleClass\\Foo');

        $this->assertSame(SymbolType::Class_, $class->type);
        $this->assertSame('Foo', $class->name);
        $this->assertSame('Ripple\\Tests\\Fixtures\\SimpleClass\\Foo', $class->fullyQualifiedName);
        $this->assertSame(5, $class->startLine);
        $this->assertSame(7, $class->endLine);
        $this->assertNull($class->parent);
    }

    public function testExtractsMultipleMethods(): void
    {
        $result = $this->analyze('class-with-methods.php');
        $calculator = 'Ripple\\Tests\\Fixtures\\ClassWithMethods\\Calculator';

        $this->assertSame($calculator, $this->symbol($result, $calculator)->fullyQualifiedName);
        $this->assertSame($calculator . '::add', $this->symbol($result, $calculator . '::add')->fullyQualifiedName);
        $this->assertSame($calculator . '::subtract', $this->symbol($result, $calculator . '::subtract')->fullyQualifiedName);
        $this->assertSame(7, $this->symbol($result, $calculator . '::add')->startLine);
        $this->assertSame(10, $this->symbol($result, $calculator . '::add')->endLine);
        $this->assertNotSame(
            $this->symbol($result, $calculator)->startLine,
            $this->symbol($result, $calculator . '::add')->startLine,
        );
    }

    public function testResolvesNamespacedFullyQualifiedNamesAndParents(): void
    {
        $result = $this->analyze('namespaced-class.php', 'src/Services/ReservationService.php');
        $className = 'Ripple\\Tests\\Fixtures\\NamespacedClass\\ReservationService';

        $this->assertSame('Ripple\\Tests\\Fixtures\\NamespacedClass', $result->namespace);
        $this->assertSame(['App\\Models\\Reservation'], $result->uses);
        $this->assertSame('src/Services/ReservationService.php', $result->file);

        $class = $this->symbol($result, $className);
        $this->assertSame(SymbolType::Class_, $class->type);
        $this->assertSame(7, $class->startLine);
        $this->assertSame(22, $class->endLine);

        $update = $this->symbol($result, $className . '::updateStatus');
        $this->assertSame(SymbolType::Method, $update->type);
        $this->assertSame('updateStatus', $update->name);
        $this->assertSame($className, $update->parent);
        $this->assertSame('public', $update->visibility);
        $this->assertFalse($update->isStatic);
        $this->assertSame(9, $update->startLine);
        $this->assertSame(11, $update->endLine);

        $validate = $this->symbol($result, $className . '::validate');
        $this->assertSame('protected', $validate->visibility);
        $this->assertFalse($validate->isStatic);

        $normalize = $this->symbol($result, $className . '::normalize');
        $this->assertSame('private', $normalize->visibility);
        $this->assertTrue($normalize->isStatic);
        $this->assertSame(18, $normalize->startLine);
        $this->assertSame(21, $normalize->endLine);
        $this->assertSame([], $update->attributes);
    }

    public function testCollectsResolvedMethodAttributes(): void
    {
        $result = $this->analyze('phpunit-test-attribute.php');
        $attributed = $this->symbol($result, 'Ripple\\Tests\\Fixtures\\PhpunitTestAttribute\\AttributeExample::updates_status');

        $this->assertSame(
            ['PHPUnit\\Framework\\Attributes\\Test'],
            $attributed->attributes,
        );
        $this->assertTrue($attributed->hasAttribute('PHPUnit\\Framework\\Attributes\\Test'));
        $this->assertSame([], $this->symbol($result, 'Ripple\\Tests\\Fixtures\\PhpunitTestAttribute\\AttributeExample::helper')->attributes);
    }

    public function testExtractsAnInterface(): void
    {
        $result = $this->analyze('interface.php');
        $interface = $this->symbol($result, 'Ripple\\Tests\\Fixtures\\InterfaceExample\\PaymentGateway');

        $this->assertSame(SymbolType::Interface, $interface->type);
        $this->assertSame(
            'Ripple\\Tests\\Fixtures\\InterfaceExample\\PaymentGateway::charge',
            $this->symbol($result, 'Ripple\\Tests\\Fixtures\\InterfaceExample\\PaymentGateway::charge')->fullyQualifiedName,
        );
        $this->assertSame('public', $this->symbol($result, 'Ripple\\Tests\\Fixtures\\InterfaceExample\\PaymentGateway::charge')->visibility);
    }

    public function testExtractsATrait(): void
    {
        $result = $this->analyze('trait.php');
        $trait = $this->symbol($result, 'Ripple\\Tests\\Fixtures\\TraitExample\\LogsActivity');

        $this->assertSame(SymbolType::Trait, $trait->type);
        $this->assertSame(
            'Ripple\\Tests\\Fixtures\\TraitExample\\LogsActivity::log',
            $this->symbol($result, 'Ripple\\Tests\\Fixtures\\TraitExample\\LogsActivity::log')->fullyQualifiedName,
        );
    }

    public function testExtractsAnEnumAndItsMethods(): void
    {
        $result = $this->analyze('enum.php');
        $enum = 'Ripple\\Tests\\Fixtures\\EnumExample\\ReservationStatus';

        $this->assertSame(SymbolType::Enum, $this->symbol($result, $enum)->type);
        $this->assertSame(
            $enum . '::label',
            $this->symbol($result, $enum . '::label')->fullyQualifiedName,
        );
        $this->assertNull($this->find($result, 'Pending'));
        $this->assertNull($this->find($result, 'Accepted'));
    }

    public function testExtractsANamespacedFunction(): void
    {
        $result = $this->analyze('function.php');
        $function = $this->symbol($result, 'Ripple\\Tests\\Fixtures\\NamespacedFunction\\calculateTotal');

        $this->assertSame(SymbolType::Function, $function->type);
        $this->assertSame('calculateTotal', $function->name);
        $this->assertSame('Ripple\\Tests\\Fixtures\\NamespacedFunction', $result->namespace);
        $this->assertNull($function->parent);
    }

    public function testExtractsAStaticMethod(): void
    {
        $method = $this->symbol(
            $this->analyze('static-method.php'),
            'Ripple\\Tests\\Fixtures\\StaticMethod\\UserFinder::find',
        );

        $this->assertTrue($method->isStatic);
        $this->assertSame('public', $method->visibility);
        $this->assertSame('Ripple\\Tests\\Fixtures\\StaticMethod\\UserFinder', $method->parent);
    }

    public function testExtractsMethodVisibilitiesIncludingImplicitPublic(): void
    {
        $result = $this->analyze('visibilities.php');
        $class = 'Ripple\\Tests\\Fixtures\\Visibilities\\VisibilityExample';

        $this->assertSame('public', $this->symbol($result, $class . '::pub')->visibility);
        $this->assertSame('protected', $this->symbol($result, $class . '::prot')->visibility);
        $this->assertSame('private', $this->symbol($result, $class . '::priv')->visibility);
        $this->assertSame('public', $this->symbol($result, $class . '::implicit')->visibility);
        $this->assertFalse($this->symbol($result, $class . '::implicit')->isStatic);
    }

    public function testSkipsAnonymousClassSymbols(): void
    {
        $result = $this->analyze('anonymous-class.php');
        $names = array_map(static fn (Symbol $symbol): string => $symbol->fullyQualifiedName, $result->symbols);
        $named = 'Ripple\\Tests\\Fixtures\\AnonymousClass\\Named';

        $this->assertSame([$named, $named . '::make'], $names);
        $this->assertNull($this->find($result, 'run'));
    }

    public function testExtractsAClassThatExtendsAndImplements(): void
    {
        $result = $this->analyze('extends-implements.php');
        $class = $this->symbol($result, 'Ripple\\Tests\\Fixtures\\ExtendsImplements\\Child');

        $this->assertSame(SymbolType::Class_, $class->type);
        $this->assertCount(1, $result->symbols);
    }

    public function testExtractsAClassThatUsesATrait(): void
    {
        $result = $this->analyze('uses-trait.php');
        $class = 'Ripple\\Tests\\Fixtures\\UsesTrait\\UsesTrait';

        $this->assertSame($class, $this->symbol($result, $class)->fullyQualifiedName);
        $this->assertCount(1, $result->symbols);
    }

    public function testExtractsMultipleClassesInOneFile(): void
    {
        $result = $this->analyze('multiple-classes.php');
        $first = 'Ripple\\Tests\\Fixtures\\MultipleClasses\\First';
        $second = 'Ripple\\Tests\\Fixtures\\MultipleClasses\\Second';

        $this->assertSame($first, $this->symbol($result, $first)->fullyQualifiedName);
        $this->assertSame($second, $this->symbol($result, $second)->fullyQualifiedName);
        $this->assertSame($second . '::run', $this->symbol($result, $second . '::run')->fullyQualifiedName);
        $this->assertCount(3, $result->symbols);
    }

    public function testSupportsBracedNamespaces(): void
    {
        $result = $this->analyze('braced-namespace.php');
        $class = 'Ripple\\Tests\\Fixtures\\BracedNamespace\\ReservationService';

        $this->assertSame('Ripple\\Tests\\Fixtures\\BracedNamespace', $result->namespace);
        $this->assertSame($class, $this->symbol($result, $class)->fullyQualifiedName);
        $this->assertSame(
            $class . '::updateStatus',
            $this->symbol($result, $class . '::updateStatus')->fullyQualifiedName,
        );
    }

    public function testCollectsUseStatementsWithoutResolvingUsage(): void
    {
        $result = $this->analyze('uses.php');

        $this->assertSame(
            [
                'App\\Models\\Reservation',
                'App\\Services\\PaymentService',
            ],
            $result->uses,
        );
        $this->assertSame(
            'Ripple\\Tests\\Fixtures\\Uses\\ReservationService',
            $this->symbol($result, 'Ripple\\Tests\\Fixtures\\Uses\\ReservationService')->fullyQualifiedName,
        );
    }

    private function analyze(string $fixture, string $file = ''): AstResult
    {
        $path = dirname(__DIR__, 3) . '/Fixtures/php/' . $fixture;
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        return (new AstAnalyzer())->analyze($contents, $file === '' ? $fixture : $file);
    }

    private function symbol(AstResult $result, string $fullyQualifiedName): Symbol
    {
        $symbol = $this->find($result, $fullyQualifiedName);
        $this->assertNotNull($symbol, 'Missing symbol ' . $fullyQualifiedName);

        return $symbol;
    }

    private function find(AstResult $result, string $fullyQualifiedName): ?Symbol
    {
        foreach ($result->symbols as $symbol) {
            if ($symbol->fullyQualifiedName === $fullyQualifiedName || $symbol->name === $fullyQualifiedName) {
                return $symbol;
            }
        }

        return null;
    }
}
