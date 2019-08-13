<?php declare(strict_types=1);

namespace Rector\Php\Tests\Rector\LNumber\AddLiteralSeparatorToNumberRector;

use Rector\Php\Rector\LNumber\AddLiteralSeparatorToNumberRector;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

final class AddLiteralSeparatorToNumberRectorTest extends AbstractRectorTestCase
{
    public function test(): void
    {
        $this->doTestFiles([__DIR__ . '/Fixture/fixture.php.inc']);
    }

    protected function getRectorClass(): string
    {
        return AddLiteralSeparatorToNumberRector::class;
    }
}
