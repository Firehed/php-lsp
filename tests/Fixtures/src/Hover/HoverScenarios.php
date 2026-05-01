<?php

declare(strict_types=1);

namespace Fixtures\Hover;

use Fixtures\Domain\Entity;
use Fixtures\Domain\User;
use Fixtures\Inheritance\ChildClass;
use Fixtures\Traits\HasTimestamps;

/**
 * A sample class for hover testing.
 */
class HoverScenarios
{
    use HasTimestamps;

    /** The person's full name. */
    public string $name = '';

    /** Static application name. */
    public static string $appName = 'MyApp';

    private ?Calculator $calc = null;
    private ?HoverScenarios $person = null;
    protected ?Calculator $protectedCalc = null;
    private ?Middle $middle = null;

    public function triggerClassHover(): void
    {
        $x = new /*|class_hover*/User("id", "name", "email");
    }

    public function triggerPropertyHover(): void
    {
        echo $this->/*|property_hover*/name;
    }

    public function useTypedCalculator(Calculator $calc): void
    {
        $calc->/*|typed_var_method*/add(1, 2);
    }

    public function useAssignedGreeter(): void
    {
        $greeter = new Greeter();
        $greeter->/*|assigned_var_method*/greet("World");
    }

    public function triggerVariadicMethod(): void
    {
        $logger = new Logger();
        $logger->/*|variadic_method*/log('info', 'a', 'b');
    }

    public function triggerOptionalMethod(): void
    {
        $greeter = new Greeter();
        $greeter->/*|optional_param_method*/greet('World');
    }

    public function triggerNullsafeMethod(): void
    {
        $this->calc?->/*|nullsafe_method*/multiply(2, 3);
    }

    public function triggerNullsafeProperty(): string
    {
        return $this->person?->/*|nullsafe_property*/name ?? 'Unknown';
    }

    public function useNullsafeTypedVar(?Calculator $calc): void
    {
        $calc?->/*|nullsafe_typed_var*/add(1, 2);
    }

    public function triggerNullsafeProtected(): void
    {
        $this->protectedCalc?->/*|nullsafe_protected*/divide(10, 2);
    }

    public function triggerChainedNullsafe(): void
    {
        $this->middle?->inner?->/*|chained_nullsafe*/getValue();
    }

    public function triggerStaticProperty(): void
    {
        $name = self::$/*|static_property*/appName;
    }

    public function triggerTraitProperty(): void
    {
        echo $this->/*|trait_property*/createdAt;
    }

    public function triggerTraitMethod(): void
    {
        $this->/*|trait_method*/markCreated();
    }

    public function useInterface(Entity $entity): void
    {
        $entity->/*|interface_method*/getId();
    }
}
