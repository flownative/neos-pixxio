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
use Flownative\Pixxio\Exception\MissingClientSecretException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Utils;
use Neos\Flow\Annotations\Inject;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryResultInterface;
use Psr\Log\LoggerInterface;

/**
 *
 */
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
     * @var ?string
     */
    private $assetCollectionFilter;

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
     * @var string
     */
    private $parentFolderIdentifier = '';

    /**
     * @Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Inject
     * @var ThrowableStorageInterface
     */
    protected $throwableStorage;

    /**
     * @param PixxioAssetSource $assetSource
     */
    public function __construct(PixxioAssetSource $assetSource)
    {
        $this->assetSource = $assetSource;
    }

    /**
     * @param int $offset
     */
    public function setOffset(int $offset): void
    {
        $this->offset = $offset;
    }

    /**
     * @return int
     */
    public function getOffset(): int
    {
        return $this->offset;
    }

    /**
     * @param int $limit
     */
    public function setLimit(int $limit): void
    {
        $this->limit = $limit;
    }

    /**
     * @return int
     */
    public function getLimit(): int
    {
        return $this->limit;
    }

    /**
     * @param string $searchTerm
     */
    public function setSearchTerm(string $searchTerm): void
    {
        $this->searchTerm = $searchTerm;
    }

    /**
     * @return string
     */
    public function getSearchTerm(): string
    {
        return $this->searchTerm;
    }

    /**
     * @param string $assetTypeFilter
     */
    public function setAssetTypeFilter(string $assetTypeFilter): void
    {
        $this->assetTypeFilter = $assetTypeFilter;
    }

    /**
     * @return string
     */
    public function getAssetTypeFilter(): string
    {
        return $this->assetTypeFilter;
    }

    /**
     * @param ?string $assetCollectionFilter
     */
    public function setAssetCollectionFilter(?string $assetCollectionFilter): void
    {
        $this->assetCollectionFilter = $assetCollectionFilter;
    }

    /**
     * @return ?string
     */
    public function getAssetCollectionFilter(): ?string
    {
        return $this->assetCollectionFilter;
    }

    /**
     * @return array
     */
    public function getOrderings(): array
    {
        return $this->orderings;
    }

    /**
     * @param array $orderings
     */
    public function setOrderings(array $orderings): void
    {
        $this->orderings = $orderings;
    }

    /**
     * @return string
     */
    public function getParentFolderIdentifier(): string
    {
        return $this->parentFolderIdentifier;
    }

    /**
     * @param string $parentFolderIdentifier
     */
    public function setParentFolderIdentifier(string $parentFolderIdentifier): void
    {
        $this->parentFolderIdentifier = $parentFolderIdentifier;
    }

    /**
     * @return AssetProxyQueryResultInterface
     */
    public function execute(): AssetProxyQueryResultInterface
    {
        return new PixxioAssetProxyQueryResult($this);
    }

    /**
     * @return int
     */
    public function count(): int
    {
        try {
            $response = $this->sendSearchRequest(1, []);
            $responseObject = Utils::jsonDecode($response->getBody()->getContents());

            if (!isset($responseObject->quantity)) {
                if (isset($responseObject->help)) {
                    $message = $this->throwableStorage->logThrowable(new ConnectionException('Query to pixx.io failed: ' . $responseObject->help, 1526629493));
                    $this->logger->error($message, LogEnvironment::fromMethodName(__METHOD__));
                }
                return 0;
            }
            return (int)$responseObject->quantity;
        } catch (AuthenticationFailedException $exception) {
            $message = $this->throwableStorage->logThrowable(new ConnectionException('Connection to pixx.io failed.', 1526629541, $exception));
            $this->logger->error($message, LogEnvironment::fromMethodName(__METHOD__));
            return 0;
        } catch (MissingClientSecretException $exception) {
            $message = $this->throwableStorage->logThrowable(new ConnectionException('Connection to pixx.io failed.', 1526629547, $exception));
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
            $response = $this->sendSearchRequest($this->limit, $this->orderings);
            $responseObject = Utils::jsonDecode($response->getBody()->getContents());

            if (!isset($responseObject->files)) {
                return [];
            }
            foreach ($responseObject->files as $rawAsset) {
                $assetProxies[] = PixxioAssetProxy::fromJsonObject($rawAsset, $this->assetSource);
            }
        } catch (AuthenticationFailedException $exception) {
            $message = $this->throwableStorage->logThrowable(new ConnectionException('Connection to pixx.io failed.', 1643822709, $exception));
            $this->logger->error($message, LogEnvironment::fromMethodName(__METHOD__));
            return [];
        } catch (MissingClientSecretException $exception) {
            $message = $this->throwableStorage->logThrowable(new ConnectionException('Connection to pixx.io failed.', 1643822727, $exception));
            $this->logger->error($message, LogEnvironment::fromMethodName(__METHOD__));
            return [];
        }
        return $assetProxies;
    }

    /**
     * @param int $limit
     * @param array $orderings
     * @return Response
     * @throws AuthenticationFailedException
     * @throws MissingClientSecretException
     * @throws ConnectionException
     */
    private function sendSearchRequest(int $limit, array $orderings): Response
    {
        $searchTerm = $this->searchTerm;

        switch ($this->assetTypeFilter) {
            case 'Image':
                $formatTypes = ['image'];
                $fileTypes = [];
            break;
            case 'Video':
                $formatTypes = ['video'];
                $fileTypes = [];
            break;
            case 'Audio':
                $formatTypes = ['audio'];
                $fileTypes = [];
            break;
            case 'Document':
                $formatTypes = [];
                $fileTypes = ['pdf'];
            break;
            case 'All':
            default:
                $formatTypes = ['converted'];
                $fileTypes = [];
            break;
        }

        return $this->assetSource->getPixxioClient()->search($searchTerm, $formatTypes, $fileTypes, $this->assetCollectionFilter, $this->offset, $limit, $orderings);
    }
}
