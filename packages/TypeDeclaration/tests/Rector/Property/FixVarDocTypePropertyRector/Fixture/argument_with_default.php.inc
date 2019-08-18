<?php declare(strict_types=1);

namespace Rector\TypeDeclaration\Tests\Rector\Property\FixVarDocTypePropertyRector\Fixture;

use PhpParser\NodeTraverser;
use Rector\Contract\Rector\PhpRectorInterface;
use Rector\Testing\Application\EnabledRectorsProvider;

final class ArgumentWithDefault extends NodeTraverser
{
    /**
     * @var PhpRectorInterface[]
     */
    private $allPhpRectors = [];

    /**
     * @param PhpRectorInterface[] $phpRectors
     */
    public function __construct(array $phpRectors = [])
    {
        $this->allPhpRectors = $phpRectors;
    }
}
