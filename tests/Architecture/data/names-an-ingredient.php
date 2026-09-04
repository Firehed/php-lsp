<?php

declare(strict_types=1);

// Canary for OneRoutePerFactTest: every form in which a file can name a class.
// The scanner must report each line that names Ingredient.

namespace Firehed\PhpLsp\Tests\Architecture\Data;

use Firehed\PhpLsp\Tests\Architecture\Data\Routes\Ingredient;

// extends
final class NamesAnIngredient extends Ingredient
{
    // property type
    private Ingredient $property;

    // parameter type
    public function __construct(Ingredient $parameter)
    {
        $this->property = $parameter;
    }

    // return type
    public function build(): Ingredient
    {
        // new
        return new Ingredient();
    }

    public function forms(mixed $value): string
    {
        // static call
        Ingredient::create();
        // class constant
        $constant = Ingredient::NAME;
        // instanceof
        if ($value instanceof Ingredient) {
            // ::class
            return Ingredient::class;
        }
        try {
            return $constant;
        } catch (Ingredient $e) {
            // catch
            return '';
        }
    }
}
