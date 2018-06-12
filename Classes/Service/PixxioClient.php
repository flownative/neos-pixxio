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
use GuzzleHttp\Client;
use Neos\Flow\Http\Uri;
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
    private $fields = [
        'id', 'originalFilename', 'fileType', 'keywords', 'createDate', 'imageHeight', 'imageWidth', 'originalPath', 'subject', 'description',
        'modifyDate', 'fileSize', 'modifiedImagePaths', 'imagePath'
    ];


    /**
     * @param string $apiEndpointUri
     * @param string $apiKey
     */
    public function __construct(string $apiEndpointUri, string $apiKey)
    {
        $this->apiEndpointUri = $apiEndpointUri;
        $this->apiKey = $apiKey;
        $this->guzzleClient = new Client();
        $this->imageOptions  = [
            (object)[
                'width' => 400,
                'height' => 400,
                'quality' => 90
            ],
            (object)[
                'width' => 1500,
                'height' => 1500,
                'quality' => 90
            ]
        ];
    }

    /**
     * @param string $refreshToken
     * @throws AuthenticationFailedException
     */
    public function authenticate(string $refreshToken)
    {
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

        $result = \GuzzleHttp\json_decode($response->getBody()->getContents());
        if ($result->success === 'true' && isset($result->accessToken)) {
            $this->accessToken = $result->accessToken;
            return;
        }

        throw new AuthenticationFailedException('Authentication failed: ' . isset($result->help) ? $result->help : 'Unknown cause', 1526545835);
    }

    /**
     * @param string $id
     * @return mixed|ResponseInterface
     */
    public function getFile(string $id)
    {
        $options = new \stdClass();
        $options->imageOptions = $this->imageOptions;
        $options->fields = $this->fields;

        $uri = new Uri( $this->apiEndpointUri . '/json/files/' . $id);
        $uri = $uri->withQuery(
            'accessToken=' . $this->accessToken . '&' .
            'options=' . \GuzzleHttp\json_encode($options)
        );

        $client = new Client();
        return $client->request('GET' ,$uri);
    }

    /**
     * @param string $queryExpression
     * @param array $formatTypes
     * @param int $offset
     * @param int $limit
     * @param array $orderings
     * @return mixed|ResponseInterface
     */
    public function search(string $queryExpression, array $formatTypes, int $offset = 0, int $limit = 50, $orderings = [])
    {
        $options = new \stdClass();
        $options->pagination = $limit . '-' . intval($offset / $limit + 1);
        $options->imageOptions = $this->imageOptions;
        $options->fields = $this->fields;
        $options->formatType = $formatTypes;

        if (!empty($queryExpression)) {
            $options->searchTerm = $queryExpression;
        }

        if (isset($orderings['filename'])) {
            $options->sortBy = 'filename';
            $options->sortDirection = ($orderings['filename'] === SupportsSortingInterface::ORDER_DESCENDING) ? 'descending' : 'ascending';
        }

        if (isset($orderings['lastModified'])) {
            $options->sortBy = 'uploadDate';
            $options->sortDirection = ($orderings['lastModified'] === SupportsSortingInterface::ORDER_DESCENDING) ? 'descending' : 'ascending';
        }

        $uri = new Uri( $this->apiEndpointUri . '/json/files');
        $uri = $uri->withQuery(
            'accessToken=' . $this->accessToken . '&' .
            'options=' . \GuzzleHttp\json_encode($options)
        );

        $client = new Client();
        return $client->request('GET' ,$uri);
    }

    /**
     * @return string
     */
    public function getAccessToken(): string
    {
        return $this->accessToken;
    }
}
