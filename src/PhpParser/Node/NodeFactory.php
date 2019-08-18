<?php declare(strict_types=1);

namespace Rector\PhpParser\Node;

use PhpParser\BuilderFactory;
use PhpParser\BuilderHelpers;
use PhpParser\Comment\Doc;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Rector\Exception\NotImplementedException;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Php\TypeAnalyzer;

final class NodeFactory
{
    /**
     * @var BuilderFactory
     */
    private $builderFactory;

    /**
     * @var TypeAnalyzer
     */
    private $typeAnalyzer;

    public function __construct(BuilderFactory $builderFactory, TypeAnalyzer $typeAnalyzer)
    {
        $this->builderFactory = $builderFactory;
        $this->typeAnalyzer = $typeAnalyzer;
    }

    /**
     * Creates "\SomeClass::CONSTANT"
     */
    public function createClassConstant(string $className, string $constantName): ClassConstFetch
    {
        $classNameNode = in_array($className, ['static', 'parent', 'self'], true) ? new Name(
            $className
        ) : new FullyQualified($className);

        $classConstFetchNode = $this->builderFactory->classConstFetch($classNameNode, $constantName);
        $classConstFetchNode->class->setAttribute(AttributeKey::RESOLVED_NAME, $classNameNode);

        return $classConstFetchNode;
    }

    /**
     * Creates "\SomeClass::class"
     */
    public function createClassConstantReference(string $className): ClassConstFetch
    {
        $nameNode = new FullyQualified($className);

        return $this->builderFactory->classConstFetch($nameNode, 'class');
    }

    /**
     * Creates "['item', $variable]"
     *
     * @param mixed[] $items
     */
    public function createArray(array $items): Array_
    {
        $arrayItems = [];

        $defaultKey = 0;
        foreach ($items as $key => $item) {
            $customKey = $key !== $defaultKey ? $key : null;
            $arrayItems[] = $this->createArrayItem($item, $customKey);

            ++$defaultKey;
        }

        return new Array_($arrayItems);
    }

    /**
     * Creates "($args)"
     *
     * @param mixed[] $arguments
     * @return Arg[]
     */
    public function createArgs(array $arguments): array
    {
        return $this->builderFactory->args($arguments);
    }

    /**
     * Creates $this->property = $property;
     */
    public function createPropertyAssignment(string $propertyName): Assign
    {
        $variable = new Variable($propertyName);

        return $this->createPropertyAssignmentWithExpr($propertyName, $variable);
    }

    public function createPropertyAssignmentWithExpr(string $propertyName, Expr $expr): Assign
    {
        $leftExprNode = $this->createPropertyFetch('this', $propertyName);

        return new Assign($leftExprNode, $expr);
    }

    /**
     * Creates "($arg)"
     *
     * @param mixed $argument
     */
    public function createArg($argument): Arg
    {
        return new Arg(BuilderHelpers::normalizeValue($argument));
    }

    public function createPublicMethod(string $name): ClassMethod
    {
        return $this->builderFactory->method($name)
            ->makePublic()
            ->getNode();
    }

    public function createParamFromVariableInfo(VariableInfo $variableInfo): Param
    {
        $paramBuild = $this->builderFactory->param($variableInfo->getName());

        if (is_string($variableInfo->getType())) {
            $type = $this->createTypeName($variableInfo->getType());
        } else {
            $type = new Name((string) $variableInfo->getType());
        }

        $paramBuild->setType($type);

        return $paramBuild->getNode();
    }

    public function createPrivatePropertyFromVariableInfo(VariableInfo $variableInfo): Property
    {
        $docComment = $this->createVarDoc($variableInfo->getType());

        $propertyBuilder = $this->builderFactory->property($variableInfo->getName())
            ->makePrivate()
            ->setDocComment($docComment);

        return $propertyBuilder->getNode();
    }

    public function createTypeName(string $name): Name
    {
        if ($this->typeAnalyzer->isPhpReservedType($name)) {
            return new Name($name);
        }

        return new FullyQualified($name);
    }

    /**
     * @param string|Expr $variable
     * @param mixed[] $arguments
     */
    public function createMethodCall($variable, string $method, array $arguments): MethodCall
    {
        if (is_string($variable)) {
            $variable = new Variable($variable);
        }

        if ($variable instanceof PropertyFetch) {
            $variable = new PropertyFetch($variable->var, $variable->name);
        }

        if ($variable instanceof Expr\StaticPropertyFetch) {
            $variable = new Expr\StaticPropertyFetch($variable->class, $variable->name);
        }

        $methodCallNode = $this->builderFactory->methodCall($variable, $method, $arguments);

        $variable->setAttribute(AttributeKey::PARENT_NODE, $methodCallNode);

        return $methodCallNode;
    }

    /**
     * @param string|Expr $variable
     */
    public function createPropertyFetch($variable, string $property): PropertyFetch
    {
        if (is_string($variable)) {
            $variable = new Variable($variable);
        }

        return $this->builderFactory->propertyFetch($variable, $property);
    }

    /**
     * @param Param[] $params
     */
    public function createParentConstructWithParams(array $params): StaticCall
    {
        return new StaticCall(
            new Name('parent'),
            new Identifier('__construct'),
            $this->convertParamNodesToArgNodes($params)
        );
    }

    /**
     * @param mixed $item
     * @param string|int|null $key
     */
    private function createArrayItem($item, $key = null): ArrayItem
    {
        $arrayItem = null;

        if ($item instanceof Variable) {
            $arrayItem = new ArrayItem($item);
        } elseif ($item instanceof Identifier) {
            $string = new String_($item->toString());
            $arrayItem = new ArrayItem($string);
        } elseif (is_scalar($item)) {
            $itemValue = BuilderHelpers::normalizeValue($item);
            $arrayItem = new ArrayItem($itemValue);
        } elseif (is_array($item)) {
            $arrayItem = new ArrayItem($this->createArray($item));
        }

        if ($arrayItem !== null) {
            if ($key === null) {
                return $arrayItem;
            }

            $arrayItem->key = BuilderHelpers::normalizeValue($key);

            return $arrayItem;
        }

        throw new NotImplementedException(sprintf(
            'Not implemented yet. Go to "%s()" and add check for "%s" node.',
            __METHOD__,
            is_object($item) ? get_class($item) : $item
        ));
    }

    /**
     * @param string|TypeNode $type
     * @return Doc
     */
    private function createVarDoc($type): Doc
    {
        if (is_string($type)) {
            $type = $this->typeAnalyzer->isPhpReservedType($type) ? $type : '\\' . $type;
        } else {
            $type = (string) $type;
        }

        return new Doc(sprintf('/**%s * @var %s%s */', PHP_EOL, $type, PHP_EOL));
    }

    /**
     * @param Param[] $paramNodes
     * @return Arg[]
     */
    private function convertParamNodesToArgNodes(array $paramNodes): array
    {
        $argNodes = [];
        foreach ($paramNodes as $paramNode) {
            $argNodes[] = new Arg($paramNode->var);
        }

        return $argNodes;
    }
}
