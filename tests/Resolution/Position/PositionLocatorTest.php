<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Tests\Resolution\Position;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Resolution\Position\PositionLocator;
use Firehed\PhpLsp\Resolution\Position\Slot;
use Firehed\PhpLsp\Tests\LoadsFixturesTrait;
use Firehed\PhpLsp\Tests\Parser\ProductionSyntaxSource;
use PhpParser\Node\Expr;
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
        yield 'after a modifier' => [$c . 'Keywords.php', 'after_visibility', Slot::AfterModifiers, '', false];
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
        yield 'a word after modifiers' => [$c . 'TypeHints.php', 'property_prefix', Slot::AfterModifiers, 'Us', false];
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
        yield 'interpolated text' => [$p, 'in_interpolated', Slot::InterpolatedString, 'ite', false];
        yield 'after two positional arguments' => [$p, 'after_two_positional', Slot::Expression, '', true];
        yield 'outside every node' => ['Position/TopLevel.php', 'top_level', Slot::Expression, '', false];
        yield 'after an attribute' => ['Recovery/AfterAttribute.php', 'after_attribute', Slot::ClassMember, '', false];
        yield 'after modifiers of nothing yet' => [
            'src/Position/Members.php', 'after_modifiers_alone', Slot::AfterModifiers, '', false,
        ];
        yield 'a word in a class body' => [
            'Position/ClassBodyWord.php', 'class_body_word', Slot::ClassMember, 'p', false,
        ];
        yield 'a DNF parameter type' => [
            'Position/DnfParameter.php', 'dnf_parameter', Slot::ParameterType, 'Ent', false,
        ];
        yield 'a DNF return type' => ['Position/DnfReturn.php', 'dnf_return', Slot::ReturnType, 'Ent', false];
        yield 'an intersection property type' => [
            'Position/IntersectionProperty.php', 'intersection_property', Slot::PropertyType, '', false,
        ];
        yield 'a lone $' => ['Position/LoneDollar.php', 'lone_dollar', Slot::Variable, '$', false];
        yield 'an empty return type' => [
            'Namespacing/ImportCompletion.php', 'type_hint_return', Slot::ReturnType, '', false,
        ];
        yield 'an import ending in \\' => [
            'Namespacing/UseCompletion.php', 'use_workspace_class', Slot::Import, 'Fixtures\Domain\\', false,
        ];
        yield 'a closure\'s use clause' => [
            'Namespacing/ClosureUseCompletion.php', 'closure_capture', Slot::Variable, '', false,
        ];
        yield 'a qualified name ending in \\' => [
            'Namespacing/ImportedPrefix.php', 'imported_slash', Slot::New, 'Env\\', false,
        ];
        yield 'an absolute name ending in \\' => [
            'Namespacing/CatalogProbe.php', 'ondisk_autoload_files_namespace', Slot::New, '\Fixtures\\', false,
        ];
        yield 'an absolute parameter type' => [
            'Namespacing/AbsoluteNavigation.php', 'param_nav', Slot::ParameterType, '\Ps', false,
        ];
        yield 'right after a string value' => [
            $c . 'EditingNamedArg.php', 'after_string_value', Slot::Expression, '', true,
        ];
        yield 'right after a numeric value' => [
            $c . 'EditingNamedArg.php', 'after_named_value', Slot::Expression, '123', true,
        ];
        yield 'a member with the next statement parsed onto it' => [
            $c . 'NextLineParsedOnto.php', 'this_before_statement', Slot::Member, '', false,
        ];
        yield 'a static member with the next statement parsed onto it' => [
            'TopLevel/static_before_statement.php', 'static_before_statement', Slot::StaticMember, '', false,
        ];
        yield 'a nullsafe member in a for' => [
            'src/IncompleteCode/InControlStructures.php', 'nullsafe_access_for', Slot::Member, '', false,
        ];
        yield 'a member before its written name' => ['src/ParseHealth/Clean.php', 'in_call', Slot::Member, '', true];
        yield 'a constructor\'s arguments' => [
            'src/IncompleteCode/AliasedImports.php', 'colliding_new', Slot::Expression, 'Wid', true,
        ];
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

    /**
     * @return iterable<string, array{string, string, class-string, int, list<string>, int}>
     */
    public static function callSites(): iterable
    {
        $s = 'SignatureHelp.php';
        $b = 'src/Resolution/ArgumentBoundaries.php';
        yield 'the first argument' => [$s, 'first_param', Expr\FuncCall::class, 0, [], 0];
        yield 'the second argument' => [$s, 'second_param', Expr\FuncCall::class, 1, [], 1];
        yield 'a named argument' => [$s, 'named_arg', Expr\FuncCall::class, 1, ['a', 'b'], 0];
        yield 'a constructor' => [$s, 'constructor', Expr\New_::class, 0, [], 0];
        yield 'a static call' => [$s, 'static_call', Expr\StaticCall::class, 0, [], 0];
        $m = Expr\MethodCall::class;
        yield 'typing the first argument' => [$b, 'typing_first', $m, 0, [], 0];
        yield 'after the first argument and a space' => [$b, 'after_first_with_space', $m, 0, [], 0];
        yield 'after a comma' => [$b, 'after_comma', $m, 1, [], 1];
        yield 'after a comma with no space' => [$b, 'after_comma_no_space', $m, 1, [], 1];
        yield 'typing the second argument' => [$b, 'typing_second', $m, 1, [], 1];
        yield 'inside the second argument\'s value' => [$b, 'inside_second_value', $m, 1, [], 1];
        yield 'a named argument after the position' => [
            'src/Completion/EditingNamedArg.php', 'editing_in_complete', Expr\New_::class, 1, ['age'], 1,
        ];
    }

    /**
     * @param class-string $callClass
     * @param list<string> $usedNames
     */
    #[DataProvider('callSites')]
    public function testTheCallSiteIsLocated(
        string $fixture,
        string $marker,
        string $callClass,
        int $argumentIndex,
        array $usedNames,
        int $positionalCount,
    ): void {
        $code = $this->loadFixture($fixture);

        $document = new TextDocument('file:///' . $fixture, 'php', 1, $code);
        $call = (new PositionLocator(ProductionSyntaxSource::create()->source))
            ->locate($document, new ByteOffset($this->markerOffset($code, $marker)))
            ->call;

        self::assertNotNull($call, 'the position is in a call\'s arguments');
        self::assertInstanceOf($callClass, $call->call, 'the call whose arguments hold it');
        self::assertSame($argumentIndex, $call->argumentIndex, 'commas before the position advance the argument');
        self::assertSame($usedNames, $call->usedNames, 'every argument written by name counts as used');
        self::assertSame($positionalCount, $call->positionalCount, 'only positional arguments before it are filled');
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
