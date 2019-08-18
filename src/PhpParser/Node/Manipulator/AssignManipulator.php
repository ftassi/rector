<?php declare(strict_types=1);

namespace Rector\PhpParser\Node\Manipulator;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use Rector\PhpParser\Node\Resolver\NameResolver;

final class AssignManipulator
{
    /**
     * @var NameResolver
     */
    private $nameResolver;

    public function __construct(NameResolver $nameResolver)
    {
        $this->nameResolver = $nameResolver;
    }

    /**
     * Checks:
     * $this->x = y;
     */
    public function isLocalPropertyAssign(Node $node): bool
    {
        if (! $node instanceof Assign) {
            return false;
        }

        if (! $node->var instanceof PropertyFetch) {
            return false;
        }

        if (! $node->var->var instanceof Variable) {
            return false;
        }

        return $this->nameResolver->isName($node->var->var, 'this');
    }

    /**
     * Is: "$this->value = <$value>"
     *
     * @param string[] $propertyNames
     */
    public function isLocalPropertyAssignWithPropertyNames(Node $node, array $propertyNames): bool
    {
        if (! $this->isLocalPropertyAssign($node)) {
            return false;
        }

        /** @var Assign $node */
        $propertyFetch = $node->expr;

        /** @var PropertyFetch $propertyFetch */
        return $this->nameResolver->isNames($propertyFetch, $propertyNames);
    }
}
