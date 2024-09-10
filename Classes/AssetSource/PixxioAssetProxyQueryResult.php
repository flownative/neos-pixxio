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

use Flownative\Pixxio\Exception\ConnectionException;
use Neos\Media\Domain\Model\AssetSource\AssetProxy\AssetProxyInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryInterface;
use Neos\Media\Domain\Model\AssetSource\AssetProxyQueryResultInterface;

/**
 *
 */
class PixxioAssetProxyQueryResult implements AssetProxyQueryResultInterface
{
    /**
     * @var PixxioAssetProxyQuery
     */
    private $query;

    /**
     * @var array
     */
    private $assetProxies;

    /**
     * @var int
     */
    private $numberOfAssetProxies;

    /**
     * @var \ArrayIterator
     */
    private $assetProxiesIterator;

    /**
     * @param PixxioAssetProxyQuery $query
     */
    public function __construct(PixxioAssetProxyQuery $query)
    {
        $this->query = $query;
    }

    /**
     * @return void
     */
    private function initialize(): void
    {
        if ($this->assetProxies === null) {
            $this->assetProxies = $this->query->getArrayResult();
            $this->assetProxiesIterator = new \ArrayIterator($this->assetProxies);
        }
    }

    /**
     * @return AssetProxyQueryInterface
     */
    public function getQuery(): AssetProxyQueryInterface
    {
        return clone $this->query;
    }

    /**
     * @return AssetProxyInterface|null
     */
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

    public function next()
    {
        $this->initialize();
        $this->assetProxiesIterator->next();
    }

    public function key()
    {
        $this->initialize();
        return $this->assetProxiesIterator->key();
    }

    public function valid()
    {
        $this->initialize();
        return $this->assetProxiesIterator->valid();
    }

    public function rewind()
    {
        $this->initialize();
        $this->assetProxiesIterator->rewind();
    }

    public function offsetExists($offset)
    {
        $this->initialize();
        return $this->assetProxiesIterator->offsetExists($offset);
    }

    public function offsetGet($offset)
    {
        $this->initialize();
        return $this->assetProxiesIterator->offsetGet($offset);
    }

    public function offsetSet($offset, $value)
    {
        $this->initialize();
        $this->assetProxiesIterator->offsetSet($offset, $value);
    }

    public function offsetUnset($offset)
    {
    }

    /**
     * @return int
     * @throws ConnectionException
     */
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
