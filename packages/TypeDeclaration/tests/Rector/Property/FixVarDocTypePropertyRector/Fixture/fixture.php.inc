<?php

namespace Rector\TypeDeclaration\Tests\Rector\Property\FixVarDocTypePropertyRector\Fixture;

class SomeClass
{
    /**
     * @var string
     */
    private $name;

    public function __construct(?string $name)
    {
        return $this->name = $name;
    }
}

?>
-----
<?php

namespace Rector\TypeDeclaration\Tests\Rector\Property\FixVarDocTypePropertyRector\Fixture;

class SomeClass
{
    /**
     * @var string|null
     */
    private $name;

    public function __construct(?string $name)
    {
        return $this->name = $name;
    }
}

?>
