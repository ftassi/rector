<?php declare(strict_types=1);

namespace Rector\PhpParser\Printer;

use Nette\Utils\FileSystem;
use PhpParser\Node;
use Symplify\PackageBuilder\FileSystem\SmartFileInfo;

final class FormatPerservingPrinter
{
    /**
     * @var BetterStandardPrinter
     */
    private $betterStandardPrinter;

    public function __construct(BetterStandardPrinter $betterStandardPrinter)
    {
        $this->betterStandardPrinter = $betterStandardPrinter;
    }

    /**
     * @param Node[] $newStmts
     * @param Node[] $oldStmts
     * @param Node[] $oldTokens
     */
    public function printToFile(SmartFileInfo $fileInfo, array $newStmts, array $oldStmts, array $oldTokens): string
    {
        $newContent = $this->printToString($newStmts, $oldStmts, $oldTokens);

        FileSystem::write($fileInfo->getRealPath(), $newContent, $fileInfo->getPerms());

        return $newContent;
    }

    /**
     * @param Node[] $newStmts
     * @param Node[] $oldStmts
     * @param Node[] $oldTokens
     */
    public function printToString(array $newStmts, array $oldStmts, array $oldTokens): string
    {
        return $this->betterStandardPrinter->printFormatPreserving($newStmts, $oldStmts, $oldTokens);
    }
}
