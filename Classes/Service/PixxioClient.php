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
use Neos\Flow\Http\Uri;
use Neos\Media\Domain\Model\AssetSource\SupportsSortingInterface;

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
     * @var array
     */
    private $imageOptions;

    /**
     * @var array
     */
    private static $fields = [
        'id', 'fileName', 'fileType', 'keywords', 'height', 'width', 'originalFileURL', 'subject', 'description',
        'modifyDate', 'fileSize', 'previewFileURL', 'modifiedPreviewFileURLs', 'importantMetadata'
    ];

    /**
     * @var array
     */
    private static $defaultImageOptions = [
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
                'maxSize' => 1920,
                'quality' => 90
            ]
    ];

    public function __construct(string $apiEndpointUri, string $apiKey, array $apiClientOptions, array $imageOptions)
    {
        $this->apiEndpointUri = rtrim($apiEndpointUri, '/');
        $this->guzzleClient = new Client($apiClientOptions + [
                'base_uri' => $this->apiEndpointUri,
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey
                ]
            ]);

        foreach (self::$defaultImageOptions as $imageOptionPresetKey => $imageOptionPresetConfiguration) {
            $imageOption = $imageOptions[$imageOptionPresetKey] ?? $imageOptionPresetConfiguration;

            if (isset($imageOption['crop'], $imageOption['height']) && $imageOption['crop'] === false) {
                unset($imageOption['height'], $imageOption['crop']);
            }

            $this->imageOptions[] = $imageOption;
        }
    }


    /**
     * @throws Exception
     * @throws \JsonException
     */
    public function getFile(string $id): object
    {
        $options = new \stdClass();
        $options->previewFileOptions = json_encode($this->imageOptions, JSON_THROW_ON_ERROR);
        $options->responseFields = json_encode(self::$fields, JSON_THROW_ON_ERROR);

        $uri = new Uri($this->apiEndpointUri . '/files/' . $id);
        $uri = $uri->withQuery(http_build_query($options));
        return $this->request('GET', $uri);
    }

    /**
     * @throws Exception
     */
    public function updateFile(string $id, array $metadata): object
    {
        if (!isset($metadata['keywords'])) {
            throw new Exception('updateFile: Only support for keywords is implemented yet', 1587559102);
        }

        $options = new \stdClass();
        $options->keywords = $metadata['keywords'];

        $uri = new Uri($this->apiEndpointUri . '/files/' . $id);
        try {
            return $this->guzzleClient->request(
                'PUT',
                $uri,
                [
                    'form_params' => [
                        'options' => json_encode($options, JSON_THROW_ON_ERROR)
                    ]
                ]
            );
        } catch (GuzzleException $exception) {
            throw new ConnectionException('Updating file failed: ' . $exception->getMessage(), 1587559150);
        }
    }

    /**
     * @throws AuthenticationFailedException
     * @throws ConnectionException
     * @throws \JsonException
     */
    public function search(string $queryExpression, string $formatType, array $fileTypes, int $offset = 0, int $limit = 50, array $orderings = []): object
    {
        $options = new \stdClass();
        $options->pageSize = $limit;
        $options->page = (int)($offset / $limit + 1);
        $options->previewFileOptions = json_encode($this->imageOptions, JSON_THROW_ON_ERROR);
        $options->responseFields = json_encode(self::$fields, JSON_THROW_ON_ERROR);

        $filters = [];
        if ($formatType !== '') {
            $filters[] = ['filterType' => 'fileType', 'fileType' => $formatType];
        }
        if ($fileTypes !== []) {
            foreach ($fileTypes as $fileType) {
                $filters[] = ['filterType' => 'fileExtension', 'fileExtension' => $fileType];
            }
        }
        if (!empty($queryExpression)) {
            $filters[] = [
                'filterType' => 'connectorOr',
                'filters' => [[
                    'filterType' => 'subject',
                    'term' => $queryExpression,
                    'exactMatch' => false,
                    'useSynonyms' => true,
                ], [
                    'filterType' => 'description',
                    'term' => $queryExpression,
                    'exactMatch' => false,
                    'useSynonyms' => true,
                ], [
                    'filterType' => 'keyword',
                    'term' => $queryExpression,
                    'exactMatch' => false,
                    'useSynonyms' => true,
                ], [
                    'filterType' => 'fileName',
                    'term' => $queryExpression,
                    'exactMatch' => false,
                    'useSynonyms' => true,
                ]]
            ];
        }

        if ($filters !== []) {
            if (count($filters) > 1) {
                $options->filter = json_encode([
                    'filterType' => 'connectorAnd',
                    'filters' => $filters
                ], JSON_THROW_ON_ERROR);
            } else {
                $options->filter = json_encode(current($filters), JSON_THROW_ON_ERROR);
            }
        }

        if (isset($orderings['resource.filename'])) {
            $options->sortBy = 'fileName';
            $options->sortDirection = ($orderings['resource.filename'] === SupportsSortingInterface::ORDER_DESCENDING) ? 'desc' : 'asc';
        }

        if (isset($orderings['lastModified'])) {
            $options->sortBy = 'uploadDate';
            $options->sortDirection = ($orderings['lastModified'] === SupportsSortingInterface::ORDER_DESCENDING) ? 'desc' : 'asc';
        }

        $uri = (new Uri($this->apiEndpointUri . '/files'))->withQuery(http_build_query($options));
        return $this->request('GET', $uri);
    }

    /**
     * @throws ConnectionException
     * @throws AuthenticationFailedException
     * @throws \JsonException
     */
    private function request(string $method, Uri $uri): object
    {
        try {
            $response = $this->guzzleClient->request($method, $uri);
            $result = json_decode($response->getBody()->getContents(), false, 512, JSON_THROW_ON_ERROR);
            if ($result->success !== true) {
                if ($response->getStatusCode() === 401 || $response->getStatusCode() === 403) {
                    throw new AuthenticationFailedException($result->errorMessage, 1737726447);
                }
                throw new ConnectionException($result->errorMessage, 1737726800);
            }
            return $result;
        } catch (GuzzleException $exception) {
            throw new ConnectionException('Request failed: ' . $exception->getMessage(), 1737726691, $exception);
        }
    }
}
