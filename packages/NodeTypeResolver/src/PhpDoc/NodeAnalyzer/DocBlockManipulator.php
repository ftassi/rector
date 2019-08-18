<?php declare(strict_types=1);

namespace Rector\NodeTypeResolver\PhpDoc\NodeAnalyzer;

use Nette\Utils\Strings;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PHPStan\PhpDocParser\Ast\PhpDoc\InvalidTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocChildNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ThrowsTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use Rector\BetterPhpDocParser\Annotation\AnnotationNaming;
use Rector\BetterPhpDocParser\Ast\NodeTraverser;
use Rector\BetterPhpDocParser\Attributes\Ast\AttributeAwareNodeFactory;
use Rector\BetterPhpDocParser\Attributes\Ast\PhpDoc\AttributeAwareParamTagValueNode;
use Rector\BetterPhpDocParser\Attributes\Ast\PhpDoc\AttributeAwarePhpDocNode;
use Rector\BetterPhpDocParser\Attributes\Ast\PhpDoc\AttributeAwarePhpDocTagNode;
use Rector\BetterPhpDocParser\Attributes\Ast\PhpDoc\AttributeAwareVarTagValueNode;
use Rector\BetterPhpDocParser\Attributes\Ast\PhpDoc\Type\AttributeAwareIdentifierTypeNode;
use Rector\BetterPhpDocParser\Attributes\Attribute\Attribute;
use Rector\BetterPhpDocParser\Attributes\Contract\Ast\AttributeAwareNodeInterface;
use Rector\BetterPhpDocParser\NodeDecorator\StringsTypePhpDocNodeDecorator;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfoFactory;
use Rector\BetterPhpDocParser\Printer\PhpDocInfoPrinter;
use Rector\CodingStyle\Application\UseAddingCommander;
use Rector\Exception\ShouldNotHappenException;
use Rector\NodeTypeResolver\Exception\MissingTagException;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\Php\ParamTypeInfo;
use Rector\NodeTypeResolver\Php\ReturnTypeInfo;
use Rector\NodeTypeResolver\Php\VarTypeInfo;
use Rector\Php\TypeAnalyzer;
use Rector\TypeDeclaration\ValueObject\IdentifierValueObject;

final class DocBlockManipulator
{
    /**
     * @var PhpDocInfoFactory
     */
    private $phpDocInfoFactory;

    /**
     * @var StringsTypePhpDocNodeDecorator
     */
    private $stringsTypePhpDocNodeDecorator;

    /**
     * @var PhpDocInfoPrinter
     */
    private $phpDocInfoPrinter;

    /**
     * @var TypeAnalyzer
     */
    private $typeAnalyzer;

    /**
     * @var AttributeAwareNodeFactory
     */
    private $attributeAwareNodeFactory;

    /**
     * @var NodeTraverser
     */
    private $nodeTraverser;

    /**
     * @var string[]
     */
    private $importedNames = [];

    /**
     * @var UseAddingCommander
     */
    private $useAddingCommander;

    public function __construct(
        PhpDocInfoFactory $phpDocInfoFactory,
        PhpDocInfoPrinter $phpDocInfoPrinter,
        TypeAnalyzer $typeAnalyzer,
        AttributeAwareNodeFactory $attributeAwareNodeFactory,
        StringsTypePhpDocNodeDecorator $stringsTypePhpDocNodeDecorator,
        NodeTraverser $nodeTraverser,
        UseAddingCommander $useAddingCommander
    ) {
        $this->phpDocInfoFactory = $phpDocInfoFactory;
        $this->phpDocInfoPrinter = $phpDocInfoPrinter;
        $this->typeAnalyzer = $typeAnalyzer;
        $this->attributeAwareNodeFactory = $attributeAwareNodeFactory;
        $this->stringsTypePhpDocNodeDecorator = $stringsTypePhpDocNodeDecorator;
        $this->nodeTraverser = $nodeTraverser;
        $this->useAddingCommander = $useAddingCommander;
    }

    public function hasTag(Node $node, string $name): bool
    {
        if ($node->getDocComment() === null) {
            return false;
        }

        // simple check
        $pattern = '#@(\\\\)?' . preg_quote(ltrim($name, '@'), '#') . '#';
        if (Strings::match($node->getDocComment()->getText(), $pattern)) {
            return true;
        }

        // advanced check, e.g. for "Namespaced\Annotations\DI"
        $phpDocInfo = $this->createPhpDocInfoFromNode($node);

        return $phpDocInfo->hasTag($name);
    }

