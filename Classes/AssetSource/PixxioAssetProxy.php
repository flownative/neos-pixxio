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

use Exception;
use Flownative\Pixxio\Exception\ConnectionException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Uri;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\HasRemoteOriginalInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\SupportsIptcMetadataInterface;
use Neos\Media\Domain\Model\AssetSource\AssetSourceInterface;
use Neos\Media\Domain\Model\ImportedAsset;
use Neos\Media\Domain\Repository\ImportedAssetRepository;
use Neos\Utility\MediaTypes;
use Psr\Http\Message\UriInterface;
use stdClass;

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
    private  $identifier;

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
    private $scaledOriginalUri;

    /**
     * @var UriInterface
     */
    private $originalUri;

    /**
     * @var int|null
     */
    private $widthInPixels;

    /**
     * @var int|null
     */
    private $heightInPixels;

    /**
     * @var array
     */
    private  $tags = [];

    /**
     * @Flow\Inject
     * @var ImportedAssetRepository
     */
    protected $importedAssetRepository;

    /**
     * @throws Exception
     */
    public static function fromJsonObject(stdClass $jsonObject, PixxioAssetSource $assetSource): PixxioAssetProxy
    {
        $mediaType = MediaTypes::getMediaTypeFromFilename(strtolower($jsonObject->fileName));
        $assetProxy = new static();
        $assetProxy->assetSource = $assetSource;
        $assetProxy->identifier = (string)$jsonObject->id;
        $assetProxy->label = $jsonObject->subject;
        $assetProxy->filename = $jsonObject->fileName;
        $assetProxy->lastModified = new \DateTime($jsonObject->modifyDate ?? '1.1.2000');
        $assetProxy->fileSize = (int)$jsonObject->fileSize;
        $assetProxy->mediaType = $mediaType;
        $assetProxy->tags = $jsonObject->keywords ?? [];

        $assetProxy->iptcProperties['Title'] = $jsonObject->subject ?? '';
        $assetProxy->iptcProperties['CaptionAbstract'] = $jsonObject->description ?? '';
        $assetProxy->iptcProperties['CopyrightNotice'] = (string)self::extractMetadata($jsonObject->importantMetadata, 'iptc', 'CopyrightNotice');

        $assetProxy->widthInPixels = $jsonObject->width ? (int)$jsonObject->width : null;
        $assetProxy->heightInPixels = $jsonObject->height ? (int)$jsonObject->height : null;

        $modifiedPreviewFileURLs = $jsonObject->modifiedPreviewFileURLs;
        if (isset($modifiedPreviewFileURLs[0])) {
            $assetProxy->thumbnailUri = new Uri($modifiedPreviewFileURLs[0]);
        }
        if (isset($modifiedPreviewFileURLs[1])) {
            $assetProxy->previewUri = new Uri($modifiedPreviewFileURLs[1]);
        }
        if (isset($modifiedPreviewFileURLs[2])) {
            $assetProxy->scaledOriginalUri = new Uri($modifiedPreviewFileURLs[2]);
        }

        $assetProxy->originalUri = new Uri($jsonObject->originalFileURL);

        return $assetProxy;
    }

    private static function extractMetadata(array $importantMetadata, string $type, string $name)
    {
        foreach ($importantMetadata as $importantMetadatum) {
            if ($importantMetadatum->type === $type && $importantMetadatum->name === $name) {
                return $importantMetadatum->value;
            }
        }

        return null;
    }

    public function getAssetSource(): AssetSourceInterface
    {
        return $this->assetSource;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getLastModified(): \DateTimeInterface
    {
        return $this->lastModified;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    public function hasIptcProperty(string $propertyName): bool
    {
        return isset($this->iptcProperties[$propertyName]);
    }

    public function getIptcProperty(string $propertyName): string
    {
        return $this->iptcProperties[$propertyName] ?? '';
    }

    public function getIptcProperties(): array
    {
        return $this->iptcProperties;
    }

    public function getWidthInPixels(): ?int
    {
        return $this->widthInPixels;
    }

    public function getHeightInPixels(): ?int
    {
        return $this->heightInPixels;
    }

    public function getThumbnailUri(): ?UriInterface
    {
        return $this->thumbnailUri;
    }

    public function getPreviewUri(): ?UriInterface
    {
        return $this->previewUri;
    }

    /**
     * @throws ConnectionException
     */
    public function getImportStream()
    {
        $mediaType = MediaTypes::getMediaTypeFromFilename(strtolower($this->filename));
        $assetSourceOptions = $this->getAssetSource()->getAssetSourceOptions();
        $usePixxioThumbnailAsOriginal = (!isset($assetSourceOptions['mediaTypes'][$mediaType]) || $assetSourceOptions['mediaTypes'][$mediaType]['usePixxioThumbnailAsOriginal'] === false);
        $importUri = $usePixxioThumbnailAsOriginal ? $this->scaledOriginalUri : $this->originalUri;

        $client = new Client($assetSourceOptions['apiClientOptions'] ?? []);
        try {
            $response = $client->request('GET', $importUri);
            if ($response->getStatusCode() === 200) {
                return $response->getBody()->detach();
            }

            return false;
        } catch (GuzzleException $exception) {
            throw new ConnectionException('Retrieving file failed: ' . $exception->getMessage(), 1542808207, $exception);
        }
    }

    public function getLocalAssetIdentifier(): ?string
    {
        $importedAsset = $this->importedAssetRepository->findOneByAssetSourceIdentifierAndRemoteAssetIdentifier($this->assetSource->getIdentifier(), $this->identifier);
        return ($importedAsset instanceof ImportedAsset ? $importedAsset->getLocalAssetIdentifier() : null);
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function isImported(): bool
    {
        return true;
    }
}
