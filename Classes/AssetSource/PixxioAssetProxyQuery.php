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
use Neos\Cache\Exception as CacheException;
use Neos\Cache\Frontend\StringFrontend;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations\Inject;
use Neos\Flow\Log\SystemLoggerInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryResultInterface;

final class PixxioAssetProxyQuery implements AssetProxyQueryInterface
{
    /**
     * @var PixxioAssetSource
     */
    private $assetSource;

    /**
     * @var string
     */
    private $searchTerm = '';

    /**
     * @var string
     */
    private $assetTypeFilter = 'All';

    /**
     * @var array
     */
    private $orderings = [];

    /**
     * @var int
     */
    private $offset = 0;

    /**
     * @var int
     */
    private $limit = 30;

    /**
     * @Inject
     * @var SystemLoggerInterface
     */
    protected $logger;

    /**
     * @var StringFrontend
     */
    protected $assetProxyCache;

    /**
     * @var VariableFrontend
     */
    protected $pageCursorCache;

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

    public function setSearchTerm(string $searchTerm): void
    {
        $this->searchTerm = $searchTerm;
    }

    public function getSearchTerm(): string
    {
        return $this->searchTerm;
    }

    public function setAssetTypeFilter(string $assetTypeFilter): void
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
            // The total quantity is independent of the current offset, so we always read it
            // from the first page (which needs no cursor). A page size of 1 keeps this cheap.
            [$formatType, $fileTypes] = $this->resolveTypeFilters();
            $response = $this->assetSource->getPixxioClient()->search($this->searchTerm, $formatType, $fileTypes, '', 1, []);
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
     * Fetches the result page covering the current offset.
     *
     * The pixx.io API only supports cursor-based pagination for GET /files: to load page N
     * the "cursor" returned by page N-1 is required. To bridge the offset-based Neos query
     * interface we keep a short-lived cache of already discovered cursors per query. Sequential
     * forward paging (the common case) therefore costs a single request per page, while jumping
     * to a not-yet-visited page walks the cursors forward from the nearest known position.
     *
     * @throws AuthenticationFailedException
     * @throws ConnectionException
     * @throws \JsonException
     */
    private function sendSearchRequest(int $limit, array $orderings): object
    {
        [$formatType, $fileTypes] = $this->resolveTypeFilters();
        $client = $this->assetSource->getPixxioClient();

        $targetPage = $limit > 0 ? intdiv($this->offset, $limit) : 0;

        // The first page is requested without a cursor.
        if ($targetPage === 0) {
            $response = $client->search($this->searchTerm, $formatType, $fileTypes, '', $limit, $orderings);
            $this->rememberPageCursors($limit, $orderings, [1 => $response->cursor ?? null]);
            return $response;
        }

        $cacheKey = $this->pageCursorCacheKey($limit, $orderings);
        $cursors = $this->pageCursorCache->get($cacheKey) ?: [];

        // Start from the nearest known cursor (page 0 needs none) and walk forward.
        $page = $targetPage;
        while ($page > 0 && !array_key_exists($page, $cursors)) {
            $page--;
        }
        $cursor = $page === 0 ? '' : $cursors[$page];

        $response = null;
        while (true) {
            $response = $client->search($this->searchTerm, $formatType, $fileTypes, $cursor, $limit, $orderings);
            $nextCursor = $response->cursor ?? null;
            if ($nextCursor !== null) {
                $cursors[$page + 1] = $nextCursor;
            }
            if ($page === $targetPage) {
                break;
            }
            if ($nextCursor === null) {
                // The requested page lies beyond the last page, so there are no results for it.
                $response->files = [];
                break;
            }
            $cursor = $nextCursor;
            $page++;
        }

        $this->rememberPageCursors($limit, $orderings, $cursors);
        return $response;
    }

    /**
     * Maps the asset type filter to the pixx.io format type and file extensions.
     *
     * @return array
     */
    private function resolveTypeFilters(): array
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

        return [$formatType, $fileTypes];
    }

    /**
     * Builds a cache identifier covering everything that influences the result set and its ordering.
     *
     * @throws \JsonException
     */
    private function pageCursorCacheKey(int $limit, array $orderings): string
    {
        return sha1(json_encode([
            $this->assetSource->getIdentifier(),
            $this->searchTerm,
            $this->assetTypeFilter,
            $orderings,
            $limit,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @throws \JsonException
     */
    private function rememberPageCursors(int $limit, array $orderings, array $cursors): void
    {
        // Only actual cursor tokens are worth keeping; the first page never needs one.
        $cursors = array_filter($cursors, static function ($cursor) {
            return $cursor !== null;
        });
        if ($cursors === []) {
            return;
        }
        try {
            // Caching cursors is a best-effort optimisation; a cache failure must not break the search.
            $this->pageCursorCache->set($this->pageCursorCacheKey($limit, $orderings), $cursors);
        } catch (CacheException $exception) {
            $this->logger->log('Could not cache pixx.io page cursors: ' . $exception->getMessage(), LOG_WARNING);
        }
    }
}
