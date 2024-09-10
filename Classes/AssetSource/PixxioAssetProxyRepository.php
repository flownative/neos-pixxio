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
use Flownative\Pixxio\Exception\AccessToAssetDeniedException;
use Flownative\Pixxio\Exception\AssetNotFoundException;
use Flownative\Pixxio\Exception\AuthenticationFailedException;
use Flownative\Pixxio\Exception\ConnectionException;
use Flownative\Pixxio\Exception\MissingClientSecretException;
use GuzzleHttp\Utils;
use Neos\Cache\Frontend\StringFrontend;
use Neos\Flow\ObjectManagement\DependencyInjection\DependencyProxy;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\AssetSource\AssetNotFoundExceptionInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryResultInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyRepositoryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetSourceConnectionExceptionInterface;
use Neos\Media\Domain\Model\AssetSource\AssetTypeFilter;
use Neos\Media\Domain\Model\AssetSource\SupportsCollectionsInterface;
use Neos\Media\Domain\Model\AssetSource\SupportsSortingInterface;
use Neos\Media\Domain\Model\Tag;

/**
 * PixxioAssetProxyRepository
 */
class PixxioAssetProxyRepository implements AssetProxyRepositoryInterface, SupportsSortingInterface, SupportsCollectionsInterface
{
    /**
     * @var PixxioAssetSource
     */
    private $assetSource;

    /**
     * @var string|null
     */
    protected $assetCollectionFilter;

    /**
     * @var string
     */
    private $assetTypeFilter = 'All';

    /**
     * @var array
     */
    private $orderings = [];

    /**
     * @var StringFrontend
     */
    protected $assetProxyCache;

    /**
     * @param PixxioAssetSource $assetSource
     */
    public function __construct(PixxioAssetSource $assetSource)
    {
        $this->assetSource = $assetSource;
    }

    /**
     * @param string $identifier
     * @return AssetProxyInterface
     * @throws AssetNotFoundExceptionInterface
     * @throws AssetSourceConnectionExceptionInterface
     * @throws MissingClientSecretException
     * @throws AuthenticationFailedException
     * @throws AssetNotFoundException
     * @throws ConnectionException
     * @throws \Neos\Cache\Exception
     * @throws Exception
     */
    public function getAssetProxy(string $identifier): AssetProxyInterface
    {
        $client = $this->assetSource->getPixxioClient();

        $cacheEntryIdentifier = sha1($identifier);
        $cacheEntry = $this->assetProxyCache->get($cacheEntryIdentifier);

        if ($cacheEntry) {
            $responseObject = Utils::jsonDecode($cacheEntry);
        } else {
            $response = $client->getFile($identifier);
            $responseObject = Utils::jsonDecode($response->getBody()->getContents());

            if (!$responseObject instanceof \stdClass) {
                throw new AssetNotFoundException('Asset not found', 1526636260);
            }
            if (!isset($responseObject->success) || $responseObject->success !== 'true') {
                switch ($responseObject->status) {
                    case 403:
                        throw new AccessToAssetDeniedException(sprintf('Failed retrieving asset: %s', $response->help ?? '-') , 1589815740);
                    default:
                        throw new AssetNotFoundException(sprintf('Failed retrieving asset, unexpected API response: %s', $response->help ?? '-') , 1589354288);
                }
            }

            $this->assetProxyCache->set($cacheEntryIdentifier, Utils::jsonEncode($responseObject, JSON_FORCE_OBJECT));
        }
        return PixxioAssetProxy::fromJsonObject($responseObject, $this->assetSource);
    }

    /**
     * @param AssetTypeFilter|null $assetType
     */
    public function filterByType(AssetTypeFilter $assetType = null): void
    {
        $this->assetTypeFilter = (string)$assetType ?: 'All';
    }

    public function filterByCollection(AssetCollection $assetCollection = null): void
    {
        $this->assetCollectionFilter = $assetCollection?->getTitle();
    }

    /**
     * @return AssetProxyQueryResultInterface
     */
    public function findAll(): AssetProxyQueryResultInterface
    {
        $query = new PixxioAssetProxyQuery($this->assetSource);
        $query->setAssetTypeFilter($this->assetTypeFilter);
        $query->setAssetCollectionFilter($this->assetCollectionFilter);
        $query->setOrderings($this->orderings);
        return new PixxioAssetProxyQueryResult($query);
    }

    /**
     * @param string $searchTerm
     * @return AssetProxyQueryResultInterface
     */
    public function findBySearchTerm(string $searchTerm): AssetProxyQueryResultInterface
    {
        $query = new PixxioAssetProxyQuery($this->assetSource);
        $query->setSearchTerm($searchTerm);
        $query->setAssetTypeFilter($this->assetTypeFilter);
        $query->setAssetCollectionFilter($this->assetCollectionFilter);
        $query->setOrderings($this->orderings);
        return new PixxioAssetProxyQueryResult($query);
    }

    /**
     * @param Tag $tag
     * @return AssetProxyQueryResultInterface
     */
    public function findByTag(Tag $tag): AssetProxyQueryResultInterface
    {
        $query = new PixxioAssetProxyQuery($this->assetSource);
        $query->setSearchTerm($tag->getLabel());
        $query->setAssetTypeFilter($this->assetTypeFilter);
        $query->setAssetCollectionFilter($this->assetCollectionFilter);
        $query->setOrderings($this->orderings);
        return new PixxioAssetProxyQueryResult($query);
    }

    /**
     * @return AssetProxyQueryResultInterface
     */
    public function findUntagged(): AssetProxyQueryResultInterface
    {
        $query = new PixxioAssetProxyQuery($this->assetSource);
        $query->setAssetTypeFilter($this->assetTypeFilter);
        $query->setAssetCollectionFilter($this->assetCollectionFilter);
        $query->setOrderings($this->orderings);
        return new PixxioAssetProxyQueryResult($query);
    }

    /**
     * @return int
     */
    public function countAll(): int
    {
        return (new PixxioAssetProxyQuery($this->assetSource))->count();
    }

    /**
     * Sets the property names to order results by. Expected like this:
     * array(
     *  'filename' => SupportsSorting::ORDER_ASCENDING,
     *  'lastModified' => SupportsSorting::ORDER_DESCENDING
     * )
     *
     * @param array $orderings The property names to order by by default
     * @return void
     * @api
     */
    public function orderBy(array $orderings): void
    {
        $this->orderings = $orderings;
    }

    /**
     * @return StringFrontend
     */
    public function getAssetProxyCache(): StringFrontend
    {
        if ($this->assetProxyCache instanceof DependencyProxy) {
            $this->assetProxyCache->_activateDependency();
        }
        return $this->assetProxyCache;
    }
}
