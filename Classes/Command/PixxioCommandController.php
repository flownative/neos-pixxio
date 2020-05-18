<?php
namespace Flownative\Pixxio\Command;

use Flownative\Pixxio\AssetSource\PixxioAssetProxy;
use Flownative\Pixxio\AssetSource\PixxioAssetProxyRepository;
use Flownative\Pixxio\AssetSource\PixxioAssetSource;
use Flownative\Pixxio\Exception\AccessToAssetDeniedException;
use Flownative\Pixxio\Exception\AuthenticationFailedException;
use Flownative\Pixxio\Exception\Exception;
use Flownative\Pixxio\Exception\MissingClientSecretException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetSource\AssetSourceAwareInterface;
use Neos\Media\Domain\Repository\AssetRepository;

class PixxioCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    /**
     * @Flow\InjectConfiguration(path="assetSources", package="Neos.Media")
     * @var array
     */
    protected $assetSourcesConfiguration;

    /**
     * Tag used assets
     *
     * @param string $assetSource Name of the pixxio asset source
     * @param bool $quiet If set, only errors will be displayed.
     * @return void
     */
    public function tagUsedAssetsCommand(string $assetSource = 'flownative-pixxio', bool $quiet = false): void
    {
        $assetSourceIdentifier = $assetSource;
        $iterator = $this->assetRepository->findAllIterator();

        !$quiet && $this->outputLine('<b>Tagging used assets of asset source "%s" via Pixxio API:</b>', [$assetSourceIdentifier]);

        try {
            $pixxioAssetSource = new PixxioAssetSource($assetSourceIdentifier, $this->assetSourcesConfiguration[$assetSourceIdentifier]['assetSourceOptions']);
            $pixxioClient = $pixxioAssetSource->getPixxioClient();
        } catch (MissingClientSecretException $e) {
            $this->outputLine('<error>Authentication error: Missing client secret</error>');
            exit(1);
        } catch (AuthenticationFailedException $e) {
            $this->outputLine('<error>Authentication error: %s</error>', [$e->getMessage()]);
            exit(1);
        }

        if (!$pixxioAssetSource->isAutoTaggingEnabled()) {
            $this->outputLine('<error>Auto-tagging is disabled</error>');
            exit(1);
        }

        $assetProxyRepository = $pixxioAssetSource->getAssetProxyRepository();
        assert($assetProxyRepository instanceof PixxioAssetProxyRepository);
        $assetProxyRepository->getAssetProxyCache()->flush();

        foreach ($this->assetRepository->iterate($iterator) as $asset) {
            if (!$asset instanceof Asset) {
                continue;
            }
            if (!$asset instanceof AssetSourceAwareInterface) {
                continue;
            }
            if ($asset->getAssetSourceIdentifier() !== $assetSourceIdentifier) {
                continue;
            }

            try {
                $assetProxy = $asset->getAssetProxy();
            } catch (AccessToAssetDeniedException $exception) {
                $this->outputLine('   error   %s', [$exception->getMessage()]);
                continue;
            }

            if (!$assetProxy instanceof PixxioAssetProxy) {
                $this->outputLine('   error   Asset "%s" (%s) could not be accessed via Pixxio-API', [$asset->getLabel(), $asset->getIdentifier()]);
                continue;
            }

            $currentTags = $assetProxy->getTags();
            sort($currentTags);
            if ($asset->getUsageCount() > 0) {
                $newTags = array_unique(array_merge($currentTags, [$pixxioAssetSource->getAutoTaggingInUseTag()]));
                sort($newTags);

                if ($currentTags !== $newTags) {
                    $pixxioClient->updateFile($assetProxy->getIdentifier(), ['keywords' => implode(',', $newTags)]);
                    $this->outputLine('   tagged   %s %s (%s)', [$asset->getLabel(), $assetProxy->getIdentifier(), $asset->getUsageCount()]);
                } else {
                    $this->outputLine('  (tagged)  %s %s (%s)', [$asset->getLabel(), $assetProxy->getIdentifier(), $asset->getUsageCount()]);
                }
            } else {
                $newTags = array_flip($currentTags);
                unset($newTags[$pixxioAssetSource->getAutoTaggingInUseTag()]);
                $newTags = array_flip($newTags);
                sort($newTags);

                if ($currentTags !== $newTags) {
                    $pixxioClient->updateFile($assetProxy->getIdentifier(), ['keywords' => implode(',', $newTags)]);
                    $this->outputLine('   removed %s', [$asset->getLabel(), $asset->getUsageCount()]);
                } else {
                    $this->outputLine('  (removed) %s', [$asset->getLabel(), $asset->getUsageCount()]);
                }
            }
        }
    }
}
