<?php declare(strict_types=1);

namespace Rector\Php\Rector\LNumber;

use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\Scalar\DNumber;
use PhpParser\Node\Scalar\LNumber;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\CodeSample;
use Rector\RectorDefinition\RectorDefinition;

/**
 * @see https://wiki.php.net/rfc/numeric_literal_separator
 * @see https://github.com/nikic/PHP-Parser/pull/615
 * @see \Rector\Php\Tests\Rector\LNumber\AddLiteralSeparatorToNumberRector\AddLiteralSeparatorToNumberRectorTest
 */
final class AddLiteralSeparatorToNumberRector extends AbstractRector
{
    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Add "_" as thousands separator in numbers', [
            new CodeSample(
                <<<'CODE_SAMPLE'
class SomeClass
{
    public function run()
    {
        $int = 1000;
        $float = 1000500.001;
    }
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
class SomeClass
{
    public function run()
    {
        $int = 1_000;
        $float = 1_000_500.001;
    }
}
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [LNumber::class, DNumber::class];
    }

    /**
     * @param LNumber|DNumber $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->isAtLeastPhpVersion('7.4')) {
            return null;
        }

        $numericValueAsString = (string) $node->value;
        if (Strings::contains($numericValueAsString, '_')) {
            return null;
        }

        $chunks = $this->strSplitNegative($numericValueAsString, 3);

        $literalSeparatedNumber = implode('_', $chunks);

        $node->value = $literalSeparatedNumber;

        return $node;
    }

    /**
     * @return string[]
     */
    private function strSplitNegative(string $string, int $length): array
    {
        $inversed = strrev($string);

        /** @var string[] $chunks */
        $chunks = str_split($inversed, $length);

        $chunks = array_reverse($chunks);
        foreach ($chunks as $key => $chunk) {
            $chunks[$key] = strrev($chunk);
        }

        return $chunks;
    }
}
