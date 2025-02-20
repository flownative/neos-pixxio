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

use Flownative\Pixxio\Exception\AuthenticationFailedException;
use Flownative\Pixxio\Exception\ConnectionException;
use Flownative\Pixxio\Exception\Exception;
use Neos\Cache\Frontend\StringFrontend;
use Neos\Flow\Annotations\Inject;
use Neos\Flow\Log\SystemLoggerInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryResultInterface;

final class PixxioAssetProxyQuery implements AssetProxyQueryInterface
{
    private PixxioAssetSource $assetSource;

    private string $searchTerm = '';

    private string $assetTypeFilter = 'All';

    private array $orderings = [];

    private int $offset = 0;

    private int $limit = 30;

    /**
     * @Inject
     * @var SystemLoggerInterface
     */
    protected $logger;

    /**
     * @var StringFrontend
     */
    protected $assetProxyCache;

    public function __construct(PixxioAssetSource $assetSource)
    {
        $this->assetSource = $assetSource;
    }

    public function setOffset(int $offset): void
    {
        $this->offset = $offset;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }


    public function setLimit(int $limit): void
    {
        $this->limit = $limit;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }

    public function setSearchTerm(string $searchTerm)
    {
        $this->searchTerm = $searchTerm;
    }

    public function getSearchTerm(): string
    {
        return $this->searchTerm;
    }

    public function setAssetTypeFilter(string $assetTypeFilter)
    {
        $this->assetTypeFilter = $assetTypeFilter;
    }

    public function getAssetTypeFilter(): string
    {
        return $this->assetTypeFilter;
    }

    public function getOrderings(): array
    {
        return $this->orderings;
    }

    public function setOrderings(array $orderings): void
    {
        $this->orderings = $orderings;
    }

    public function execute(): AssetProxyQueryResultInterface
    {
        return new PixxioAssetProxyQueryResult($this);
    }

    public function count(): int
    {
        try {
            $response = $this->sendSearchRequest(1, []);
            if (!isset($response->quantity)) {
                if (isset($response->errorMessage)) {
                    $this->logger->logException(new ConnectionException('Query to pixx.io failed: ' . $response->errorMessage, 1526629493));
                }
                return 0;
            }
            return (int)$response->quantity;
        } catch (AuthenticationFailedException $exception) {
            $this->logger->logException(new ConnectionException('Connection to pixx.io failed.', 1526629541, $exception));
            return 0;
        } catch (ConnectionException $exception) {
            $this->logger->logException(new ConnectionException('Connection to pixx.io failed.', 1643823324, $exception));
            return 0;
        }
    }

    /**
     * @return PixxioAssetProxy[]
     */
    public function getArrayResult(): array
    {
        try {
            $assetProxies = [];
            $responseObject = $this->sendSearchRequest($this->limit, $this->orderings);

            if (!isset($responseObject->files)) {
                return [];
            }
            foreach ($responseObject->files as $rawAsset) {
                $cacheEntryIdentifier = sha1((string)$rawAsset->id);
                $cacheEntry = $this->assetProxyCache->get($cacheEntryIdentifier);

                if ($cacheEntry) {
                    $cachedObject = json_decode($cacheEntry, false, 512, JSON_THROW_ON_ERROR);
                    $this->logger->log('Cache HIT for ' . $cacheEntryIdentifier, LOG_DEBUG);
                    $assetProxies[] = PixxioAssetProxy::fromJsonObject($cachedObject, $this->assetSource);
                } else {
                    $this->logger->log('Cache MISS for ' . $cacheEntryIdentifier, LOG_DEBUG);
                    $this->assetProxyCache->set($cacheEntryIdentifier, json_encode($rawAsset, JSON_THROW_ON_ERROR));

                    $assetProxies[] = PixxioAssetProxy::fromJsonObject($rawAsset, $this->assetSource);
                }
            }
        } catch (Exception $exception) {
            $this->logger->logException(new Exception('Request to pixx.io failed.', 1643822709, $exception));
            return [];
        }
        return $assetProxies;
    }

    /**
     * @throws AuthenticationFailedException
     * @throws ConnectionException
     * @throws \JsonException
     */
    private function sendSearchRequest(int $limit, array $orderings): object
    {
        $formatType = '';
        $fileTypes = [];
        switch ($this->assetTypeFilter) {
            case 'Image':
                $formatType = 'image';
                break;
            case 'Video':
                $formatType = 'video';
                break;
            case 'Audio':
                $formatType = 'audio';
                break;
            case 'Document':
                $fileTypes[] = '.pdf';
                break;
        }

        return $this->assetSource->getPixxioClient()->search($this->searchTerm, $formatType, $fileTypes, $this->offset, $limit, $orderings);
    }
}