    public function removeParamTagByName(Node $node, string $name): void
    {
        if ($node->getDocComment() === null) {
            return;
        }

        $phpDocInfo = $this->createPhpDocInfoFromNode($node);
        $this->removeParamTagByParameter($phpDocInfo, $name);

        $this->updateNodeWithPhpDocInfo($node, $phpDocInfo);
    }

    public function addTag(Node $node, PhpDocChildNode $phpDocChildNode): void
    {
        $phpDocChildNode = $this->attributeAwareNodeFactory->createFromNode($phpDocChildNode);

        if ($node->getDocComment() !== null) {
            $phpDocInfo = $this->createPhpDocInfoFromNode($node);
            $phpDocNode = $phpDocInfo->getPhpDocNode();
            $phpDocNode->children[] = $phpDocChildNode;
            $this->updateNodeWithPhpDocInfo($node, $phpDocInfo);
        } else {
            $phpDocNode = new AttributeAwarePhpDocNode([$phpDocChildNode]);
            $node->setDocComment(new Doc($phpDocNode->__toString()));
        }
    }

    public function removeTagFromNode(Node $node, string $name, bool $shouldSkipEmptyLinesAbove = false): void
    {
        if ($node->getDocComment() === null) {
            return;
        }

        $phpDocInfo = $this->createPhpDocInfoFromNode($node);

        $this->removeTagByName($phpDocInfo, $name);
        $this->updateNodeWithPhpDocInfo($node, $phpDocInfo, $shouldSkipEmptyLinesAbove);
    }

    public function changeType(Node $node, string $oldType, string $newType, bool $includeChildren = false): void
    {
        if (! $this->hasNodeTypeChangeableTags($node)) {
            return;
        }

        $phpDocInfo = $this->createPhpDocInfoFromNode($node);

        $this->replacePhpDocTypeByAnother($phpDocInfo->getPhpDocNode(), $oldType, $newType, $node, $includeChildren);

        $this->updateNodeWithPhpDocInfo($node, $phpDocInfo);
    }

    public function changeTypeIncludingChildren(Node $node, string $oldType, string $newType): void
    {
        $this->changeType($node, $oldType, $newType, true);
    }

    public function replaceAnnotationInNode(Node $node, string $oldAnnotation, string $newAnnotation): void
    {
        if ($node->getDocComment() === null) {
            return;
        }

        $phpDocInfo = $this->createPhpDocInfoFromNode($node);
        $this->replaceTagByAnother($phpDocInfo->getPhpDocNode(), $oldAnnotation, $newAnnotation);

        $this->updateNodeWithPhpDocInfo($node, $phpDocInfo);
    }

    public function getReturnTypeInfo(Node $node): ?ReturnTypeInfo
    {
        if ($node->getDocComment() === null) {
            return null;
        }

        $phpDocInfo = $this->createPhpDocInfoFromNode($node);
        $types = $phpDocInfo->getShortReturnTypes();
        if ($types === []) {
            return null;
        }

        $fqnTypes = $phpDocInfo->getReturnTypes();

        return new ReturnTypeInfo($types, $this->typeAnalyzer, $fqnTypes);
    }

    /**
     * With "name" as key
     *
     * @return ParamTypeInfo[]
     */
    public function getParamTypeInfos(Node $node): array
    {
        if ($node->getDocComment() === null) {
            return [];
        }

        $phpDocInfo = $this->createPhpDocInfoFromNode($node);
        $types = $phpDocInfo->getParamTagValues();
        if ($types === []) {
            return [];
        }

        $fqnTypes = $phpDocInfo->getParamTagValues();

        $paramTypeInfos = [];
        /** @var AttributeAwareParamTagValueNode $paramTagValueNode */
        foreach ($types as $i => $paramTagValueNode) {
            $fqnParamTagValueNode = $fqnTypes[$i];

            $paramTypeInfo = new ParamTypeInfo(
                $paramTagValueNode->parameterName,
                $this->typeAnalyzer,
                $paramTagValueNode->getAttribute(Attribute::TYPE_AS_ARRAY),
                $fqnParamTagValueNode->getAttribute(Attribute::RESOLVED_NAMES)
            );

            $paramTypeInfos[$paramTypeInfo->getName()] = $paramTypeInfo;
        }

        return $paramTypeInfos;
    }

