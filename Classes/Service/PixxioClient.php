<?php

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

use Flownative\Pixxio\Exception\AuthenticationFailedException;
use Flownative\Pixxio\Exception\ConnectionException;
use Flownative\Pixxio\Exception\Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Utils;
use Neos\Media\Domain\Model\AssetSource\SupportsSortingInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Pixx.io API client
 */
final class PixxioClient
{
    /**
     * @var Client
     */
    private $guzzleClient;

    /**
     * @var string
     */
    private $apiEndpointUri;

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var array
     */
    private $apiClientOptions;

    /**
     * @var string
     */
    private $accessToken;

    /**
     * @var array
     */
    private $imageOptions;

    /**
     * @var array
     */
    private static array $fields = [
        'id', 'originalFilename', 'fileType', 'keywords', 'createDate', 'imageHeight', 'imageWidth', 'originalPath', 'subject', 'description',
        'modifyDate', 'fileSize', 'modifiedImagePaths', 'imagePath', 'dynamicMetadata'
    ];


    /**
     * @param string $apiEndpointUri
     * @param string $apiKey
     * @param array $apiClientOptions
     * @param array $imageOptions
     */
    public function __construct(string $apiEndpointUri, string $apiKey, array $apiClientOptions, array $imageOptions)
    {
        $this->apiEndpointUri = $apiEndpointUri;
        $this->apiKey = $apiKey;
        $this->apiClientOptions = $apiClientOptions;
        $this->guzzleClient = new Client($this->apiClientOptions);
        $this->configureImageOptionsWithFallback($imageOptions);
    }

    private function configureImageOptionsWithFallback(array $imageOptions): void
    {
        $imageOptionsPresets = [
            'thumbnailUri' =>
                [
                    'width' => 400,
                    'height' => 400,
                    'quality' => 90
                ],
            'previewUri' =>
                [
                    'width' => 1500,
                    'height' => 1500,
                    'quality' => 90
                ],
            'originalUri' =>
                [
                    'sizeMax' => 1920,
                    'quality' => 90
                ]
        ];
        foreach ($imageOptionsPresets as $imageOptionPresetKey => $imageOptionPresetConfiguration) {
            if (isset($imageOptions[$imageOptionPresetKey]) === false) {
                $this->imageOptions[] = $imageOptionPresetConfiguration;
                continue;
            }
            $imageOption = $imageOptions[$imageOptionPresetKey];
            if (isset($imageOption['crop'], $imageOption['height']) && $imageOption['crop'] === false) {
                unset($imageOption['height'], $imageOption['crop']);
            }
            $this->imageOptions[] = $imageOption;
        }
    }

    /**
     * @param string $refreshToken
     * @throws AuthenticationFailedException
     */
    public function authenticate(string $refreshToken): void
    {
        try {
            $response = $this->guzzleClient->request(
                'POST',
                $this->apiEndpointUri . '/json/accessToken',
                [
                    'form_params' => [
                        'apiKey' => $this->apiKey,
                        'refreshToken' => $refreshToken
                    ]
                ]
            );
        } catch (GuzzleException $exception) {
            throw new AuthenticationFailedException('Authentication failed: ' . $exception->getMessage(), 1542808119);
        }

        $result = Utils::jsonDecode($response->getBody()->getContents());
        if ($result->success === 'true' && isset($result->accessToken)) {
            $this->accessToken = $result->accessToken;
            return;
        }

        throw new AuthenticationFailedException('Authentication failed: ' . ($result->help ?? 'Unknown cause'), 1526545835);
    }

    /**
     * @param string $id
     * @return ResponseInterface
     * @throws ConnectionException
     */
    public function getFile(string $id): ResponseInterface
    {
        $options = new \stdClass();
        $options->imageOptions = $this->imageOptions;
        $options->fields = static::$fields;

        $uri = new Uri( $this->apiEndpointUri . '/json/files/' . $id);
        $uri = $uri->withQuery(
            'accessToken=' . $this->accessToken . '&' .
            'options=' . Utils::jsonEncode($options)
        );

        $client = new Client($this->apiClientOptions);
        try {
            return $client->request('GET', $uri);
        } catch (GuzzleException $exception) {
            throw new ConnectionException('Retrieving file failed: ' . $exception->getMessage(), 1542808207);
        }
    }

