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
    private PixxioAssetSource $assetSource;

    private string $searchTerm = '';

    private string $assetTypeFilter = 'All';

    private ?string $assetCollectionFilter;

    private array $orderings = [];

    private int $offset = 0;

    private int $limit = 30;

    private string $parentFolderIdentifier = '';

    /**
     * @Inject
     */
    protected LoggerInterface $logger;

    /**
     * @Inject
     */
    protected ThrowableStorageInterface $throwableStorage;

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

    public function setAssetCollectionFilter(?string $assetCollectionFilter): void
    {
        $this->assetCollectionFilter = $assetCollectionFilter;
    }

    public function getAssetCollectionFilter(): ?string
    {
        return $this->assetCollectionFilter;
    }

    public function getOrderings(): array
    {
        return $this->orderings;
    }

    public function setOrderings(array $orderings): void
    {
        $this->orderings = $orderings;
    }

    public function getParentFolderIdentifier(): string
    {
        return $this->parentFolderIdentifier;
    }

    public function setParentFolderIdentifier(string $parentFolderIdentifier): void
    {
        $this->parentFolderIdentifier = $parentFolderIdentifier;
    }

    public function execute(): AssetProxyQueryResultInterface
    {
        return new PixxioAssetProxyQueryResult($this);
    }

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
