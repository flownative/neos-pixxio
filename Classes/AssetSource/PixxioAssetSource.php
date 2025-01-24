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

use Flownative\Pixxio\Domain\Model\ClientSecret;
use Flownative\Pixxio\Domain\Repository\ClientSecretRepository;
use Flownative\Pixxio\Exception\AuthenticationFailedException;
use Flownative\Pixxio\Exception\MissingClientSecretException;
use Flownative\Pixxio\Service\PixxioClient;
use Flownative\Pixxio\Service\PixxioServiceFactory;
use GuzzleHttp\Psr7\Uri;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Context;
use Neos\Flow\ResourceManagement\ResourceManager;
use Neos\Media\Domain\Model\AssetSource\AssetProxyRepositoryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetSourceInterface;
use Neos\Utility\Arrays;
use Neos\Utility\MediaTypes;

class PixxioAssetSource implements AssetSourceInterface
{
    /**
     * @Flow\Inject
     */
    protected PixxioServiceFactory $pixxioServiceFactory;

    /**
     * @Flow\Inject
     */
    protected ClientSecretRepository $clientSecretRepository;

    /**
     * @Flow\Inject
     */
    protected Context $securityContext;

    /**
     * @Flow\Inject
     */
    protected ResourceManager $resourceManager;

    /**
     * @Flow\InjectConfiguration(path="defaults", package="Flownative.Pixxio")
     */
    protected array $defaultConfiguration = [];

    private ?PixxioAssetProxyRepository $assetProxyRepository = null;

    private ?PixxioClient $pixxioClient = null;

    private string $assetSourceIdentifier;

    private array $assetSourceOptions;

    private string $apiEndpointUri;

    private string $apiKey;

    private array $apiClientOptions = [];

    private array $imageOptions = [];

    private string $sharedRefreshToken;

    private bool $autoTaggingEnabled = false;

    private string $autoTaggingInUseTag = 'used-by-neos';

    protected string $iconPath = 'resource://Flownative.Pixxio/Public/Icons/PixxioWhite.svg';

    protected string $label = 'pixx.io';

    protected string $description = '';

    protected array $mapping = [];

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
        $this->assetSourceOptions = Arrays::arrayMergeRecursiveOverrule($this->defaultConfiguration['assetSourceOptions'], $this->assetSourceOptions);

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
                case 'sharedRefreshToken':
                    if (!is_string($optionValue) || empty($optionValue)) {
                        throw new \InvalidArgumentException(sprintf('Invalid shared refresh token specified for pixx.io asset source %s', $this->assetSourceIdentifier), 1528806843);
                    }
                    $this->sharedRefreshToken = $optionValue;
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
                                $this->autoTaggingEnabled = (bool)$autoTaggingOptionValue;
                            break;
                            case 'inUseTag':
                                $this->autoTaggingInUseTag = preg_replace('/[^A-Za-z0-9&_+ßäöüÄÖÜ.@ -]+/u', '', (string)$autoTaggingOptionValue);
                            break;
                            default:
                                throw new \InvalidArgumentException(sprintf('Unknown asset source option "%s" specified for autoTagging in pixx.io asset source "%s". Please check your settings.', $autoTaggingOptionName, $this->assetSourceIdentifier), 1587561244);
                        }
                    }
                break;
                case 'icon':
                    if (!is_string($optionValue)) {
                        throw new \InvalidArgumentException(sprintf('Invalid icon specified for pixx.io asset source %s', $this->assetSourceIdentifier), 1725985121);
                    }
                    $this->iconPath = $optionValue;
                    break;
                case 'label':
                    if (!is_string($optionValue)) {
                        throw new \InvalidArgumentException(sprintf('Invalid label specified for pixx.io asset source %s', $this->assetSourceIdentifier), 1725985129);
                    }
                    $this->label = $optionValue;
                    break;
                case 'description':
                    if (!is_string($optionValue)) {
                        throw new \InvalidArgumentException(sprintf('Invalid description specified for pixx.io asset source %s', $this->assetSourceIdentifier), 1725985133);
                    }
                    $this->description = $optionValue;
                    break;
                case 'mapping':
                    if (!is_array($optionValue)) {
                        throw new \InvalidArgumentException(sprintf('Invalid mapping options specified for pixx.io asset source %s', $this->assetSourceIdentifier), 1725985041);
                    }
                    $this->mapping = $optionValue;
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
        return $this->autoTaggingEnabled;
    }

    public function getAutoTaggingInUseTag(): string
    {
        return $this->autoTaggingInUseTag;
    }

    /**
     * @throws MissingClientSecretException
     * @throws AuthenticationFailedException
     */
    public function getPixxioClient(): PixxioClient
    {
        if ($this->pixxioClient === null) {

            if ($this->securityContext->isInitialized() && $this->securityContext->getAccount()) {
                $account = $this->securityContext->getAccount();
                $clientSecret = $this->clientSecretRepository->findOneByIdentifiers($this->assetSourceIdentifier, $account->getAccountIdentifier());
            } else {
                $clientSecret = null;
                $account = new Account();
                $account->setAccountIdentifier('shared');
            }

            if (!empty($this->sharedRefreshToken) && ($clientSecret === null || $clientSecret->getRefreshToken() === '')) {
                $clientSecret = new ClientSecret();
                $clientSecret->setRefreshToken($this->sharedRefreshToken);
                $clientSecret->setAssetSourceIdentifier($this->assetSourceIdentifier);
                $clientSecret->setFlowAccountIdentifier('shared');
            }

            if ($clientSecret === null || $clientSecret->getRefreshToken() === '') {
                throw new MissingClientSecretException(sprintf('No client secret found for account %s. Please set up the pixx.io plugin with the correct credentials.', $account->getAccountIdentifier()), 1526544548);
            }

            $this->pixxioClient = $this->pixxioServiceFactory->createForAccount(
                $this->apiEndpointUri,
                $this->apiKey,
                $this->apiClientOptions,
                $this->imageOptions
            );

            $this->pixxioClient->authenticate($clientSecret->getRefreshToken());
        }
        return $this->pixxioClient;
    }

    public function getIconUri(): string
    {
        return $this->resourceManager->getPublicPackageResourceUriByPath($this->iconPath);
    }

    public function getDescription(): string
    {
        return $this->description;
    }
}
