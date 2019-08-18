<?php declare(strict_types=1);

namespace Rector\TypeDeclaration\TypeInferer\PropertyTypeInferer;

use Nette\Utils\Strings;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Property;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\NullType;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\TypeDeclaration\Contract\TypeInferer\PropertyTypeInfererInterface;
use Rector\TypeDeclaration\TypeInferer\AbstractTypeInferer;
use Rector\TypeDeclaration\TypeInferer\AssignToPropertyTypeInferer;

final class AllAssignNodePropertyTypeInferer extends AbstractTypeInferer implements PropertyTypeInfererInterface
{
    /**
     * @var AssignToPropertyTypeInferer
     */
    private $assignToPropertyTypeInferer;

    public function __construct(AssignToPropertyTypeInferer $assignToPropertyTypeInferer)
    {
        $this->assignToPropertyTypeInferer = $assignToPropertyTypeInferer;
    }

    /**
     * @return string[]
     */
    public function inferProperty(Property $property): array
    {
        /** @var ClassLike|null $class */
        $class = $property->getAttribute(AttributeKey::CLASS_NODE);
        if ($class === null) {
            return [];
        }

        $propertyName = $this->nameResolver->getName($property);

        $assignedExprStaticTypes = $this->assignToPropertyTypeInferer->inferPropertyInClassLike($propertyName, $class);
        if ($assignedExprStaticTypes === []) {
            return [];
        }

        // assign after constructor/setUp, without default value is definitely nullable
        $propertyDefautValue = $property->props[0]->default;
        if ($propertyDefautValue === null || $this->isNull($propertyDefautValue)) {
            $assignedExprStaticTypes[] = new NullType();
        }

        $assignedExprStaticType = new IntersectionType($assignedExprStaticTypes);

        $objectTypes = $this->staticTypeToStringResolver->resolveObjectType($assignedExprStaticType);

        return $this->removeMixedIterableIfNotNeeded($objectTypes);
    }

    public function getPriority(): int
    {
        return 500;
    }

    /**
     * @param string[] $types
     * @return string[]
     */
    private function removeMixedIterableIfNotNeeded(array $types): array
    {
        $hasKnownObjectIterableType = $this->hasMixedAndAnotherIterableTypes($types);
        if ($hasKnownObjectIterableType) {
            foreach ($types as $key => $objectType) {
                if ($objectType === 'mixed[]') {
                    unset($types[$key]);
                }
            }
        }

        return $types;
    }

    /**
     * @param string[] $types
     */
    private function hasMixedAndAnotherIterableTypes(array $types): bool
    {
        foreach ($types as $objectType) {
            if (! Strings::endsWith($objectType, '[]')) {
                continue;
            }

            if ($objectType === 'mixed[]') {
                continue;
            }

            return true;
        }

        return false;
    }

    private function isNull(Expr $expr): bool
    {
        if (! $expr instanceof ConstFetch) {
            return false;
        }

        return $this->nameResolver->isNameInsensitive($expr, 'null');
    }
}
