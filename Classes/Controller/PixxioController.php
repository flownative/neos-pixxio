<?php
declare(strict_types=1);

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
     */
    protected array $assetSourcesConfiguration = [];

    /**
     * @Flow\Inject
     * @var ClientSecretRepository
     */
    protected $clientSecretRepository;

    public function indexAction(): void
    {
        $account = $this->securityContext->getAccount();

        $assetSourcesData = [];
        foreach ($this->assetSourcesConfiguration as $assetSourceIdentifier => $assetSourceConfiguration) {
            $assetSourceData = [];
            if ($assetSourceConfiguration['assetSource'] !== PixxioAssetSource::class) {
                continue;
            }

            $assetSourceData['label'] = $assetSourceConfiguration['assetSourceOptions']['label'] ?? 'pixx.io Asset Source';
            $assetSourceData['description'] = $assetSourceConfiguration['assetSourceOptions']['description'] ?? null;
            $assetSourceData['apiEndpointUri'] = $assetSourceConfiguration['assetSourceOptions']['apiEndpointUri'] ?? null;
            $assetSourceData['sharedRefreshToken'] = $assetSourceConfiguration['assetSourceOptions']['sharedRefreshToken'] ?? null;
            $clientSecret = $account ? $this->clientSecretRepository->findOneByIdentifiers($assetSourceIdentifier, $account->getAccountIdentifier()) : null;
            if ($clientSecret !== null && $clientSecret->getRefreshToken()) {
                $assetSourceData['refreshToken'] = $clientSecret->getRefreshToken();
            }
            try {
                $assetSource = new PixxioAssetSource($assetSourceIdentifier, $assetSourceConfiguration['assetSourceOptions']);
                $assetSource->getPixxioClient();
                $assetSourceData['connectionSucceeded'] = true;
            } catch (MissingClientSecretException|AuthenticationFailedException $exception) {
                $assetSourceData['authenticationError'] = $exception->getMessage();
            }

            $assetSourcesData[$assetSourceIdentifier] = $assetSourceData;
        }
        $this->view->assign('assetSourcesData', $assetSourcesData);
    }

    /**
     * @throws IllegalObjectTypeException
     * @throws UnsupportedRequestTypeException
     */
    public function updateRefreshTokenAction(string $assetSourceIdentifier, string $refreshToken = null): void
    {
        $account = $this->securityContext->getAccount();
        $clientSecret = $this->clientSecretRepository->findOneByIdentifiers($assetSourceIdentifier, $account->getAccountIdentifier());

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
            $clientSecret->setAssetSourceIdentifier($assetSourceIdentifier);
            $clientSecret->setFlowAccountIdentifier($account->getAccountIdentifier());
            $clientSecret->setRefreshToken($refreshToken);
            $clientSecret->setAccessToken(null);
            $this->clientSecretRepository->add($clientSecret);
        }

        $this->redirect('index');
    }
}
