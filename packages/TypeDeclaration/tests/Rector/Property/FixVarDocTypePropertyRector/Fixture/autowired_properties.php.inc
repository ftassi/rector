<?php declare(strict_types=1);

namespace Rector\TypeDeclaration\Tests\Rector\Property\FixVarDocTypePropertyRector\Fixture;

use Symfony\Component\Console\Style\SymfonyStyle;

final class AutowiredProperties
{
    /**
     * @var SymfonyStyle
     */
    private $symfonyStyle;

    /**
     * @required
     */
    public function autowireThis(SymfonyStyle $symfonyStyle)
    {
        $this->symfonyStyle = $symfonyStyle;
    }
}
