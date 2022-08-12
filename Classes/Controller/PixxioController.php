<?php

namespace Flownative\Pixxio\Controller;

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

use Flownative\Pixxio\AssetSource\PixxioAssetSource;
use Flownative\Pixxio\Domain\Model\ClientSecret;
use Flownative\Pixxio\Domain\Repository\ClientSecretRepository;
use Flownative\Pixxio\Exception\AuthenticationFailedException;
use Flownative\Pixxio\Exception\MissingClientSecretException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Exception\StopActionException;
use Neos\Flow\Mvc\Exception\UnsupportedRequestTypeException;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Security\Context;
use Neos\Neos\Controller\Module\AbstractModuleController;

class PixxioController extends AbstractModuleController
{
    /**
     * @Flow\Inject
     * @var Context
     */
    protected $securityContext;

    /**
     * @Flow\InjectConfiguration(path="assetSources", package="Neos.Media")
     * @var array
     */
    protected $assetSourcesConfiguration;

    /**
     * @Flow\Inject
     * @var ClientSecretRepository
     */
    protected $clientSecretRepository;

    /**
     * @return void
     */
    public function indexAction()
    {
        $this->view->assign('apiEndpointUri', $this->assetSourcesConfiguration['flownative-pixxio']['assetSourceOptions']['apiEndpointUri']);
        $this->view->assign('sharedRefreshToken', $this->assetSourcesConfiguration['flownative-pixxio']['assetSourceOptions']['sharedRefreshToken'] ?? null);

        $account = $this->securityContext->getAccount();
        $clientSecret = $this->clientSecretRepository->findOneByFlowAccountIdentifier($account->getAccountIdentifier());
        if ($clientSecret !== null && $clientSecret->getRefreshToken()) {
            $this->view->assign('refreshToken', $clientSecret->getRefreshToken());
        }

        try {
            $assetSource = new PixxioAssetSource('flownative-pixxio', $this->assetSourcesConfiguration['flownative-pixxio']['assetSourceOptions']);
            $assetSource->getPixxioClient();
            $this->view->assign('connectionSucceeded', true);
        } catch (MissingClientSecretException $e) {
        } catch (AuthenticationFailedException $e) {
            $this->view->assign('authenticationError', $e->getMessage());
        }
    }

    /**
     * @param string $refreshToken
     * @throws StopActionException
     * @throws UnsupportedRequestTypeException
     * @throws IllegalObjectTypeException
     */
    public function updateRefreshTokenAction(string $refreshToken = null)
    {
        $account = $this->securityContext->getAccount();
        $clientSecret = $this->clientSecretRepository->findOneByFlowAccountIdentifier($account->getAccountIdentifier());

        if ($refreshToken === null) {
            if ($clientSecret !== null) {
                $this->clientSecretRepository->remove($clientSecret);
            }
            $this->redirectToUri('index');
        }

        if ($clientSecret !== null) {
            $clientSecret->setRefreshToken($refreshToken);
            $clientSecret->setAccessToken(null);
            $this->clientSecretRepository->update($clientSecret);
        } else {
            $clientSecret = new ClientSecret();
            $clientSecret->setFlowAccountIdentifier($account->getAccountIdentifier());
            $clientSecret->setRefreshToken($refreshToken);
            $clientSecret->setAccessToken(null);
            $this->clientSecretRepository->add($clientSecret);
        }

        $this->redirectToUri('index');
    }
}