    /**
     * @final
     * @return PhpDocTagNode[]
     */
    public function getTagsByName(Node $node, string $name): array
    {
        if ($node->getDocComment() === null) {
            return [];
        }

        $phpDocInfo = $this->createPhpDocInfoFromNode($node);

        return $phpDocInfo->getTagsByName($name);
    }

    /**
     * @param string|string[]|IdentifierValueObject|IdentifierValueObject[] $type
     */
    public function changeVarTag(Node $node, $type): void
    {
        if ($this->isCurrentTypeAlreadyAdded($type, $node)) {
            return;
        }

        $this->removeTagFromNode($node, 'var', true);
        $this->addTypeSpecificTag($node, 'var', $type);
    }

    public function addReturnTag(Node $node, string $type): void
    {
        // make sure the tags are not identical, e.g imported class vs FQN class
        $returnTypeInfo = $this->getReturnTypeInfo($node);
        if ($returnTypeInfo) {
            // already added
            if ([ltrim($type, '\\')] === $returnTypeInfo->getFqnTypes()) {
                return;
            }
        }

        $this->removeTagFromNode($node, 'return');
        $this->addTypeSpecificTag($node, 'return', $type);
    }

    /**
     * @final
     */
    public function getTagByName(Node $node, string $name): PhpDocTagNode
    {
        if (! $this->hasTag($node, $name)) {
            throw new MissingTagException(sprintf('Tag "%s" was not found at "%s" node.', $name, get_class($node)));
        }

        /** @var PhpDocTagNode[] $foundTags */
        $foundTags = $this->getTagsByName($node, $name);
        return array_shift($foundTags);
    }

    public function getVarTypeInfo(Node $node): ?VarTypeInfo
    {
        if ($node->getDocComment() === null) {
            return null;
        }

        $phpDocInfo = $this->createPhpDocInfoFromNode($node);
        $types = $phpDocInfo->getShortVarTypes();
        if ($types === []) {
            return null;
        }

        $fqnTypes = $phpDocInfo->getVarTypes();

        return new VarTypeInfo($types, $this->typeAnalyzer, $fqnTypes);
    }

    public function removeTagByName(PhpDocInfo $phpDocInfo, string $tagName): void
    {
        $phpDocNode = $phpDocInfo->getPhpDocNode();

        $tagName = AnnotationNaming::normalizeName($tagName);

        $phpDocTagNodes = $phpDocInfo->getTagsByName($tagName);

        foreach ($phpDocTagNodes as $phpDocTagNode) {
            $this->removeTagFromPhpDocNode($phpDocNode, $phpDocTagNode);
        }
    }

    public function removeParamTagByParameter(PhpDocInfo $phpDocInfo, string $parameterName): void
    {
        $phpDocNode = $phpDocInfo->getPhpDocNode();

        /** @var PhpDocTagNode[] $phpDocTagNodes */
        $phpDocTagNodes = $phpDocNode->getTagsByName('@param');

        foreach ($phpDocTagNodes as $phpDocTagNode) {
            /** @var ParamTagValueNode|InvalidTagValueNode $paramTagValueNode */
            $paramTagValueNode = $phpDocTagNode->value;

            $parameterName = '$' . ltrim($parameterName, '$');

            // process invalid tag values
            if ($paramTagValueNode instanceof InvalidTagValueNode) {
                if ($paramTagValueNode->value === $parameterName) {
                    $this->removeTagFromPhpDocNode($phpDocNode, $phpDocTagNode);
                }
                // process normal tag
            } elseif ($paramTagValueNode->parameterName === $parameterName) {
                $this->removeTagFromPhpDocNode($phpDocNode, $phpDocTagNode);
            }
        }
    }

    /**
     * @param PhpDocTagNode|PhpDocTagValueNode $phpDocTagOrPhpDocTagValueNode
     */
    public function removeTagFromPhpDocNode(PhpDocNode $phpDocNode, $phpDocTagOrPhpDocTagValueNode): void
    {
        // remove specific tag
        foreach ($phpDocNode->children as $key => $phpDocChildNode) {
            if ($phpDocChildNode === $phpDocTagOrPhpDocTagValueNode) {
                unset($phpDocNode->children[$key]);
                return;
            }
        }

        // or by type
        foreach ($phpDocNode->children as $key => $phpDocChildNode) {
            if (! $phpDocChildNode instanceof PhpDocTagNode) {
                continue;
            }

            if ($phpDocChildNode->value === $phpDocTagOrPhpDocTagValueNode) {
                unset($phpDocNode->children[$key]);
            }
        }
    }

