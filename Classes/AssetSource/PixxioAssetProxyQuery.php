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

use Flownative\Pixxio\Exception\AuthenticationFailedException;
use Flownative\Pixxio\Exception\ConnectionException;
use Flownative\Pixxio\Exception\Exception;
use Neos\Cache\Exception as CacheException;
use Neos\Cache\Frontend\StringFrontend;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations\Inject;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\ObjectManagement\DependencyInjection\DependencyProxy;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryResultInterface;
use Psr\Log\LoggerInterface;

final class PixxioAssetProxyQuery implements AssetProxyQueryInterface
{
    private PixxioAssetSource $assetSource;

    private string $searchTerm = '';

    private string $assetTypeFilter = 'All';

    private ?int $directoryFilter = null;

    private array $orderings = [];

    private int $offset = 0;

    private int $limit = 30;

    /**
     * @Inject
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * @Inject
     * @var ThrowableStorageInterface
     */
    protected ThrowableStorageInterface $throwableStorage;

    protected null|StringFrontend|DependencyProxy $assetProxyCache = null;

    protected null|VariableFrontend|DependencyProxy $pageCursorCache = null;

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

    public function setDirectoryFilter(?int $directoryFilter): void
    {
        $this->directoryFilter = $directoryFilter;
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
            $response = $this->assetSource->getPixxioClient()->search($this->searchTerm, $formatType, $fileTypes, $this->directoryFilter, '', 1, []);
            if (!isset($response->quantity)) {
                if (isset($response->errorMessage)) {
                    $message = $this->throwableStorage->logThrowable(new ConnectionException('Query to pixx.io failed: ' . $response->errorMessage, 1526629493));
                    $this->logger->error($message, LogEnvironment::fromMethodName(__METHOD__));
                }
                return 0;
            }
            return (int)$response->quantity;
        } catch (AuthenticationFailedException $exception) {
            $message = $this->throwableStorage->logThrowable(new ConnectionException('Connection to pixx.io failed.', 1526629541, $exception));
            $this->logger->error($message, LogEnvironment::fromMethodName(__METHOD__));
            return 0;
        } catch (ConnectionException $exception) {
            $message = $this->throwableStorage->logThrowable(new ConnectionException('Connection to pixx.io failed.', 1643823324, $exception));
            $this->logger->error($message, LogEnvironment::fromMethodName(__METHOD__));
            return 0;
        }
    }

    /**
     * @return PixxioAssetProxy[]
     * @throws \Exception
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
                    $this->logger->debug('Cache HIT for ' . $cacheEntryIdentifier);
                    $assetProxies[] = PixxioAssetProxy::fromJsonObject($cachedObject, $this->assetSource);
                } else {
                    $this->logger->debug('Cache MISS for ' . $cacheEntryIdentifier);
                    $this->assetProxyCache->set($cacheEntryIdentifier, json_encode($rawAsset, JSON_THROW_ON_ERROR));

                    $assetProxies[] = PixxioAssetProxy::fromJsonObject($rawAsset, $this->assetSource);
                }
            }
        } catch (Exception $exception) {
            $message = $this->throwableStorage->logThrowable(new Exception('Request to pixx.io failed.', 1643822709, $exception));
            $this->logger->error($message);
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
            $response = $client->search($this->searchTerm, $formatType, $fileTypes, $this->directoryFilter, '', $limit, $orderings);
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

        while (true) {
            $response = $client->search($this->searchTerm, $formatType, $fileTypes, $this->directoryFilter, $cursor, $limit, $orderings);
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
     * @return array{0: string, 1: array<string>}
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
            $this->directoryFilter,
            $orderings,
            $limit,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<int, string|null> $cursors
     * @throws \JsonException
     */
    private function rememberPageCursors(int $limit, array $orderings, array $cursors): void
    {
        // Only actual cursor tokens are worth keeping; the first page never needs one.
        $cursors = array_filter($cursors, static fn ($cursor) => $cursor !== null);
        if ($cursors === []) {
            return;
        }
        try {
            // Caching cursors is a best-effort optimisation; a cache failure must not break the search.
            $this->pageCursorCache->set($this->pageCursorCacheKey($limit, $orderings), $cursors);
        } catch (CacheException $exception) {
            $this->logger->warning('Could not cache pixx.io page cursors: ' . $exception->getMessage(), LogEnvironment::fromMethodName(__METHOD__));
        }
    }
}
