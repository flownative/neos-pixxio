<?php

namespace Flownative\Pixxio\AssetSource;

/*
 * This file is part of the Flownative.Pixxio package.
 *
 * (c) Robert Lemke, Flownative GmbH - www.flownative.com
 * (c) pixx.io GmbH - pixx.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Behat\Transliterator\Transliterator;
use Neos\Flow\Http\Uri;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\HasRemoteOriginalInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\SupportsIptcMetadataInterface;
use Neos\Media\Domain\Model\AssetSource\AssetSourceInterface;
use Neos\Media\Domain\Model\ImportedAsset;
use Neos\Media\Domain\Repository\ImportedAssetRepository;
use Neos\Utility\MediaTypes;
use Psr\Http\Message\UriInterface;
use Neos\Flow\Annotations as Flow;

/**
 *
 */
final class PixxioAssetProxy implements AssetProxyInterface, HasRemoteOriginalInterface, SupportsIptcMetadataInterface
{
    /**
     * @var PixxioAssetSource
     */
    private $assetSource;

    /**
     * @var string
     */
    private $identifier;

    /**
     * @var string
     */
    private $label;

    /**
     * @var string
     */
    private $filename;

    /**
     * @var \DateTime
     */
    private $lastModified;

    /**
     * @var int
     */
    private $fileSize;

    /**
     * @var string
     */
    private $mediaType;

    /**
     * @var array
     */
    private $iptcProperties = [];

    /**
     * @var UriInterface
     */
    private $thumbnailUri;

    /**
     * @var UriInterface
     */
    private $previewUri;

    /**
     * @var UriInterface
     */
    private $originalUri;

    /**
     * @var int
     */
    private $widthInPixels;

    /**
     * @var int
     */
    private $heightInPixels;

    /**
     * @Flow\Inject
     * @var ImportedAssetRepository
     */
    protected $importedAssetRepository;

    /**
     * @param \stdClass $jsonObject
     * @param PixxioAssetSource $assetSource
     * @return static
     */
    static public function fromJsonObject(\stdClass $jsonObject, PixxioAssetSource $assetSource)
    {
        $assetSourceOptions = $assetSource->getAssetSourceOptions();
        $pixxioOriginalMediaType = MediaTypes::getMediaTypeFromFilename('foo.' . strtolower($jsonObject->fileType));;
        $usePixxioThumbnailAsOriginal = (!isset($assetSourceOptions['mediaTypes'][$pixxioOriginalMediaType]) || $assetSourceOptions['mediaTypes'][$pixxioOriginalMediaType]['usePixxioThumbnailAsOriginal'] === false);
        $modifiedFileType = $usePixxioThumbnailAsOriginal ? 'jpg' : strtolower($jsonObject->fileType);

        $assetProxy = new static();
        $assetProxy->assetSource = $assetSource;
        $assetProxy->identifier = $jsonObject->id;
        $assetProxy->label = $jsonObject->subject;
        $assetProxy->filename = Transliterator::urlize($jsonObject->subject) . '.' . $modifiedFileType;
        $assetProxy->lastModified = new \DateTime($jsonObject->modifyDate ?? '1.1.2000');
        $assetProxy->fileSize = $jsonObject->fileSize;
        $assetProxy->mediaType = MediaTypes::getMediaTypeFromFilename('foo.' . $modifiedFileType);

        $assetProxy->iptcProperties['Title'] = $jsonObject->subject ?? '';
        $assetProxy->iptcProperties['CaptionAbstract'] = $jsonObject->description ?? '';
        $assetProxy->iptcProperties['CopyrightNotice'] = $jsonObject->dynamicMetadata->CopyrightNotice ?? '';

        $assetProxy->widthInPixels = $jsonObject->imageWidth ?? null;
        $assetProxy->heightInPixels = $jsonObject->imageHeight ?? null;

        if (isset($jsonObject->modifiedImagePaths)) {
            $modifiedImagePaths = $jsonObject->modifiedImagePaths;
            if (is_array($modifiedImagePaths)) {
                if (isset($modifiedImagePaths[0])) {
                    $assetProxy->thumbnailUri = new Uri($modifiedImagePaths[0]);
                }
                if (isset($modifiedImagePaths[1])) {
                    $assetProxy->previewUri = new Uri($modifiedImagePaths[1]);
                }
                if (isset($modifiedImagePaths[2])) {
                    $assetProxy->originalUri = new Uri($modifiedImagePaths[2]);
                }
            } else if (is_object($modifiedImagePaths)) {
                if (isset($modifiedImagePaths->{'0'})) {
                    $assetProxy->thumbnailUri = new Uri($modifiedImagePaths->{'0'});
                }
                if (isset($modifiedImagePaths->{'1'})) {
                    $assetProxy->previewUri = new Uri($modifiedImagePaths->{'1'});
                }
                if (isset($modifiedImagePaths->{'2'})) {
                    $assetProxy->originalUri = new Uri($modifiedImagePaths->{'2'});
                }
            }
        }
        if (!$usePixxioThumbnailAsOriginal && isset($jsonObject->originalPath)) {
            $assetProxy->originalUri = new Uri($jsonObject->originalPath);
        }
        return $assetProxy;
    }

    /**
     * @return AssetSourceInterface
     */
    public function getAssetSource(): AssetSourceInterface
    {
        return $this->assetSource;
    }

    /**
     * @return string
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * @return string
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @return string
     */
    public function getFilename(): string
    {
        return $this->filename;
    }

    /**
     * @return \DateTimeInterface
     */
    public function getLastModified(): \DateTimeInterface
    {
        return $this->lastModified;
    }

    /**
     * @return int
     */
    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    /**
     * @return string
     */
    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    /**
     * @param string $propertyName
     * @return bool
     */
    public function hasIptcProperty(string $propertyName): bool
    {
        return isset($this->iptcProperties[$propertyName]);
    }

    /**
     * @param string $propertyName
     * @return string
     */
    public function getIptcProperty(string $propertyName): string
    {
        return $this->iptcProperties[$propertyName] ?? '';
    }

    /**
     * @return array
     */
    public function getIptcProperties(): array
    {
        return $this->iptcProperties;
    }

    /**
     * @return int|null
     */
    public function getWidthInPixels(): ?int
    {
        return $this->widthInPixels;
    }

    /**
     * @return int|null
     */
    public function getHeightInPixels(): ?int
    {
        return $this->heightInPixels;
    }

    /**
     * @return UriInterface
     */
    public function getThumbnailUri(): ?UriInterface
    {
        return $this->thumbnailUri;
    }

    /**
     * @return UriInterface
     */
    public function getPreviewUri(): ?UriInterface
    {
        return $this->previewUri;
    }

    /**
     * @return resource
     */
    public function getImportStream()
    {
        return fopen($this->originalUri, 'rb');
    }

    /**
     * @return string
     */
    public function getLocalAssetIdentifier(): ?string
    {
        $importedAsset = $this->importedAssetRepository->findOneByAssetSourceIdentifierAndRemoteAssetIdentifier($this->assetSource->getIdentifier(), $this->identifier);
        return ($importedAsset instanceof ImportedAsset ? $importedAsset->getLocalAssetIdentifier() : null);
    }

    /**
     * @return bool
     */
    public function isImported(): bool
    {
        return true;
    }
}