    /**
     * @param string $id
     * @param array $metadata
     * @return ResponseInterface
     * @throws Exception
     */
    public function updateFile(string $id, array $metadata): ResponseInterface
    {
        if (!isset($metadata['keywords'])) {
            throw new Exception('updateFile: Only support for keywords is implemented yet', 1587559102);
        }

        $options = new \stdClass();
        $options->keywords = $metadata['keywords'];

        $uri = new Uri( $this->apiEndpointUri . '/json/files/' . $id);
        $uri = $uri->withQuery(
            'accessToken=' . $this->accessToken
        );

        $client = new Client($this->apiClientOptions);
        try {
            return $client->request(
                'PUT',
                $uri,
                [
                    'form_params' => [
                        'options' => Utils::jsonEncode($options)
                    ]
                ]
            );
        } catch (GuzzleException $exception) {
            throw new ConnectionException('Updating file failed: ' . $exception->getMessage(), 1587559150);
        }
    }

    /**
     * @param string $queryExpression
     * @param array $formatTypes
     * @param array $fileTypes
     * @param string|null $assetCollectionFilter
     * @param int $offset
     * @param int $limit
     * @param array $orderings
     * @return ResponseInterface
     * @throws ConnectionException
     */
    public function search(string $queryExpression, array $formatTypes, array $fileTypes, string $assetCollectionFilter = null, int $offset = 0, int $limit = 50, array $orderings = []): ResponseInterface
    {
        $options = new \stdClass();
        $options->pagination = $limit . '-' . (int)($offset / $limit + 1);
        $options->imageOptions = $this->imageOptions;
        $options->fields = static::$fields;
        $options->formatType = $formatTypes;
        $options->fileType = implode(',', $fileTypes);

        if ($assetCollectionFilter !== null) {
            $options->category = 'sub/' . $assetCollectionFilter;
        }

        if (!empty($queryExpression)) {
            $options->searchTerm = urlencode($queryExpression);
        }

        if (isset($orderings['resource.filename'])) {
            $options->sortBy = 'fileName';
            $options->sortDirection = ($orderings['resource.filename'] === SupportsSortingInterface::ORDER_DESCENDING) ? 'descending' : 'ascending';
        }

        if (isset($orderings['lastModified'])) {
            $options->sortBy = 'uploadDate';
            $options->sortDirection = ($orderings['lastModified'] === SupportsSortingInterface::ORDER_DESCENDING) ? 'descending' : 'ascending';
        }

        $uri = new Uri( $this->apiEndpointUri . '/json/files');
        $uri = $uri->withQuery(
            'accessToken=' . $this->accessToken . '&' .
            'options=' . Utils::jsonEncode($options)
        );

        $client = new Client($this->apiClientOptions);
        try {
            return $client->request('GET', $uri);
        } catch (GuzzleException $exception) {
            throw new ConnectionException('Search failed: ' . $exception->getMessage(), 1542808181);
        }
    }

    /**
     * @return ResponseInterface
     * @throws ConnectionException
     */
    public function getCategories(): ResponseInterface
    {
        $uri = new Uri( $this->apiEndpointUri . '/json/categories');
        $uri = $uri->withQuery(
            'accessToken=' . $this->accessToken
        );

        $client = new Client($this->apiClientOptions);
        try {
            return $client->request('GET', $uri);
        } catch (GuzzleException $exception) {
            throw new ConnectionException('Retrieving categories failed: ' . $exception->getMessage(), 1642430939);
        }
    }

    /**
     * @return string
     */
    public function getAccessToken(): string
    {
        return $this->accessToken;
    }
}
