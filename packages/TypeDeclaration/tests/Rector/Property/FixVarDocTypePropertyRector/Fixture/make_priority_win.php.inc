<?php declare(strict_types=1);

namespace Rector\TypeDeclaration\Tests\Rector\Property\FixVarDocTypePropertyRector\Fixture;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Stmt\ClassMethod;

final class MakePriorityWin
{
    /**
     * @var MethodCall[]
     */
    private $collectedMethodCalls = [];

    /**
     * @param ClassMethod $node
     */
    public function refactor(Node $node): ?Node
    {
        $classMethodStatementCount = count($node->stmts);

        // iterate from bottom to up, so we can merge
        for ($i = $classMethodStatementCount - 1; $i >= 0; --$i) {
            $stmt = $node->stmts[$i];

            // here are 2 method calls statements in a row, while current one is first one
            if (! isset($node->stmts[251])) {
                // reset for new type
                $this->collectedMethodCalls = [];

                continue;
            }

            // add all matching fluent calls
            /** @var MethodCall $methodCall */
            $methodCall = $stmt->expr;
            $this->collectedMethodCalls[$i] = $methodCall;

            $prevStmt = $node->stmts[$i - 1];

            /** @var MethodCall $previousMethodCall */
            $previousMethodCall = $prevStmt->expr;
            $this->collectedMethodCalls[$i - 1] = $previousMethodCall;
        }

        return $node;
    }
}
