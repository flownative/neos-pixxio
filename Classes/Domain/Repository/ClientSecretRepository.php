<?php

namespace Flownative\Pixxio\Domain\Repository;

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

use Flownative\Pixxio\Domain\Model\ClientSecret;
use Neos\Flow\Annotations\Scope;
use Neos\Flow\Persistence\Repository;

/**
 * @Scope("singleton")
 */
class ClientSecretRepository extends Repository
{
    /**
     * @param string $accountIdentifier
     * @return ClientSecret|null
     */
    public function findOneByFlowAccountIdentifier(string $accountIdentifier): ?ClientSecret
    {
        return $this->__call('findOneByFlowAccountIdentifier', [$accountIdentifier]);
    }
}
