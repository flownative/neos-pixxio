<?php

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
use Neos\Flow\Annotations\Identity;

/**
 * @Entity()
 */
class ClientSecret
{
    /**
     * @Identity()
     * @var string
     */
    protected $flowAccountIdentifier;

    /**
     * @ORM\Column(type="text")
     * @var string
     */
    protected $refreshToken;

    /**
     * @ORM\Column(nullable=true, type="text")
     * @var string
     */
    protected $accessToken;

    /**
     * @return string
     */
    public function getFlowAccountIdentifier(): string
    {
        return $this->flowAccountIdentifier;
    }

    /**
     * @param string $flowAccountIdentifier
     */
    public function setFlowAccountIdentifier(string $flowAccountIdentifier): void
    {
        $this->flowAccountIdentifier = $flowAccountIdentifier;
    }

    /**
     * @return string
     */
    public function getRefreshToken(): string
    {
        return $this->refreshToken;
    }

    /**
     * @param string $refreshToken
     */
    public function setRefreshToken(string $refreshToken): void
    {
        $this->refreshToken = $refreshToken;
    }

    /**
     * @return string
     */
    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    /**
     * @param string|null $accessToken
     */
    public function setAccessToken($accessToken): void
    {
        $this->accessToken = $accessToken;
    }
}
