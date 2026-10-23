<?php

declare(strict_types=1);

namespace Firehed\PhpLsp\Resolution\Position;

use Firehed\PhpLsp\Document\TextDocument;
use Firehed\PhpLsp\Domain\ByteOffset;
use Firehed\PhpLsp\Domain\ClassCandidateFilter;
use Firehed\PhpLsp\Domain\ClasslikeType;
use Firehed\PhpLsp\Domain\KeywordGroup;
use Firehed\PhpLsp\Domain\LateBindingKeyword;
use Firehed\PhpLsp\Domain\NameKind;
use Firehed\PhpLsp\Domain\TypeHintContext;
use Firehed\PhpLsp\Resolution\Expected;
use Firehed\PhpLsp\Resolution\ExpressionResolver;
use Firehed\PhpLsp\Resolution\ExpressionResolverInterface;
use Firehed\PhpLsp\Resolution\MemberAccessContext;
use Firehed\PhpLsp\Resolution\NameQuery;
use PhpParser\Node\Name;

final class ExpectedAtPosition implements ExpectedAtPositionInterface
{
    public function __construct(
        private readonly PositionLocatorInterface $locator,
        private readonly CallAtPositionInterface $calls,
        private readonly ExpressionResolverInterface $expressions,
    ) {
    }

    public function expectedAt(TextDocument $document, ByteOffset $offset): Expected
    {
        $located = $this->locator->locate($document, $offset);
        $prefix = $located->prefix;

        return match ($located->slot) {
            Slot::NotCode, Slot::DeclaredName => new Expected($prefix),
            Slot::InterpolatedString, Slot::Variable => new Expected($prefix, variables: true),
            Slot::Member, Slot::StaticMember => new Expected($prefix, members: $this->memberAccess($located)),
            Slot::ArgumentName => new Expected($prefix, argumentNames: $this->calls->callAt($document, $offset)),
            Slot::ClassMember => $prefix === '' ? new Expected($prefix) : new Expected(
                $prefix,
                keywords: KeywordGroup::ClassBody,
            ),
            Slot::AfterModifiers => new Expected(
                $prefix,
                keywords: KeywordGroup::AfterVisibility,
                builtinTypes: TypeHintContext::Property,
                names: self::names($located, [NameKind::ClassLike], ClassCandidateFilter::TypeHint),
            ),
            Slot::ParameterType => self::type($located, TypeHintContext::Parameter),
            Slot::ReturnType => self::type($located, TypeHintContext::ReturnType),
            Slot::PropertyType => self::type($located, TypeHintContext::Property),
            Slot::New => self::named($located, ClassCandidateFilter::Instantiable),
            Slot::Instanceof => self::named($located, ClassCandidateFilter::TypeHint),
            Slot::Catch => self::named($located, ClassCandidateFilter::Throwable),
            Slot::Implements, Slot::InterfaceExtends => self::named($located, ClassCandidateFilter::Interface_),
            Slot::ClassExtends => self::named($located, ClassCandidateFilter::ExtendableClass),
            Slot::Attribute => self::named($located, ClassCandidateFilter::Attribute),
            Slot::TraitUse => self::named($located, ClassCandidateFilter::Trait_),
            Slot::StaticReceiver => self::named($located, ClassCandidateFilter::Any),
            Slot::Import => new Expected(
                $prefix,
                names: self::names($located, [NameKind::ClassLike], ClassCandidateFilter::Any, absolute: true),
            ),
            Slot::Expression, Slot::CalledName => $this->expression($located, $document, $offset),
        };
    }

    /**
     * Anything may start an expression. Inside a call's argument list that
     * includes the name of an argument of what it calls, until its value is
     * being written; elsewhere, with nothing written yet, nothing is offered.
     */
    private function expression(Located $located, TextDocument $document, ByteOffset $offset): Expected
    {
        $prefix = $located->prefix;
        if ($located->call === null) {
            return $prefix === '' ? new Expected($prefix) : new Expected(
                $prefix,
                keywords: KeywordGroup::All,
                names: self::names($located, NameKind::cases(), ClassCandidateFilter::Any),
            );
        }
        $call = $this->calls->callAt($document, $offset);
        return new Expected(
            $prefix,
            argumentNames: $call === null || $call->inArgumentValue ? null : $call,
            variables: true,
            keywords: KeywordGroup::Expression,
            names: self::names($located, NameKind::cases(), ClassCandidateFilter::Any),
        );
    }

    /**
     * The members of what a member is accessed on, as visible from where the
     * access is written; null when what it is accessed on does not resolve.
     */
    private function memberAccess(Located $located): ?MemberAccessContext
    {
        // A static property's name is written with its `$`; a member's name is not.
        $prefix = ltrim($located->prefix, '$');
        $from = $located->scope->getSelfName();
        $receiver = $located->receiver;

        if ($located->slot === Slot::Member) {
            assert($receiver !== null && !$receiver instanceof Name, 'an instance member is accessed on an expression');
            $type = $this->expressions->resolve($receiver, $located->parsed)?->getType();
            if ($type === null || ExpressionResolver::receiverClasslikeNames($type) === []) {
                return null;
            }
            return MemberAccessContext::forInstance($type, $from, $prefix);
        }

        if (!$receiver instanceof Name) {
            return null;
        }
        $keyword = LateBindingKeyword::tryFromName($receiver->toString());
        $targetName = $located->scope->resolveClasslikeName($receiver);
        if ($targetName === null) {
            return null;
        }
        $target = new ClasslikeType($targetName);
        return $keyword === LateBindingKeyword::Parent
            ? MemberAccessContext::forParent($target, $from, $located->scope->staticFor($receiver), $prefix)
            : MemberAccessContext::forStatic($target, $from, $prefix);
    }

    private static function type(Located $located, TypeHintContext $context): Expected
    {
        return new Expected(
            $located->prefix,
            builtinTypes: $context,
            names: self::names($located, [NameKind::ClassLike], ClassCandidateFilter::TypeHint),
        );
    }

    private static function named(Located $located, ClassCandidateFilter $filter): Expected
    {
        return new Expected($located->prefix, names: self::names($located, [NameKind::ClassLike], $filter));
    }

    /**
     * @param list<NameKind> $kinds
     */
    private static function names(
        Located $located,
        array $kinds,
        ClassCandidateFilter $filter,
        bool $absolute = false,
    ): NameQuery {
        return new NameQuery(
            $kinds,
            $filter,
            $located->scope->nameContext(),
            $absolute,
        );
    }
}
