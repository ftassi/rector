<?php declare(strict_types=1);

namespace Rector\TypeDeclaration\Tests\Rector\Property\FixVarDocTypePropertyRector;

use Rector\Testing\PHPUnit\AbstractRectorTestCase;
use Rector\TypeDeclaration\Rector\Property\FixVarDocTypePropertyRector;

final class FixVarDocTypePropertyRectorTest extends AbstractRectorTestCase
{
    public function test(): void
    {
        $this->doTestFiles([
            __DIR__ . '/Fixture/fixture.php.inc',
            __DIR__ . '/Fixture/anonymous_class.php.inc',
            __DIR__ . '/Fixture/make_priority_win.php.inc',
            __DIR__ . '/Fixture/argument_with_default.php.inc',
            // keep
            __DIR__ . '/Fixture/skip_different_order.php.inc',
            __DIR__ . '/Fixture/keep_already_existing.php.inc',
            __DIR__ . '/Fixture/keep_mixed_array.php.inc',
        ]);
    }

    protected function getRectorClass(): string
    {
        return FixVarDocTypePropertyRector::class;
    }
}
