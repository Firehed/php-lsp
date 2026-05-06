<?php

declare(strict_types=1);

namespace Fixtures\Domain;

use Fixtures\Enum\Status;
use Fixtures\Traits\HasTimestamps;

/**
 * Represents a system user.
 *
 * Users can authenticate and perform actions within the application.
 */
class User implements Entity, Person
{
    use HasTimestamps;

    public const DEFAULT_ROLE = 'user';

    private static int $instanceCount = 0; //hover:property_declaration

    public function __construct(
        private readonly string $id,
        private string $name,
        private string $email,
        private int $age = 0,
        private Status $status = Status::Active,
        public ?self $manager = null,
        public ?Team $team = null,
    ) {
        self::$instanceCount++;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Updates the user's display name.
     */
    public function setName(string $name): void
    {
        $this->name = $name; /*|inside_setName*/
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getAge(): int
    {
        return $this->age;
    }

    public function getStatus(): Status
    {
        return $this->status;
    }

    /**
     * Gets the user's team.
     */
    public function getTeam(): ?Team
    {
        return $this->team;
    }

    public function activate(): void
    {
        $this->status = Status::Active;
    }

    public function deactivate(): void
    {
        $this->status = Status::Inactive;
    }

    public function isActive(): bool
    {
        return $this->status === Status::Active;
    }

    /**
     * Sets the name fluently.
     */
    public function withName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Sets the age fluently.
     */
    public function withAge(int $age): self
    {
        $this->age = $age;
        return $this;
    }

    /**
     * Sets the status fluently.
     */
    public function withStatus(Status $status): self
    {
        $this->status = $status;
        return $this;
    }

    public static function getInstanceCount(): int
    {
        return self::$instanceCount;
    }

    /** Creates a new user instance. */
    public static function create(string $id, string $name, string $email): self
    {
        return new self($id, $name, $email);
    }

    public function triggerSigThis(): void
    {
        $this->setName(/*|sig_this_call*/"name");
    }

    public function triggerSigSelf(): self
    {
        return self::create(/*|sig_self_call*/"id", "name", "email");
    }

    public function triggerSigNullsafe(): void
    {
        $this->manager?->setName(/*|sig_nullsafe_property*/"name");
    }

    public function triggerHoverMethod(): void
    {
        $this->setName("name"); //hover:setName
    }

    public function triggerHoverProperty(): void
    {
        echo $this->manager; //hover:manager
    }

    public function triggerHoverStaticMethod(): self
    {
        return User::create("id", "name", "email"); //hover:create
    }

    public function triggerHoverNullsafeMethod(): void
    {
        $this->manager?->setName("name"); //hover:setName_nullsafe
    }

    public function triggerHoverNullsafeProperty(): void
    {
        echo $this->manager?->manager; //hover:manager_nullsafe
    }

    public function triggerHoverTraitMethod(): void
    {
        $this->markCreated(); //hover:markCreated
    }

    public function triggerHoverMultilineChain(): void
    {
        $this
            ->withName('test') //hover:chain_method
            ->team //hover:chain_property
            ?->getLeader() //hover:chain_cross_type
            ->manager //hover:chain_back_to_user
            ?->withAge(30); //hover:chain_nullsafe
    }

    public static function triggerDefViaAssignment(): void
    {
        $user = new User('1', 'name', 'email');
        $user->setName('new'); //hover:method_via_assignment
    }

    public static function triggerDefNullsafeViaAssignment(): void
    {
        $user = rand() ? new User('1', 'name', 'email') : null;
        $user?->setName('new'); //hover:nullsafe_via_assignment
    }

    public function triggerHoverVariable(): void
    {
        $typed = $this->manager;
        echo $typed; //hover:variable_typed /*|after_assignment*/
    }

    public function triggerNestedVariables(): void
    {
        $outer = 1;
        if (true) {
            $inner = 2;
            echo $inner; /*|inside_nested*/
        }
        echo $outer; /*|before_after*/
        $after = 3;
    }

    public function triggerUnknownClass(): void
    {
        new UnknownClass(); //hover:unknown_class
    }

    public function triggerUnknownProperty(): void
    {
        echo $this->unknownProperty; //hover:unknown_property
    }

    public function triggerUntypedProperty(): void
    {
        $untyped = $this->getUnknown();
        echo $untyped->prop; //hover:untyped_property
    }

    public function triggerLiteralHover(): int //hover:method_name
    {
        return 42; //hover:literal_number
    }

    public function triggerUnknownConstant(): void
    {
        echo self::UNKNOWN_CONSTANT; //hover:unknown_constant
    }

    public function triggerSigNew(): self
    {
        return new User(/*|sig_new*/'1', 'name', 'email');
    }

    public function triggerSigBuiltinFunc(): int
    {
        return strlen(/*|sig_builtin_func*/'test'); //hover:builtin_strlen
    }

    public function triggerDynamicMethodCall(): void
    {
        $method = 'setName';
        $this->$method(/*|sig_dynamic_method*/'name');
    }

    public function triggerDynamicStaticCall(): void
    {
        $method = 'create';
        self::$method(/*|sig_dynamic_static*/'1', 'n', 'e');
    }

    public function triggerDynamicFuncCall(): void
    {
        $func = 'strlen';
        $func(/*|sig_dynamic_func*/'test');
    }

    public function triggerDynamicNew(): self
    {
        $class = self::class;
        return new $class(/*|sig_dynamic_new*/'1', 'n', 'e');
    }

    public function triggerVariableVariable(): void
    {
        $name = 'foo';
        $$name = 'bar'; //hover:variable_variable
        echo /*|outer_var_var*/$$name;
    }

    public function triggerDynamicProperty(): void
    {
        $prop = 'name';
        echo $this->$prop; //hover:dynamic_property
    }

    public function triggerDynamicConstant(): void
    {
        $const = 'DEFAULT_ROLE';
        echo self::$const; //hover:dynamic_constant
    }

    public function triggerComputedClassStatic(): void
    {
        $class = self::class;
        $class::create(/*|sig_computed_class*/'1', 'n', 'e');
    }

    public function triggerUntypedMethodCall(): void
    {
        $untyped = $this->getUnknown();
        $untyped->foo(/*|sig_untyped_method*/);
    }

    public function triggerNonexistentMethodCall(): void
    {
        $this->nonexistentMethod(/*|sig_nonexistent_method*/);
    }

    public function triggerNonexistentStaticMethod(): void
    {
        self::nonexistentStatic(/*|sig_nonexistent_static*/);
    }
}
