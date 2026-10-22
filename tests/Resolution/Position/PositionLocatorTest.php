<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution\Position;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Resolution\Position\PositionLocator;
use Firehed\PhpLsp\Resolution\Position\Slot;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PositionLocator::class)]
final class PositionLocatorTest extends TestCase
{
    use LoadsFixturesTrait;

    /**
     * @return iterable<string, array{string, string, Slot, string, bool}>
     */
    public static function positions(): iterable
    {
        $c = 'src/Completion/';
        yield 'a statement' => [$c . 'Keywords.php', 'statement', Slot::Expression, '', false];
        yield 'a class body' => [$c . 'Keywords.php', 'class_body', Slot::ClassMember, '', false];
        yield 'after a modifier' => [$c . 'Keywords.php', 'after_visibility', Slot::ClassMember, '', false];
        yield 'a comment' => [$c . 'ContextFiltering.php', 'in_comment', Slot::NotCode, '', false];
        yield 'a heredoc' => [$c . 'ContextFiltering.php', 'in_heredoc', Slot::InterpolatedString, '', false];
        yield 'a member not typed' => [$c . 'MethodAccess.php', 'this_empty', Slot::Member, '', false];
        yield 'a member being typed' => [$c . 'MethodAccess.php', 'this_prefix', Slot::Member, 'get', false];
        yield 'a member of a call' => [$c . 'MethodAccess.php', 'chain_on_string', Slot::Member, '', false];
        yield 'a static member' => [$c . 'StaticAccess.php', 'self_prefix', Slot::StaticMember, 'get', false];
        yield 'an enum case' => [$c . 'EnumUsage.php', 'unit_enum_prefix', Slot::StaticMember, 'A', false];
        yield 'a second caught type' => [$c . 'MultiCatchCompletion.php', 'catch_multi', Slot::Catch, '', false];
        yield 'an implements list' => [
            $c . 'ImplementsCompletion.php', 'implements_empty', Slot::Implements, '', false,
        ];
        yield 'an implemented interface' => [
            $c . 'ImplementsPrefixCompletion.php', 'implements_d_prefix', Slot::Implements, 'D', false,
        ];
        yield 'an extended class' => [
            $c . 'ClassExtendsPrefixCompletion.php', 'class_extends_p_prefix', Slot::ClassExtends, 'P', false,
        ];
        yield 'an extended interface' => [
            $c . 'InterfaceExtendsPrefixCompletion.php',
            'interface_extends_d_prefix',
            Slot::InterfaceExtends,
            'D',
            false,
        ];
        yield 'after new' => [$c . 'NewCompletion.php', 'new_abstract', Slot::New, '', false];
        yield 'after instanceof' => [$c . 'InstanceofCompletion.php', 'instanceof_empty', Slot::Instanceof, '', false];
        yield 'a parameter type' => [$c . 'TypeHints.php', 'param_type', Slot::ParameterType, '', false];
        yield 'a parameter type being typed' => [
            $c . 'TypeHints.php', 'param_prefix', Slot::ParameterType, 'Us', false,
        ];
        yield 'a return type' => [$c . 'TypeHints.php', 'return_type', Slot::ReturnType, '', false];
        yield 'a union member' => [$c . 'TypeHints.php', 'union_second', Slot::ParameterType, '', false];
        yield 'a property type' => [$c . 'TypeHints.php', 'property_prefix', Slot::PropertyType, 'Us', false];
        yield 'a nullable property type' => [$c . 'TypeHints.php', 'nullable_property', Slot::PropertyType, '', false];
        yield 'an attribute' => [$c . 'AttributePrefixCompletion.php', 'attr_prefix', Slot::Attribute, 'Rou', false];
        yield 'a variable' => [$c . 'Variables.php', 'local_prefix', Slot::Variable, '$l', false];
        yield 'an empty argument list' => [$c . 'NamedArguments.php', 'named_empty', Slot::Expression, '', true];
        yield 'after a named argument' => [$c . 'NamedArguments.php', 'after_colon', Slot::Expression, '', true];
        yield 'a bare word argument' => [
            $c . 'NamedArguments.php', 'incomplete_with_prefix', Slot::Expression, 'n', true,
        ];
        yield 'a word in a call' => [$c . 'EditingNamedArg.php', 'bare_word_in_call', Slot::Expression, 'cou', true];
        yield 'a function being typed' => [
            $c . 'FunctionCompletion.php', 'builtin_function', Slot::Expression, 'arr', false,
        ];
        yield 'an attribute argument' => [
            $c . 'AttributeNamedArguments.php', 'attr_arg_empty', Slot::Expression, '', true,
        ];
        yield 'after positional arguments' => [
            $c . 'NamedArguments.php', 'after_positional', Slot::Expression, '', true,
        ];
        $p = 'src/Position/Positions.php';
        yield 'a further parameter' => [$p, 'second_parameter', Slot::ParameterType, '', false];
        yield 'an empty body' => [$p, 'empty_body', Slot::Expression, '', false];
        yield 'interpolated text' => [$p, 'in_interpolated', Slot::InterpolatedString, '', false];
        yield 'after two positional arguments' => [$p, 'after_two_positional', Slot::Expression, '', true];
        yield 'outside every node' => ['Position/TopLevel.php', 'top_level', Slot::Expression, '', false];
        yield 'after an attribute' => ['Recovery/AfterAttribute.php', 'after_attribute', Slot::ClassMember, '', false];
        yield 'past a slot with code between' => [
            'Recovery/SlotElsewhere.php', 'after_kept', Slot::Expression, '', false,
        ];
    }

