<?php
declare(strict_types=1);

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
use Exception;
use Flownative\Pixxio\Exception\ConnectionException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use Neos\Flow\Annotations as Flow;
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
    private PixxioAssetSource $assetSource;

    private string $identifier;

    private string $label;

    private string $filename;

    private \DateTime $lastModified;

    private int $fileSize;

    private string $mediaType;

    private array $iptcProperties = [];

    private UriInterface $thumbnailUri;

    private UriInterface $previewUri;

    private UriInterface $originalUri;

    private ?int $widthInPixels;

    private ?int $heightInPixels;

    private array $tags = [];

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
        $assetSourceOptions = $assetSource->getAssetSourceOptions();
        $pixxioOriginalMediaType = MediaTypes::getMediaTypeFromFilename('foo.' . strtolower($jsonObject->fileType));
        $usePixxioThumbnailAsOriginal = (!isset($assetSourceOptions['mediaTypes'][$pixxioOriginalMediaType]) || $assetSourceOptions['mediaTypes'][$pixxioOriginalMediaType]['usePixxioThumbnailAsOriginal'] === false);
        $modifiedFileType = $usePixxioThumbnailAsOriginal ? 'jpg' : strtolower($jsonObject->fileType);

        $assetProxy = new static();
        $assetProxy->assetSource = $assetSource;
        $assetProxy->identifier = $jsonObject->id;
        $assetProxy->label = $jsonObject->subject;
        $assetProxy->filename = Transliterator::urlize($jsonObject->subject) . '.' . $modifiedFileType;
        $assetProxy->lastModified = new \DateTime($jsonObject->modifyDate ?? '1.1.2000');
        $assetProxy->fileSize = (int)$jsonObject->fileSize;
        $assetProxy->mediaType = MediaTypes::getMediaTypeFromFilename('foo.' . $modifiedFileType);
        $assetProxy->tags = isset($jsonObject->keywords) ? explode(',', $jsonObject->keywords) : [];

        $assetProxy->iptcProperties['Title'] = $jsonObject->subject ?? '';
        $assetProxy->iptcProperties['CaptionAbstract'] = $jsonObject->description ?? '';
        $assetProxy->iptcProperties['CopyrightNotice'] = $jsonObject->dynamicMetadata->CopyrightNotice ?? '';

        $assetProxy->widthInPixels = $jsonObject->imageWidth ? (int)$jsonObject->imageWidth : null;
        $assetProxy->heightInPixels = $jsonObject->imageHeight ? (int)$jsonObject->imageHeight : null;

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
            } elseif (is_object($modifiedImagePaths)) {
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
        $client = new Client($this->assetSource->getAssetSourceOptions()['apiClientOptions'] ?? []);
        try {
            $response = $client->request('GET', $this->originalUri);
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
