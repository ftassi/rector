<?php

namespace Rector\TypeDeclaration\Tests\Rector\Property\FixVarDocTypePropertyRector\Fixture;

class KeepMixedArray
{
    /**
     * @var mixed[]
     */
    private $methodNamesByTypesToServiceTypes = [];

    /**
     * @param mixed[] $methodNamesByTypesToServiceTypes
     */
    public function __construct(array $methodNamesByTypesToServiceTypes = [])
    {
        $this->methodNamesByTypesToServiceTypes = $methodNamesByTypesToServiceTypes;
    }
}
