<?php
namespace Flownative\Pixxio\Command;

use Flownative\Pixxio\AssetSource\PixxioAssetProxy;
use Flownative\Pixxio\AssetSource\PixxioAssetSource;
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
     * @throws Exception
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

            $assetProxy = $asset->getAssetProxy();
            assert($assetProxy instanceof PixxioAssetProxy);

            if ($asset->getUsageCount() > 0) {
                $tags = array_unique(array_merge($assetProxy->getTags(), [$pixxioAssetSource->getAutoTaggingInUseTag()]));
                $pixxioClient->updateFile($assetProxy->getIdentifier(), ['keywords' => implode(',', $tags)]);
                $this->outputLine('   ✅  %s %s (%s)', [$asset->getLabel(), $assetProxy->getIdentifier(), $asset->getUsageCount()]);
            } else {
                $tags = array_flip($assetProxy->getTags());
                unset($tags[$pixxioAssetSource->getAutoTaggingInUseTag()]);
                $tags = array_flip($tags);

                $pixxioClient->updateFile($assetProxy->getIdentifier(), ['keywords' => implode(',', $tags)]);
                $this->outputLine('   🗑  %s', [$asset->getLabel(), $asset->getUsageCount()]);
            }
        }
    }
}