    /**
     * Positions right after a word, as a cursor sits once it is typed.
     *
     * @return iterable<string, array{string, string, Slot, string, bool}>
     */
    public static function wordEnds(): iterable
    {
        $p = 'src/Position/Positions.php';
        yield 'a class before ::' => [$p, 'User::create', Slot::StaticReceiver, 'User', false];
        yield 'an argument name' => [$p, '(first: 1', Slot::ArgumentName, 'first', true];
        yield 'a declared name' => [$p, 'function method', Slot::DeclaredName, 'method', false];
        yield 'a called function' => [$p, 'printf(', Slot::CalledName, 'printf', false];
    }

    #[DataProvider('wordEnds')]
    public function testTheWordJustTypedIsLocated(
        string $fixture,
        string $text,
        Slot $slot,
        string $prefix,
        bool $inCall,
    ): void {
        $code = $this->loadFixture($fixture);
        $word = (int) strpos($code, $text) + (int) strpos($text, $prefix) + strlen($prefix);

        $located = (new PositionLocator(ProductionSyntaxSource::create()->source))
            ->locate(new TextDocument('file:///' . $fixture, 'php', 1, $code), new ByteOffset($word));

        self::assertSame($slot, $located->slot, 'the slot');
        self::assertSame($prefix, $located->prefix, 'what is typed of the word');
        self::assertSame($prefix, $located->word, 'the whole word, since it ends here');
        self::assertSame($inCall, $located->call !== null, 'whether it is in a call\'s arguments');
    }

    #[DataProvider('positions')]
    public function testThePositionIsLocated(
        string $fixture,
        string $marker,
        Slot $slot,
        string $prefix,
        bool $inCall,
    ): void {
        $code = $this->loadFixture($fixture);
        $document = new TextDocument('file:///' . $fixture, 'php', 1, $code);

        $located = (new PositionLocator(ProductionSyntaxSource::create()->source))
            ->locate($document, new ByteOffset($this->markerOffset($code, $marker)));

        self::assertSame($slot, $located->slot, 'the slot');
        self::assertSame($prefix, $located->prefix, 'what is typed of the word');
        self::assertSame($inCall, $located->call !== null, 'whether it is in a call\'s arguments');
    }
}
