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

use Flownative\Pixxio\Service\PixxioClient;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Uri;
use Neos\Flow\Security\Context;
use Neos\Media\Domain\Model\AssetSource\AssetProxyRepositoryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetSourceInterface;
use Neos\Utility\MediaTypes;

class PixxioAssetSource implements AssetSourceInterface
{
    private string $assetSourceIdentifier;

    private ?PixxioAssetProxyRepository $assetProxyRepository = null;

    /**
     * @Flow\Inject
     * @var Context
     */
    protected $securityContext;

    private string $apiEndpointUri;

    private string $apiKey;

    private array $apiClientOptions = [];

    private ?PixxioClient $pixxioClient = null;

    private array $imageOptions = [];

    private bool $autoTaggingEnable = false;

    private string $autoTaggingInUseTag = 'used-by-neos';

    private array $assetSourceOptions;

    protected string $label = 'pixx.io';


    /**
     * @throws \InvalidArgumentException
     */
    protected function __construct(string $assetSourceIdentifier, array $assetSourceOptions)
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,62}[a-z]$/', $assetSourceIdentifier) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid asset source identifier "%s". The identifier must match /^[a-z][a-z0-9-]{0,62}[a-z]$/', $assetSourceIdentifier), 1525790890);
        }

        $this->assetSourceIdentifier = $assetSourceIdentifier;
        $this->assetSourceOptions = $assetSourceOptions;
    }

    public function initializeObject(): void
    {
        foreach ($this->assetSourceOptions as $optionName => $optionValue) {
            switch ($optionName) {
                case 'apiEndpointUri':
                    $uri = new Uri($optionValue);
                    $this->apiEndpointUri = $uri->__toString();
                    break;
                case 'apiKey':
                    if (!is_string($optionValue) || empty($optionValue)) {
                        throw new \InvalidArgumentException(sprintf('Invalid api key specified for pixx.io asset source %s', $this->assetSourceIdentifier), 1525792639);
                    }
                    $this->apiKey = $optionValue;
                    break;
                case 'apiClientOptions':
                    if (!is_array($optionValue)) {
                        throw new \InvalidArgumentException(sprintf('Invalid api client options specified for pixx.io asset source %s', $this->assetSourceIdentifier), 1591605348);
                    }
                    $this->apiClientOptions = $optionValue;
                    break;
                case 'imageOptions':
                    if (!is_array($optionValue)) {
                        throw new \InvalidArgumentException(sprintf('Invalid image options specified for pixx.io asset source %s', $this->assetSourceIdentifier), 1591605349);
                    }
                    $this->imageOptions = $optionValue;
                    break;
                case 'mediaTypes':
                    if (!is_array($optionValue)) {
                        throw new \InvalidArgumentException(sprintf('Invalid media types specified for pixx.io asset source %s', $this->assetSourceIdentifier), 1542809628);
                    }
                    foreach ($optionValue as $mediaType => $mediaTypeOptions) {
                        if (MediaTypes::getFilenameExtensionsFromMediaType($mediaType) === []) {
                            throw new \InvalidArgumentException(sprintf('Unknown media type "%s" specified for pixx.io asset source %s', $mediaType, $this->assetSourceIdentifier), 1542809775);
                        }
                    }
                    break;
                case 'autoTagging':
                    if (!is_array($optionValue)) {
                        throw new \InvalidArgumentException(sprintf('Invalid auto tagging configuration specified for pixx.io asset source %s', $this->assetSourceIdentifier), 1587561121);
                    }
                    foreach ($optionValue as $autoTaggingOptionName => $autoTaggingOptionValue) {
                        switch ($autoTaggingOptionName) {
                            case 'enable':
                                $this->autoTaggingEnable = (bool)$autoTaggingOptionValue;
                                break;
                            case 'inUseTag':
                                $this->autoTaggingInUseTag = preg_replace('/[^A-Za-z0-9&_+ßäöüÄÖÜ.@ -]+/u', '', (string)$autoTaggingOptionValue);
                                break;
                            default:
                                throw new \InvalidArgumentException(sprintf('Unknown asset source option "%s" specified for autoTagging in pixx.io asset source "%s". Please check your settings.', $autoTaggingOptionName, $this->assetSourceIdentifier), 1587561244);
                        }
                    }
                    break;
                case 'label':
                    if (!is_string($optionValue)) {
                        throw new \InvalidArgumentException(sprintf('Invalid label specified for pixx.io asset source %s', $this->assetSourceIdentifier), 1725985129);
                    }
                    $this->label = $optionValue;
                    break;
                default:
                    throw new \InvalidArgumentException(sprintf('Unknown asset source option "%s" specified for pixx.io asset source "%s". Please check your settings.', $optionName, $this->assetSourceIdentifier), 1525790910);
            }
        }
    }

    public static function createFromConfiguration(string $assetSourceIdentifier, array $assetSourceOptions): AssetSourceInterface
    {
        return new static($assetSourceIdentifier, $assetSourceOptions);
    }

    public function getIdentifier(): string
    {
        return $this->assetSourceIdentifier;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getAssetProxyRepository(): AssetProxyRepositoryInterface
    {
        if ($this->assetProxyRepository === null) {
            $this->assetProxyRepository = new PixxioAssetProxyRepository($this);
        }

        return $this->assetProxyRepository;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getAssetSourceOptions(): array
    {
        return $this->assetSourceOptions;
    }

    public function isAutoTaggingEnabled(): bool
    {
        return $this->autoTaggingEnable;
    }

    public function getAutoTaggingInUseTag(): string
    {
        return $this->autoTaggingInUseTag;
    }

    public function getPixxioClient(): PixxioClient
    {
        if ($this->pixxioClient === null) {
            $this->pixxioClient = new PixxioClient(
                $this->apiEndpointUri,
                $this->apiKey,
                $this->apiClientOptions,
                $this->imageOptions
            );
        }

        return $this->pixxioClient;
    }
}
