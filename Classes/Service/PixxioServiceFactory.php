<?php
declare(strict_types=1);

namespace Flownative\Pixxio\Service;

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

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Utility\Environment;

/**
 * Factory for the Pixx.io service class
 *
 * @Flow\Scope("singleton")
 */
class PixxioServiceFactory
{
    /**
     * @Flow\Inject
     * @var Environment
     */
    protected $environment;

    /**
     * @Flow\Inject
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * Creates a new PixxioClient instance and authenticates against the Pixx.io API
     *
     * @param string $apiEndpointUri
     * @param string $apiKey
     * @param array $apiClientOptions
     * @param array $imageOptions
     * @return PixxioClient
     */
    public function createForAccount(string $apiEndpointUri, string $apiKey, array $apiClientOptions, array $imageOptions): PixxioClient
    {
        return new PixxioClient(
            $apiEndpointUri,
            $apiKey,
            $apiClientOptions,
            $imageOptions
        );
    }
}
