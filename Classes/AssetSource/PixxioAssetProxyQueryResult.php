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

use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryResultInterface;

/**
 *
 */
class PixxioAssetProxyQueryResult implements AssetProxyQueryResultInterface
{
    private PixxioAssetProxyQuery $query;

    private ?array $assetProxies = null;

    private ?int $numberOfAssetProxies = null;

    private \ArrayIterator $assetProxiesIterator;

    public function __construct(PixxioAssetProxyQuery $query)
    {
        $this->query = $query;
    }

    private function initialize(): void
    {
        if ($this->assetProxies === null) {
            $this->assetProxies = $this->query->getArrayResult();
            $this->assetProxiesIterator = new \ArrayIterator($this->assetProxies);
        }
    }

    public function getQuery(): AssetProxyQueryInterface
    {
        return clone $this->query;
    }

    public function getFirst(): ?AssetProxyInterface
    {
        $this->initialize();
        return reset($this->assetProxies);
    }

    /**
     * @return AssetProxyInterface[]
     */
    public function toArray(): array
    {
        $this->initialize();
        return $this->assetProxies;
    }

    public function current()
    {
        $this->initialize();
        return $this->assetProxiesIterator->current();
    }

    public function next(): void
    {
        $this->initialize();
        $this->assetProxiesIterator->next();
    }

    public function key()
    {
        $this->initialize();
        return $this->assetProxiesIterator->key();
    }

    public function valid(): bool
    {
        $this->initialize();
        return $this->assetProxiesIterator->valid();
    }

    public function rewind(): void
    {
        $this->initialize();
        $this->assetProxiesIterator->rewind();
    }

    public function offsetExists($offset): bool
    {
        $this->initialize();
        return $this->assetProxiesIterator->offsetExists($offset);
    }

    public function offsetGet($offset)
    {
        $this->initialize();
        return $this->assetProxiesIterator->offsetGet($offset);
    }

    public function offsetSet($offset, $value): void
    {
        $this->initialize();
        $this->assetProxiesIterator->offsetSet($offset, $value);
    }

    public function offsetUnset($offset)
    {
    }

    public function count(): int
    {
        if ($this->numberOfAssetProxies === null) {
            if (is_array($this->assetProxies)) {
                $this->numberOfAssetProxies = count($this->assetProxies);
            } else {
                $this->numberOfAssetProxies = $this->query->count();
            }
        }

        return $this->numberOfAssetProxies;
    }
}
