<?php declare(strict_types=1);

namespace Rector\TypeDeclaration\Tests\Rector\Property\FixVarDocTypePropertyRector\Fixture;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

final class AnonymousClass
{
    /**
     * @var Stmt[][]
     */
    private $nodesToAdd = [];

    public function addNodeAfterNode(Node $addedNode): void
    {
        $this->nodesToAdd[5][] = $this->wrapToExpression($addedNode);
    }

    public function isActive(): bool
    {
        return count($this->nodesToAdd) > 0;
    }

    /**
     * @param Expr|Stmt $node
     */
    private function wrapToExpression(Node $node): Stmt
    {
        return $node instanceof Stmt ? $node : new Expression($node);
    }

    public function createNodeVisitor(): NodeVisitor
    {
        return new class($this->nodesToAdd) extends NodeVisitorAbstract {
            /**
             * @var Stmt[][]
             */
            private $nodesToAdd = [];

            /**
             * @param Stmt[][] $nodesToAdd
             */
            public function __construct(array $nodesToAdd)
            {
                $this->nodesToAdd = $nodesToAdd;
            }
        };
    }
}
