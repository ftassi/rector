<?php declare(strict_types=1);

namespace Rector\CodingStyle\Rector\Class_;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\PropertyProperty;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\PhpDoc\NodeAnalyzer\DocBlockManipulator;
use Rector\PhpParser\Node\Manipulator\PropertyFetchManipulator;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;

/**
 * @see \Rector\CodingStyle\Tests\Rector\Class_\AddArrayDefaultToArrayPropertyRector\AddArrayDefaultToArrayPropertyRectorTest
 * @sponsor Thanks https://spaceflow.io/ for sponsoring this rule - visit them on https://github.com/SpaceFlow-app
 */
final class AddArrayDefaultToArrayPropertyRector extends AbstractRector
{
    /**
     * @var DocBlockManipulator
     */
    private $docBlockManipulator;

    /**
     * @var PropertyFetchManipulator
     */
    private $propertyFetchManipulator;

    public function __construct(
        DocBlockManipulator $docBlockManipulator,
        PropertyFetchManipulator $propertyFetchManipulator
    ) {
        $this->docBlockManipulator = $docBlockManipulator;
        $this->propertyFetchManipulator = $propertyFetchManipulator;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Adds array default value to property to prevent foreach over null error', [
            new CodeSample(
                <<<'CODE_SAMPLE'
class SomeClass
{
    /**
     * @var int[]
     */
    private $values;

    public function isEmpty()
    {
        return $this->values === null;
    }
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
class SomeClass
{
    /**
     * @var int[]
     */
    private $values = [];

    public function isEmpty()
    {
        return $this->values === [];
    }
}
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /**
     * @param Class_ $node
     */
    public function refactor(Node $node): ?Node
    {
        $changedProperties = $this->collectPropertyNamesWithMissingDefaultArray($node);

        $this->completeDefaultArrayToPropertyNames($node, $changedProperties);

        // $this->variable !== null && count($this->variable) > 0 → count($this->variable) > 0
        $this->clearNotNullBeforeCount($node, $changedProperties);

        // $this->variable === null → $this->variable === []
        $this->replaceNullComparisonOfArrayPropertiesWithArrayComparison($node, $changedProperties);

        return $node;
    }

    /**
     * @return string[]
     */
    private function collectPropertyNamesWithMissingDefaultArray(Class_ $class): array
    {
        $propertyNames = [];
        $this->traverseNodesWithCallable($class, function (Node $node) use (&$propertyNames) {
            if (! $node instanceof PropertyProperty) {
                return null;
            }

            if ($node->default) {
                return null;
            }

            /** @var Node\Stmt\Property $property */
            $property = $node->getAttribute(AttributeKey::PARENT_NODE);

            // we need docblock
            if ($property->getDocComment() === null) {
                return null;
            }

            $varTypeInfo = $this->docBlockManipulator->getVarTypeInfo($property);
            if ($varTypeInfo === null) {
                return null;
            }

            if (! $varTypeInfo->isIterable()) {
                return null;
            }

            // skip nullable
            if ($varTypeInfo->isNullable()) {
                return null;
            }

            $propertyNames[] = $this->getName($node);

            return null;
        });

        return $propertyNames;
    }

    /**
     * @param string[] $propertyNames
     */
    private function completeDefaultArrayToPropertyNames(Class_ $node, array $propertyNames): void
    {
        $this->traverseNodesWithCallable($node, function (Node $node) use ($propertyNames): ?PropertyProperty {
            if (! $node instanceof PropertyProperty) {
                return null;
            }

            if (! $this->isNames($node, $propertyNames)) {
                return null;
            }

            $node->default = new Array_();

            return $node;
        });
    }

    /**
     * @param string[] $propertyNames
     */
    private function replaceNullComparisonOfArrayPropertiesWithArrayComparison(
        Class_ $class,
        array $propertyNames
    ): void {
        // replace comparison to "null" with "[]"
        $this->traverseNodesWithCallable($class, function (Node $node) use ($propertyNames): ?BinaryOp {
            if (! $node instanceof BinaryOp) {
                return null;
            }

            if ($this->propertyFetchManipulator->isLocalPropertyOfNames($node->left, $propertyNames) && $this->isNull(
                $node->right
            )) {
                $node->right = new Array_();
            }

            if ($this->propertyFetchManipulator->isLocalPropertyOfNames($node->right, $propertyNames) && $this->isNull(
                $node->left
            )) {
                $node->left = new Array_();
            }

            return $node;
        });
    }

    /**
     * @param string[] $propertyNames
     */
    private function clearNotNullBeforeCount(Class_ $class, array $propertyNames): void
    {
        $this->traverseNodesWithCallable($class, function (Node $node) use ($propertyNames): ?Expr {
            if (! $node instanceof BooleanAnd) {
                return null;
            }

            // $this->value !== null
            if (! $this->isLocalPropertyOfNamesNotIdenticalToNull($node->left, $propertyNames)) {
                return null;
            }

            if ($this->isNextNodeCountingProperty($node, $propertyNames) === false) {
                return null;
            }

            return $node->right;
        });
    }

    /**
     * @param string[] $propertyNames
     */
    private function isLocalPropertyOfNamesNotIdenticalToNull(Expr $expr, array $propertyNames): bool
    {
        if (! $expr instanceof NotIdentical) {
            return false;
        }

        if ($this->propertyFetchManipulator->isLocalPropertyOfNames($expr->left, $propertyNames) && $this->isNull(
            $expr->right
        )) {
            return true;
        }

        if ($this->propertyFetchManipulator->isLocalPropertyOfNames($expr->right, $propertyNames) && $this->isNull(
            $expr->left
        )) {
            return true;
        }

        return false;
    }

    /**
     * @param string[] $propertyNames
     */
    private function isNextNodeCountingProperty(BinaryOp $binaryOp, array $propertyNames): bool
    {
        return (bool) $this->betterNodeFinder->findFirst($binaryOp->right, function (Node $node) use (
            $propertyNames
        ): ?bool {
            if (! $node instanceof Expr\FuncCall) {
                return null;
            }

            if (! $this->isName($node, 'count')) {
                return null;
            }

            if (! isset($node->args[0])) {
                return null;
            }

            $countedArgument = $node->args[0]->value;
            if (! $countedArgument instanceof PropertyFetch && ! $countedArgument instanceof Expr\StaticPropertyFetch) {
                return null;
            }

            return $this->isNames($countedArgument, $propertyNames);
        });
    }
}