    public function replaceTagByAnother(PhpDocNode $phpDocNode, string $oldTag, string $newTag): void
    {
        $oldTag = AnnotationNaming::normalizeName($oldTag);
        $newTag = AnnotationNaming::normalizeName($newTag);

        foreach ($phpDocNode->children as $phpDocChildNode) {
            if (! $phpDocChildNode instanceof PhpDocTagNode) {
                continue;
            }

            if ($phpDocChildNode->name === $oldTag) {
                $phpDocChildNode->name = $newTag;
            }
        }
    }

    public function replacePhpDocTypeByAnother(
        AttributeAwarePhpDocNode $attributeAwarePhpDocNode,
        string $oldType,
        string $newType,
        Node $node,
        bool $includeChildren = false
    ): AttributeAwarePhpDocNode {
        foreach ($attributeAwarePhpDocNode->children as $phpDocChildNode) {
            if (! $phpDocChildNode instanceof PhpDocTagNode) {
                continue;
            }

            if (! $this->isTagValueNodeWithType($phpDocChildNode)) {
                continue;
            }

            /** @var VarTagValueNode|ParamTagValueNode|ReturnTagValueNode $tagValueNode */
            $tagValueNode = $phpDocChildNode->value;

            $phpDocChildNode->value->type = $this->replaceTypeNode(
                $tagValueNode->type,
                $oldType,
                $newType,
                $includeChildren
            );

            $this->stringsTypePhpDocNodeDecorator->decorate($attributeAwarePhpDocNode, $node);
        }

        return $attributeAwarePhpDocNode;
    }

    /**
     * @return string[]
     */
    public function importNames(Node $node): array
    {
        if ($node->getDocComment() === null) {
            return [];
        }

        $phpDocInfo = $this->createPhpDocInfoFromNode($node);
        $phpDocNode = $phpDocInfo->getPhpDocNode();

        $this->nodeTraverser->traverseWithCallable($phpDocNode, function (
            AttributeAwareNodeInterface $docNode
        ) use ($node): AttributeAwareNodeInterface {
            if (! $docNode instanceof IdentifierTypeNode) {
                return $docNode;
            }

            // is class without namespaced name → skip
            $name = ltrim($docNode->name, '\\');
            if (! Strings::contains($name, '\\')) {
                return $docNode;
            }

            $fullyQualifiedName = $this->getFullyQualifiedName($docNode);
            $shortName = $this->getShortName($name);

            return $this->processFqnNameImport($node, $docNode, $shortName, $fullyQualifiedName);
        });

        $this->updateNodeWithPhpDocInfo($node, $phpDocInfo);

        return $this->importedNames;
    }

    /**
     * @param string[]|null $excludedClasses
     */
    public function changeUnderscoreType(Node $node, string $namespacePrefix, ?array $excludedClasses): void
    {
        if ($node->getDocComment() === null) {
            return;
        }

        $phpDocInfo = $this->createPhpDocInfoFromNode($node);
        $phpDocNode = $phpDocInfo->getPhpDocNode();

        $this->nodeTraverser->traverseWithCallable($phpDocNode, function (AttributeAwareNodeInterface $node) use (
            $namespacePrefix,
            $excludedClasses
        ): AttributeAwareNodeInterface {
            if (! $node instanceof IdentifierTypeNode) {
                return $node;
            }

            $name = ltrim($node->name, '\\');
            if (! Strings::startsWith($name, $namespacePrefix)) {
                return $node;
            }

            // excluded?
            if (is_array($excludedClasses) && in_array($name, $excludedClasses, true)) {
                return $node;
            }

            // change underscore to \\
            $nameParts = explode('_', $name);
            $node->name = '\\' . implode('\\', $nameParts);

            return $node;
        });

        $this->updateNodeWithPhpDocInfo($node, $phpDocInfo);
    }

    public function resetImportedNames(): void
    {
        $this->importedNames = [];
    }

