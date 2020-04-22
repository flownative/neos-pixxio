<?php
namespace Flownative\Pixxio;

use Flownative\Pixxio\AssetSource\PixxioAutoTagger;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;
use Neos\Media\Domain\Service\AssetService;

/**
 * The Pixxio Package
 */
class Package extends BasePackage
{
    /**
     * @param Bootstrap $bootstrap The current bootstrap
     * @return void
     */
    public function boot(Bootstrap $bootstrap): void
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();
        $dispatcher->connect(AssetService::class, 'assetCreated', PixxioAutoTagger::class, 'registerCreatedAsset');
        $dispatcher->connect(AssetService::class, 'assetRemoved', PixxioAutoTagger::class, 'registerRemovedAsset');
    }
}
