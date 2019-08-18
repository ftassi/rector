<?php declare(strict_types=1);

namespace Rector\TypeDeclaration\Tests\Rector\Property\FixVarDocTypePropertyRector\Fixture;

final class SkipDifferentOrder
{
    /**
     * @var string[]|string[][]
     */
    private $oldFunctionToNewFunction = [];

    /**
     * @param string[]|string[][] $oldFunctionToNewFunction
     */
    public function __construct(array $oldFunctionToNewFunction = [])
    {
        $this->oldFunctionToNewFunction = $oldFunctionToNewFunction;
    }
}