    /**
     * For better performance
     */
    public function hasNodeTypeChangeableTags(Node $node): bool
    {
        $docComment = $node->getDocComment();
        if ($docComment === null) {
            return false;
        }

        $text = $docComment->getText();

        return (bool) Strings::match($text, '#\@(param|throws|return|var)\b#');
    }

    public function updateNodeWithPhpDocInfo(
        Node $node,
        PhpDocInfo $phpDocInfo,
        bool $shouldSkipEmptyLinesAbove = false
    ): bool {
        // skip if has no doc comment
        if ($node->getDocComment() === null) {
            return false;
        }

        $phpDoc = $this->phpDocInfoPrinter->printFormatPreserving($phpDocInfo, $shouldSkipEmptyLinesAbove);
        if ($phpDoc !== '') {
            // no change, don't save it
            if ($node->getDocComment()->getText() === $phpDoc) {
                return false;
            }

            $node->setDocComment(new Doc($phpDoc));
            return true;
        }

        // no comments, null
        $node->setAttribute('comments', null);

        return true;
    }

    public function createPhpDocInfoFromNode(Node $node): PhpDocInfo
    {
        if ($node->getDocComment() === null) {
            throw new ShouldNotHappenException(sprintf(
                'Node must have a comment. Check `$node->getDocComment() !== null` before passing it to %s',
                __METHOD__
            ));
        }

        return $this->phpDocInfoFactory->createFromNode($node);
    }

    /**
     * All class-type tags are FQN by default to keep default convention through the code.
     * Some people prefer FQN, some short. FQN can be shorten with \Rector\CodingStyle\Rector\Namespace_\ImportFullyQualifiedNamesRector later, while short prolonged not
     * @param string|string[]|IdentifierValueObject|IdentifierValueObject[] $type
     */
    private function addTypeSpecificTag(Node $node, string $name, $type): void
    {
        if (! is_array($type)) {
            $type = [$type];
        }

        foreach ($type as $key => $singleType) {
            // prefix possible class name
            $type[$key] = $this->preslashFullyQualifiedNames($singleType);
        }

        $type = implode('|', $type);

        // there might be no phpdoc at all
        if ($node->getDocComment() !== null) {
            $phpDocInfo = $this->createPhpDocInfoFromNode($node);
            $phpDocNode = $phpDocInfo->getPhpDocNode();

            $varTagValueNode = new AttributeAwareVarTagValueNode(new AttributeAwareIdentifierTypeNode($type), '', '');
            $phpDocNode->children[] = new AttributeAwarePhpDocTagNode('@' . $name, $varTagValueNode);

            $this->updateNodeWithPhpDocInfo($node, $phpDocInfo);
        } else {
            // create completely new docblock
            $varDocComment = sprintf("/**\n * @%s %s\n */", $name, $type);
            $node->setDocComment(new Doc($varDocComment));
        }
    }

    private function isTagValueNodeWithType(PhpDocTagNode $phpDocTagNode): bool
    {
        return $phpDocTagNode->value instanceof ParamTagValueNode ||
            $phpDocTagNode->value instanceof VarTagValueNode ||
            $phpDocTagNode->value instanceof ReturnTagValueNode ||
            $phpDocTagNode->value instanceof ThrowsTagValueNode;
    }

    private function replaceTypeNode(
        TypeNode $typeNode,
        string $oldType,
        string $newType,
        bool $includeChildren = false
    ): TypeNode {
        // @todo use $this->nodeTraverser->traverseWithCallable here matching "AttributeAwareIdentifierTypeNode"

        if ($typeNode instanceof AttributeAwareIdentifierTypeNode) {
            $nodeType = $this->resolveNodeType($typeNode);

            // by default do not override subtypes, can actually use parent type (race condition), which is not desired
            // see: $includeChildren
            if (($includeChildren && is_a($nodeType, $oldType, true)) || ltrim($nodeType, '\\') === $oldType) {
                $newType = $this->forceFqnPrefix($newType);

                return new AttributeAwareIdentifierTypeNode($newType);
            }
        }

        if ($typeNode instanceof UnionTypeNode) {
            foreach ($typeNode->types as $key => $subTypeNode) {
                $typeNode->types[$key] = $this->replaceTypeNode($subTypeNode, $oldType, $newType, $includeChildren);
            }
        }

        if ($typeNode instanceof ArrayTypeNode) {
            $typeNode->type = $this->replaceTypeNode($typeNode->type, $oldType, $newType, $includeChildren);

            return $typeNode;
        }

        return $typeNode;
    }

