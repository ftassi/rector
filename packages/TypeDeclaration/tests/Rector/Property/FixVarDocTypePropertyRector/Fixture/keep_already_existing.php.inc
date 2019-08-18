<?php

namespace Rector\TypeDeclaration\Tests\Rector\Property\FixVarDocTypePropertyRector\Fixture;

use Rector\TypeDeclaration\Tests\Rector\Property\FixVarDocTypePropertyRector\Source\AnotherClass;

class KeepAlreadyExisting
{
    /**
     * @var AnotherClass
     */
    private $anotherClass;

    public function __construct(AnotherClass $anotherClass)
    {
        return $this->anotherClass = $anotherClass;
    }
}
