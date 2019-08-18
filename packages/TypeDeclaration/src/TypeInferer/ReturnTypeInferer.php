<?php declare(strict_types=1);

namespace Rector\TypeDeclaration\TypeInferer;

use PhpParser\Node\FunctionLike;
use Rector\TypeDeclaration\Contract\TypeInferer\FunctionLikeReturnTypeInfererInterface;

final class ReturnTypeInferer
{
    /**
     * @var FunctionLikeReturnTypeInfererInterface[]
     */
    private $functionLikeReturnTypeInferers = [];

    /**
     * @param FunctionLikeReturnTypeInfererInterface[] $functionLikeReturnTypeInferers
     */
    public function __construct(array $functionLikeReturnTypeInferers)
    {
        $this->functionLikeReturnTypeInferers = $functionLikeReturnTypeInferers;
    }

    public function inferFunctionLike(FunctionLike $functionLike): ?array
    {
        foreach ($this->functionLikeReturnTypeInferers as $functionLikeReturnTypeInferer) {
            $types = $functionLikeReturnTypeInferer->inferFunctionLike($functionLike);
            if ($types) {
                return $types;
            }
        }

        return null;
    }
}