    /**
     * @param AttributeAwareNodeInterface&TypeNode $typeNode
     */
    private function resolveNodeType(TypeNode $typeNode): string
    {
        $nodeType = $typeNode->getAttribute(Attribute::RESOLVED_NAME);
        if ($nodeType === null) {
            $nodeType = $typeNode->getAttribute(Attribute::TYPE_AS_STRING);
        }

        if ($nodeType === null) {
            $nodeType = $typeNode->name;
        }

        return $nodeType;
    }

    private function forceFqnPrefix(string $newType): string
    {
        if (Strings::contains($newType, '\\')) {
            $newType = '\\' . ltrim($newType, '\\');
        }

        return $newType;
    }

    private function getShortName(string $name): string
    {
        return Strings::after($name, '\\', -1) ?: $name;
    }

    /**
     * @param AttributeAwareNodeInterface|AttributeAwareIdentifierTypeNode $attributeAwareNode
     */
    private function getFullyQualifiedName(AttributeAwareNodeInterface $attributeAwareNode): string
    {
        if ($attributeAwareNode->getAttribute(Attribute::RESOLVED_NAME)) {
            $fqnName = $attributeAwareNode->getAttribute(Attribute::RESOLVED_NAME);
        } else {
            $fqnName = $attributeAwareNode->getAttribute(Attribute::RESOLVED_NAMES)[0] ?? $attributeAwareNode->name;
        }

        return ltrim($fqnName, '\\');
    }

    /**
     * @param AttributeAwareIdentifierTypeNode $attributeAwareNode
     */
    private function processFqnNameImport(
        Node $node,
        AttributeAwareNodeInterface $attributeAwareNode,
        string $shortName,
        string $fullyQualifiedName
    ): AttributeAwareNodeInterface {
        // the name is already in the same namespace implicitly
        $namespaceName = $node->getAttribute(AttributeKey::NAMESPACE_NAME);

        // the class in the same namespace as differnt file can se used in this code, the short names would colide → skip
        if (class_exists($namespaceName . '\\' . $shortName)) {
            return $attributeAwareNode;
        }

        if ($this->useAddingCommander->isShortImported($node, $fullyQualifiedName)) {
            if ($this->useAddingCommander->isImportShortable($node, $fullyQualifiedName)) {
                $attributeAwareNode->name = $shortName;
            }

            return $attributeAwareNode;
        }

        $attributeAwareNode->name = $shortName;
        $this->useAddingCommander->addUseImport($node, $fullyQualifiedName);

        return $attributeAwareNode;
    }

    /**
     * @param string|IdentifierValueObject $type
     */
    private function preslashFullyQualifiedNames($type): string
    {
        if ($type instanceof IdentifierValueObject) {
            if ($type->isAlias()) {
                return $type->getName();
            }

            $type = $type->getName();
        }

        $joinChar = '|'; // default
        if (Strings::contains($type, '|')) { // intersection
            $types = explode('|', $type);
            $joinChar = '|';
        } elseif (Strings::contains($type, '&')) { // union
            $types = explode('&', $type);
            $joinChar = '&';
        } else {
            $types = [$type];
        }

        foreach ($types as $key => $singleType) {
            if ($this->typeAnalyzer->isPhpReservedType($singleType)) {
                continue;
            }

            $types[$key] = '\\' . ltrim($singleType, '\\');
        }

        return implode($joinChar, $types);
    }

    /**
     * @param string|string[]|IdentifierValueObject|IdentifierValueObject[] $type
     */
    private function isCurrentTypeAlreadyAdded($type, Node $node): bool
    {
        // make sure the tags are not identical, e.g imported class vs FQN class
        $varTagInfo = $this->getVarTypeInfo($node);
        if ($varTagInfo === null) {
            return false;
        }

        if (is_array($type)) {
            foreach ($type as $key => $singleType) {
                if (is_string($singleType)) {
                    $type[$key] = ltrim($singleType, '\\');
                }
            }
        }

        $currentTypes = $varTagInfo->getFqnTypes();
        if ($type === $currentTypes) {
            return true;
        }

        if (is_array($type)) {
            sort($currentTypes);
            sort($type);

            return $currentTypes === $type;
        }

        return false;
    }
}
