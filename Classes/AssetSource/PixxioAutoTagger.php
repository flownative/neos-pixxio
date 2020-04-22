<?php
namespace Flownative\Pixxio\AssetSource;

use Flownative\Pixxio\Exception\Exception;
use Neos\Flow\Annotations as Flow;
use Neos\Media\Domain\Model\AssetInterface;
use Neos\Media\Domain\Model\AssetSource\AssetSourceInterface;
use Neos\Media\Domain\Service\AssetSourceService;
use Psr\Log\LoggerInterface;

/**
 * @Flow\Scope("singleton")
 */
class PixxioAutoTagger
{
    /**
     * @Flow\Inject()
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject
     * @var AssetSourceService
     */
    protected $assetSourceService;

    /**
     * @var AssetSourceInterface[]
     */
    protected $assetSources = [];

    /**
     * @return void
     */
    public function initializeObject(): void
    {
        $this->assetSources = $this->assetSourceService->getAssetSources();
    }

    /**
     * Wired via signal-slot with AssetService::assetCreated – see Package.php
     *
     * @param AssetInterface $asset
     */
    public function registerCreatedAsset(AssetInterface $asset): void
    {
        $this->logger->debug(sprintf('Pixxio Auto Tagger: ' . __METHOD__));

        $assetSourceIdentifier = $asset->getAssetSourceIdentifier();
        if (!isset($this->assetSources[$assetSourceIdentifier])) {
            $this->logger->debug(sprintf('Pixxio Auto Tagger: asset source %s not found', $assetSourceIdentifier));
            return;
        }
        $assetSource = $this->assetSources[$assetSourceIdentifier];
        $assetProxy = $asset->getAssetProxy();

        $this->logger->debug('Asset Source: ' . $assetSourceIdentifier);
        $this->logger->debug('Asset Proxy: ' . gettype($assetProxy));
        if (!$assetSource instanceof PixxioAssetSource || !$assetProxy instanceof PixxioAssetProxy) {
            return;
        }
        if (!$assetSource->isAutoTaggingEnabled()) {
            $this->logger->debug(sprintf('Pixxio Auto Tagger: not tagging asset %s because auto tagging is disabled', $assetProxy->getLabel()));
            return;
        }

        $this->logger->debug(sprintf('Pixxio Auto Tagger: 2'));

        try {
            $pixxioClient = $assetSource->getPixxioClient();
            if ($asset->getUsageCount() > 0) {
                $tags = array_unique(array_merge($assetProxy->getTags(), [$assetSource->getAutoTaggingInUseTag()]));
                $pixxioClient->updateFile($assetProxy->getIdentifier(), ['keywords' => implode(',', $tags)]);
                $this->logger->info('Pixxio Auto Tagger: Tagged asset %s (%s) with keyword %s', [$asset->getLabel(), $assetProxy->getIdentifier(), $assetSource->getAutoTaggingInUseTag()]);
            } else {
                $tags = array_flip($assetProxy->getTags());
                unset($tags[$assetSource->getAutoTaggingInUseTag()]);
                $tags = array_flip($tags);

                $pixxioClient->updateFile($assetProxy->getIdentifier(), ['keywords' => implode(',', $tags)]);
                $this->logger->info('Pixxio Auto Tagger: Untagged asset %s (%s) with keyword %s', [$asset->getLabel(), $assetProxy->getIdentifier(), $assetSource->getAutoTaggingInUseTag()]);
            }
        } catch (Exception $e) {
            $this->logger->error('Pixxio Auto Tagger: ' . $e->getMessage());
        }
    }

    /**
     * When an asset was removed (supposedly by a user), also remove the corresponding entry in the imported assets registry
     *
     * Wired via signal-slot with AssetService::assetRemoved – see Package.php

     * @param AssetInterface $asset
     */
    public function registerRemovedAsset(AssetInterface $asset): void
    {
        $this->logger->debug(sprintf('Pixxio Auto Tagger: ' . __METHOD__));
#        $this->registerCreatedAsset($asset);
    }
}
