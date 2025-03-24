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
use Neos\Cache\Frontend\StringFrontend;
use Neos\Flow\ObjectManagement\DependencyInjection\DependencyProxy;
use Neos\Media\Domain\Model\AssetSource\AssetNotFoundExceptionInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryResultInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyRepositoryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetSourceConnectionExceptionInterface;
use Neos\Media\Domain\Model\AssetSource\AssetTypeFilter;
use Neos\Media\Domain\Model\AssetSource\SupportsSortingInterface;
use Neos\Media\Domain\Model\Tag;

class PixxioAssetProxyRepository implements AssetProxyRepositoryInterface, SupportsSortingInterface
{
    /**
     * @var PixxioAssetSource
     */
    private $assetSource;

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

    public function __construct(PixxioAssetSource $assetSource)
    {
        $this->assetSource = $assetSource;
    }

    /**
     * @throws AssetNotFoundExceptionInterface
     * @throws AssetSourceConnectionExceptionInterface
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
            $cachedObject = json_decode($cacheEntry, false, 512, JSON_THROW_ON_ERROR);
            return PixxioAssetProxy::fromJsonObject($cachedObject, $this->assetSource);
        }

        $responseObject = $client->getFile($identifier);

        if (!isset($responseObject->success) || $responseObject->success !== true) {
            throw new AssetNotFoundException(sprintf('Failed retrieving asset, unexpected API response: %s', $response->errorMessage ?? '-'), 1589354288);
        }

        $this->assetProxyCache->set($cacheEntryIdentifier, json_encode($responseObject->file, JSON_THROW_ON_ERROR));
        return PixxioAssetProxy::fromJsonObject($responseObject->file, $this->assetSource);
    }

    public function filterByType(AssetTypeFilter $assetType = null): void
    {
        $this->assetTypeFilter = (string)$assetType ?: 'All';
    }

    public function findAll(): AssetProxyQueryResultInterface
    {
        $query = new PixxioAssetProxyQuery($this->assetSource);
        $query->setAssetTypeFilter($this->assetTypeFilter);
        $query->setOrderings($this->orderings);
        return new PixxioAssetProxyQueryResult($query);
    }

    public function findBySearchTerm(string $searchTerm): AssetProxyQueryResultInterface
    {
        $query = new PixxioAssetProxyQuery($this->assetSource);
        $query->setSearchTerm($searchTerm);
        $query->setAssetTypeFilter($this->assetTypeFilter);
        $query->setOrderings($this->orderings);
        return new PixxioAssetProxyQueryResult($query);
    }

    public function findByTag(Tag $tag): AssetProxyQueryResultInterface
    {
        $query = new PixxioAssetProxyQuery($this->assetSource);
        $query->setSearchTerm($tag->getLabel());
        $query->setAssetTypeFilter($this->assetTypeFilter);
        $query->setOrderings($this->orderings);
        return new PixxioAssetProxyQueryResult($query);
    }

    public function findUntagged(): AssetProxyQueryResultInterface
    {
        $query = new PixxioAssetProxyQuery($this->assetSource);
        $query->setAssetTypeFilter($this->assetTypeFilter);
        $query->setOrderings($this->orderings);
        return new PixxioAssetProxyQueryResult($query);
    }

    public function countAll(): int
    {
        return (new PixxioAssetProxyQuery($this->assetSource))->count();
    }

    public function orderBy(array $orderings): void
    {
        $this->orderings = $orderings;
    }

    public function getAssetProxyCache(): StringFrontend
    {
        if ($this->assetProxyCache instanceof DependencyProxy) {
            $this->assetProxyCache->_activateDependency();
        }
        return $this->assetProxyCache;
    }
}
