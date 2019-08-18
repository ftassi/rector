<?php declare(strict_types=1);

namespace Rector\Rector\Typehint;

use PhpParser\Node;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use Rector\Exception\ShouldNotHappenException;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\Php\ParamTypeInfo;
use Rector\Php\TypeAnalyzer;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\ConfiguredCodeSample;
use Rector\RectorDefinition\RectorDefinition;

final class ParentTypehintedArgumentRector extends AbstractRector
{
    /**
     * class => [
     *      method => [
     *           argument => typehting
     *      ]
     * ]
     *
     * @var string[][][]
     */
    private $typehintForArgumentByMethodAndClass = [];

    /**
     * @var TypeAnalyzer
     */
    private $typeAnalyzer;

    /**
     * @param string[][][] $typehintForArgumentByMethodAndClass
     */
    public function __construct(TypeAnalyzer $typeAnalyzer, array $typehintForArgumentByMethodAndClass = [])
    {
        $this->typeAnalyzer = $typeAnalyzer;
        $this->typehintForArgumentByMethodAndClass = $typehintForArgumentByMethodAndClass;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Changes defined parent class typehints.', [
            new ConfiguredCodeSample(
                <<<'CODE_SAMPLE'
interface SomeInterface
{
    public read(string $content);
}

class SomeClass implements SomeInterface
{
    public read($content);
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
interface SomeInterface
{
    public read(string $content);
}

class SomeClass implements SomeInterface
{
    public read(string $content);
}
CODE_SAMPLE
                ,
                [
                    'SomeInterface' => [
                        'read' => [
                            '$content' => 'string',
                        ],
                    ],
                ]
            ),
        ]);
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [ClassMethod::class];
    }

    /**
     * @param ClassMethod $node
     */
    public function refactor(Node $node): ?Node
    {
        foreach ($this->typehintForArgumentByMethodAndClass as $type => $methodToArgumentToTypes) {
            $classNode = $node->getAttribute(AttributeKey::CLASS_NODE);
            if ($classNode === null) {
                throw new ShouldNotHappenException();
            }

            if (! $this->isType($classNode, $type)) {
                continue;
            }

            $this->processArgumentToTypes($node, $methodToArgumentToTypes);
        }

        return null;
    }

    /**
     * @param string[] $parametersToTypes
     */
    private function processClassMethodNodeWithTypehints(ClassMethod $classMethod, array $parametersToTypes): void
    {
        /** @var Param $param */
        foreach ($classMethod->params as $param) {
            foreach ($parametersToTypes as $parameter => $type) {
                $parameter = ltrim($parameter, '$');

                if (! $this->isName($param, $parameter)) {
                    continue;
                }

                if ($type === '') { // remove type
                    $param->type = null;
                } else {
                    $paramTypeInfo = new ParamTypeInfo($parameter, $this->typeAnalyzer, [$type]);
                    $param->type = $paramTypeInfo->getFqnTypeNode();
                }
            }
        }
    }

    /**
     * @param string[][] $methodToArgumentToTypes
     */
    private function processArgumentToTypes(ClassMethod $classMethod, array $methodToArgumentToTypes): void
    {
        foreach ($methodToArgumentToTypes as $method => $argumentToTypes) {
            if (! $this->isName($classMethod, $method)) {
                continue;
            }

            $this->processClassMethodNodeWithTypehints($classMethod, $argumentToTypes);
            return;
        }
    }
}
