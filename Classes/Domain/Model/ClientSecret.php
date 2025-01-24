<?php
declare(strict_types=1);

namespace Flownative\Pixxio\Domain\Model;

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

use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations\Entity;

/**
 * @Entity()
 */
class ClientSecret
{
    /**
     * @var string
     */
    protected string $flowAccountIdentifier;

    /**
     * @var string
     */
    protected string $assetSourceIdentifier;

    /**
     * @ORM\Column(type="text")
     * @var string
     */
    protected string $refreshToken;

    /**
     * @ORM\Column(nullable=true, type="text")
     * @var string|null
     */
    protected ?string $accessToken;

    public function getFlowAccountIdentifier(): string
    {
        return $this->flowAccountIdentifier;
    }

    public function setFlowAccountIdentifier(string $flowAccountIdentifier): void
    {
        $this->flowAccountIdentifier = $flowAccountIdentifier;
    }

    public function getAssetSourceIdentifier(): string
    {
        return $this->assetSourceIdentifier;
    }

    public function setAssetSourceIdentifier(string $assetSourceIdentifier): void
    {
        $this->assetSourceIdentifier = $assetSourceIdentifier;
    }

    public function getRefreshToken(): string
    {
        return $this->refreshToken;
    }

    public function setRefreshToken(string $refreshToken): void
    {
        $this->refreshToken = $refreshToken;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function setAccessToken(?string $accessToken): void
    {
        $this->accessToken = $accessToken;
    }
}
