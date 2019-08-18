<?php declare(strict_types=1);

namespace Rector\Php\Rector\Each;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\While_;
use Rector\PhpParser\Node\Manipulator\AssignManipulator;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;

/**
 * @source https://wiki.php.net/rfc/deprecations_php_7_2#each
 */
final class WhileEachToForeachRector extends AbstractRector
{
    /**
     * @var AssignManipulator
     */
    private $assignManipulator;

    public function __construct(AssignManipulator $assignManipulator)
    {
        $this->assignManipulator = $assignManipulator;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition(
            'each() function is deprecated, use foreach() instead.',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
while (list($key, $callback) = each($callbacks)) {
    // ...
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
foreach ($callbacks as $key => $callback) {
    // ...
}
CODE_SAMPLE
                ),
                new CodeSample(
                    <<<'CODE_SAMPLE'
while (list($key) = each($callbacks)) {
    // ...
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
foreach (array_keys($callbacks) as $key) {
    // ...
}
CODE_SAMPLE
                ),
            ]
        );
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [While_::class];
    }

    /**
     * @param While_ $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $node->cond instanceof Assign) {
            return null;
        }

        /** @var Assign $assignNode */
        $assignNode = $node->cond;
        if (! $this->assignManipulator->isListToEachAssign($assignNode)) {
            return null;
        }

        /** @var FuncCall $eachFuncCall */
        $eachFuncCall = $assignNode->expr;

        /** @var List_ $listNode */
        $listNode = $assignNode->var;

        $foreachedExpr = count($listNode->items) === 1 ? $this->createFunction(
            'array_keys',
            [$eachFuncCall->args[0]]
        ) : $eachFuncCall->args[0]->value;

        /** @var ArrayItem $valueItem */
        $valueItem = array_pop($listNode->items);
        $foreachNode = new Foreach_($foreachedExpr, $valueItem, [
            'stmts' => $node->stmts,
        ]);

        // is key included? add it to foreach
        if (count($listNode->items) > 0) {
            /** @var ArrayItem $keyItem */
            $keyItem = array_pop($listNode->items);
            $foreachNode->keyVar = $keyItem->value;
        }

        return $foreachNode;
    }
}
