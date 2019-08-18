<?php declare(strict_types=1);

namespace Rector\TypeDeclaration\TypeInferer\PropertyTypeInferer;

use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Type\NullType;
use PHPStan\Type\Type;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\PhpDoc\NodeAnalyzer\DocBlockManipulator;
use Rector\PhpParser\Node\Manipulator\PropertyFetchManipulator;
use Rector\TypeDeclaration\Contract\TypeInferer\PropertyTypeInfererInterface;
use Rector\TypeDeclaration\TypeInferer\AbstractTypeInferer;
use Rector\TypeDeclaration\ValueObject\IdentifierValueObject;

final class ConstructorPropertyTypeInferer extends AbstractTypeInferer implements PropertyTypeInfererInterface
{
    /**
     * @var PropertyFetchManipulator
     */
    private $propertyFetchManipulator;

    /**
     * @var DocBlockManipulator
     */
    private $docBlockManipulator;

    public function __construct(
        PropertyFetchManipulator $propertyFetchManipulator,
        DocBlockManipulator $docBlockManipulator
    ) {
        $this->propertyFetchManipulator = $propertyFetchManipulator;
        $this->docBlockManipulator = $docBlockManipulator;
    }

    /**
     * @return string[]|IdentifierValueObject[]
     */
    public function inferProperty(Node\Stmt\Property $property): array
    {
        /** @var Class_|Trait_|Interface_|null $class */
        $class = $property->getAttribute(AttributeKey::CLASS_NODE);
        if ($class === null) {
            return [];
        }

        $instantiatingClassMethods = $this->resolveInstantiatingClassMethods($class);

        $types = [];
        foreach ($instantiatingClassMethods as $instantiatingClassMethod) {
            $classMethodTypes = $this->inferTypesFromClassMethod($property, $instantiatingClassMethod);
            $types = array_merge($types, $classMethodTypes);
        }

        return array_unique($types);
    }

    public function getPriority(): int
    {
        return 800;
    }

    private function resolveParamStaticType(ClassMethod $classMethod, string $propertyName): ?Type
    {
        $firstAssignedVariable = $this->propertyFetchManipulator->getFirstVariableAssignedToPropertyOfName(
            $classMethod,
            $propertyName
        );

        if ($firstAssignedVariable === null) {
            return null;
        }

        return $this->nodeTypeResolver->getNodeStaticType($firstAssignedVariable);
    }

    private function removePreSlash(string $content): string
    {
        return ltrim($content, '\\');
    }

    private function isParamNullable(Param $param): bool
    {
        if ($param->type instanceof NullableType) {
            return true;
        }

        if ($param->default) {
            $defaultValueStaticType = $this->nodeTypeResolver->getNodeStaticType($param->default);
            if ($defaultValueStaticType instanceof NullType) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return IdentifierValueObject|string|null
     */
    private function resolveParamTypeToString(Param $param)
    {
        if ($param->type === null) {
            return null;
        }

        if ($param->type instanceof NullableType) {
            return $this->nameResolver->getName($param->type->type);
        }

        // special case for alias
        if ($param->type instanceof Node\Name\FullyQualified) {
            $fullyQualifiedName = $param->type->toString();
            $originalName = $param->type->getAttribute('originalName');

            if ($fullyQualifiedName && $originalName instanceof Node\Name) {
                // if the FQN has different ending than the original, it was aliased and we need to return the alias
                if (! Strings::endsWith($fullyQualifiedName, '\\' . $originalName->toString())) {
                    return new IdentifierValueObject($originalName->toString(), true);
                }
            }
        }

        return $this->nameResolver->getName($param->type);
    }

    /**
     * @return string[]
     */
    private function resolveMoreSpecificArrayType(ClassMethod $classMethod, string $propertyName): array
    {
        $paramStaticTypeAsString = $this->getResolveParamStaticTypeAsString($classMethod, $propertyName);
        if ($paramStaticTypeAsString) {
            return explode('|', $paramStaticTypeAsString);
        }

        return ['mixed[]'];
    }

    private function getResolveParamStaticTypeAsString(ClassMethod $classMethod, string $propertyName): ?string
    {
        $paramStaticType = $this->resolveParamStaticType($classMethod, $propertyName);
        if ($paramStaticType === null) {
            return null;
        }

        $typesAsStrings = $this->staticTypeToStringResolver->resolveObjectType($paramStaticType);

        foreach ($typesAsStrings as $i => $typesAsString) {
            $typesAsStrings[$i] = $this->removePreSlash($typesAsString);
        }

        return implode('|', $typesAsStrings);
    }

    private function inferTypesFromClassMethod(Node\Stmt\Property $property, ClassMethod $classMethod): array
    {
        $propertyName = $this->nameResolver->getName($property);

        $param = $this->propertyFetchManipulator->resolveParamForPropertyFetch($classMethod, $propertyName);
        if ($param) {
            // A. infer from type declaration of parameter
            if ($param->type) {
                return $this->inferFromParamType($classMethod, $param, $propertyName);
            }
        }

        // B. from assigns
        $assignedExprs = $this->propertyFetchManipulator->getExprsAssignedToPropertyName($classMethod, $propertyName);

        $types = [];

        foreach ($assignedExprs as $assignedExpr) {
            $assignedExprStaticType = $this->nodeTypeResolver->getNodeStaticType($assignedExpr);
            $assignedExprStaticTypeAsString = $this->staticTypeToStringResolver->resolveObjectType(
                $assignedExprStaticType
            );
            $types = array_merge($types, $assignedExprStaticTypeAsString);
        }

        return array_unique($types);
    }

    /**
     * @param Class_|Trait_|Interface_ $classLike
     * @return ClassMethod[]
     */
    private function resolveInstantiatingClassMethods(ClassLike $classLike): array
    {
        $instantiatingClassMethods = [];
        foreach ((array) $classLike->stmts as $classStmt) {
            if (! $classStmt instanceof ClassMethod) {
                continue;
            }

            if ($this->nameResolver->isNames($classStmt, ['__construct', 'setUp'])) {
                $instantiatingClassMethods[] = $classStmt;
                continue;
            }

            // autowired on creation by Symfony
            if ($this->docBlockManipulator->hasTag($classStmt, 'required')) {
                $instantiatingClassMethods[] = $classStmt;
            }
        }

        return $instantiatingClassMethods;
    }

    private function inferFromParamType(ClassMethod $classMethod, Param $param, string $propertyName): array
    {
        $type = $this->resolveParamTypeToString($param);
        if ($type === null) {
            return [];
        }

        $types = [];

        // it's an array - annotation → make type more precise, if possible
        if ($type === 'array') {
            $types = $this->resolveMoreSpecificArrayType($classMethod, $propertyName);
        } else {
            $types[] = $type;
        }

        if ($this->isParamNullable($param)) {
            $types[] = 'null';
        }

        return array_unique($types);
    }
}
