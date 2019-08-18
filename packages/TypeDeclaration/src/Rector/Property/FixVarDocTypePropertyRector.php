<?php declare(strict_types=1);

namespace Rector\TypeDeclaration\Rector\Property;

use PhpParser\Node;
use PhpParser\Node\Stmt\Property;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\PhpDoc\NodeAnalyzer\DocBlockManipulator;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;
use Rector\TypeDeclaration\TypeInferer\PropertyTypeInferer;

/**
 * @sponsor Thanks https://spaceflow.io/ for sponsoring this rule - visit them on https://github.com/SpaceFlow-app
 * @see \Rector\TypeDeclaration\Tests\Rector\Property\FixVarDocTypePropertyRector\FixVarDocTypePropertyRectorTest
 */
final class FixVarDocTypePropertyRector extends AbstractRector
{
    /**
     * @var PropertyTypeInferer
     */
    private $propertyTypeInferer;

    /**
     * @var DocBlockManipulator
     */
    private $docBlockManipulator;

    public function __construct(PropertyTypeInferer $propertyTypeInferer, DocBlockManipulator $docBlockManipulator)
    {
        $this->propertyTypeInferer = $propertyTypeInferer;
        $this->docBlockManipulator = $docBlockManipulator;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Fix @var annotation based on strong types in the code', [
            new CodeSample(
                <<<'CODE_SAMPLE'
class SomeClass
{
    /**
     * @var string
     */
    private $name;

    public function __construct(?string $name)
    {
        return $this->name = $name;
    }
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
class SomeClass
{
    /**
     * @var string|null
     */
    private $name;

    public function __construct(?string $name)
    {
        return $this->name = $name;
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
        return [Property::class];
    }

    /**
     * @param Property $node
     */
    public function refactor(Node $node): ?Node
    {
        /** @var Node\Stmt\Class_|null $classNode */
        $classNode = $node->getAttribute(AttributeKey::CLASS_NODE);
        // skip anonymous classes, as hard to resolve correctly
        if ($classNode === null || ($classNode instanceof Node\Stmt\Class_ && $classNode->isAnonymous())) {
            return null;
        }

        $types = $this->propertyTypeInferer->inferProperty($node);
        if ($types) {
            $this->docBlockManipulator->changeVarTag($node, $types);
        }

        return $node;
    }
}
