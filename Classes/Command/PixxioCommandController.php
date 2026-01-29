<?php
declare(strict_types=1);

namespace Flownative\Pixxio\Command;

use Flownative\Pixxio\AssetSource\PixxioAssetProxy;
use Flownative\Pixxio\AssetSource\PixxioAssetProxyRepository;
use Flownative\Pixxio\AssetSource\PixxioAssetSource;
use Flownative\Pixxio\Exception\AccessToAssetDeniedException;
use Flownative\Pixxio\Exception\ConnectionException;
use GuzzleHttp\Utils;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\AssetSource\AssetSourceAwareInterface;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Service\AssetSourceService;

class PixxioCommandController extends CommandController
{
    /**
     * @Flow\Inject
     */
    protected AssetRepository $assetRepository;

    /**
     * @Flow\Inject
     */
    protected AssetSourceService $assetSourceService;

    /**
     * @Flow\Inject
     */
    protected AssetCollectionRepository $assetCollectionRepository;

    /**
     * @Flow\InjectConfiguration(path="assetSources", package="Neos.Media")
     */
    protected array $assetSourcesConfiguration = [];

    /**
     * List all configured (pixx.io) asset sources
     *
     * @param bool $showAll If true, all types of asset sources will be shown
     * @return void
     */
    public function listCommand(bool $showAll = false): void
    {
        $assetSourcesData = [];

        foreach ($this->assetSourceService->getAssetSources() as $assetSource) {
            if ($showAll || $assetSource instanceof PixxioAssetSource) {
                $assetSourcesData[] = [
                    $assetSource->getIdentifier(),
                    $assetSource->getLabel(),
                    get_class($assetSource),
                    $assetSource->getDescription()
                ];
            }
        }

        $this->output->outputTable($assetSourcesData, ['Identifier', 'Label', 'Type', 'Description']);
    }

    /**
     * Tag used assets
     *
     * @param string $assetSource Name of the pixx.io asset source
     * @param bool $quiet If set, only errors will be displayed
     * @return void
     */
    public function tagUsedAssetsCommand(string $assetSource, bool $quiet = false): void
    {
        $assetSourceIdentifier = $assetSource;
        !$quiet && $this->outputLine('<b>Tagging used assets of asset source "%s" via Pixxio API:</b>', [$assetSourceIdentifier]);

        /** @var PixxioAssetSource $pixxioAssetSource */
        $pixxioAssetSource = PixxioAssetSource::createFromConfiguration($assetSourceIdentifier, $this->assetSourcesConfiguration[$assetSourceIdentifier]['assetSourceOptions']);
        $pixxioClient = $pixxioAssetSource->getPixxioClient();

        if (!$pixxioAssetSource->isAutoTaggingEnabled()) {
            $this->outputLine('<error>Auto-tagging is disabled</error>');
            exit(1);
        }

        $assetProxyRepository = $pixxioAssetSource->getAssetProxyRepository();
        assert($assetProxyRepository instanceof PixxioAssetProxyRepository);
        $assetProxyRepository->getAssetProxyCache()->flush();

        $iterator = $this->assetRepository->findAllIterator();
        foreach ($this->assetRepository->iterate($iterator) as $asset) {
            $assetProxy = $this->getPixxioAssetProxy($asset, $assetSourceIdentifier);
            if ($assetProxy === null) {
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

    /**
     * Update metadata
     *
     * @param string $assetSource Name of the pixx.io asset source
     * @param bool $quiet If set, only errors will be displayed.
     * @throws IllegalObjectTypeException
     */
    public function updateMetadataCommand(string $assetSource, bool $quiet = false): void
    {
        $assetSourceIdentifier = $assetSource;
        $iterator = $this->assetRepository->findAllIterator();

        !$quiet && $this->outputLine('<b>Updating metadata of currently used assets from source "%s":</b>', [$assetSourceIdentifier]);

        $pixxioAssetSource = PixxioAssetSource::createFromConfiguration($assetSourceIdentifier, $this->assetSourcesConfiguration[$assetSourceIdentifier]['assetSourceOptions']);

        $assetProxyRepository = $pixxioAssetSource->getAssetProxyRepository();
        assert($assetProxyRepository instanceof PixxioAssetProxyRepository);
        $assetProxyRepository->getAssetProxyCache()->flush();

        $assetsWereUpdated = false;

        foreach ($this->assetRepository->iterate($iterator) as $asset) {
            $assetProxy = $this->getPixxioAssetProxy($asset, $assetSourceIdentifier);
            if ($assetProxy === null) {
                continue;
            }

            !$quiet && $this->outputLine('   %s %s', [$asset->getLabel(), $assetProxy->getIdentifier()]);

            $assetModified = false;
            $newTitle = $assetProxy->getIptcProperty('Title');
            $newCaption = $assetProxy->getIptcProperty('CaptionAbstract');
            $newCopyrightNotice = $assetProxy->getIptcProperty('CopyrightNotice');

            if ($newTitle !== $asset->getTitle()) {
                !$quiet && $this->outputLine('      <success>New title:     %s</success>', [$newTitle]);
                $asset->setTitle($newTitle);
                $assetModified = true;
            }

            if ($newCaption !== $asset->getCaption()) {
                !$quiet && $this->outputLine('      <success>New caption:   %s</success>', [$newCaption]);
                $asset->setCaption($newCaption);
                $assetModified = true;
            }

            if ($newCopyrightNotice !== $asset->getCopyrightNotice()) {
                !$quiet && $this->outputLine('      <success>New copyright:   %s</success>', [$newCopyrightNotice]);
                $asset->setCopyrightNotice($newCopyrightNotice);
                $assetModified = true;
            }

            if ($assetModified) {
                $this->assetRepository->update($asset);
                $assetsWereUpdated = true;
            }
        }

        if ($assetsWereUpdated) {
            !$quiet && $this->outputLine();
            !$quiet && $this->outputLine('💡 You may want to run ./flow flow:cache:flushone Neos_Fusion');
            !$quiet && $this->outputLine('   in order to make changes visible in the frontend');
        }
    }

    /**
     * Import pixx.io categories as asset collections
     *
     * @param string $assetSource Name of the pixx.io asset source
     * @param bool $quiet If set, only errors will be displayed
     * @param bool $dryRun If set, no changes will be made
     * @return void
     * @throws IllegalObjectTypeException
     * @throws ConnectionException
     */
    public function importCategoriesAsCollectionsCommand(string $assetSource, bool $quiet = true, bool $dryRun = false): void
    {
        !$quiet && $this->outputLine('<b>Importing directories as asset collections via pixx.io API</b>');

        try {
            /** @var PixxioAssetSource $pixxioAssetSource */
            $pixxioAssetSource = PixxioAssetSource::createFromConfiguration($assetSource, $this->assetSourcesConfiguration[$assetSource]['assetSourceOptions']);
            $pixxioClient = $pixxioAssetSource->getPixxioClient();
        } catch (\Exception) {
            $this->outputLine('<error>pixx.io client could not be created</error>');
            $this->quit(1);
        }

        $response = $pixxioClient->getDirectories();
        foreach ($response as $directoryData) {
            $directoryPath = ltrim($directoryData->path, '/');
            if ($this->shouldBeImportedAsAssetCollection($pixxioAssetSource, $directoryPath)) {
                $assetCollection = $this->assetCollectionRepository->findOneByTitle($directoryPath);

                if ($assetCollection instanceof AssetCollection) {
                    !$quiet && $this->outputLine('= %s', [$directoryPath]);
                } else {
                    if (!$dryRun) {
                        $assetCollection = new AssetCollection($directoryPath);
                        $this->assetCollectionRepository->add($assetCollection);
                    }
                    !$quiet && $this->outputLine('+ %s', [$directoryPath]);
                }
            } else {
                !$quiet && $this->outputLine('o %s', [$directoryPath]);
            }
        }

        !$quiet && $this->outputLine('<success>Import done.</success>');
    }

    public function shouldBeImportedAsAssetCollection(PixxioAssetSource $assetSource, string $directoryPath): bool
    {
        $directoriesMapping = $assetSource->getAssetSourceOptions()['mapping']['directories'];
        if (empty($directoriesMapping)) {
            $this->outputLine('<error>No directories configured for mapping</error>');
            $this->quit(1);
        }

        $directoryPath = ltrim($directoryPath, '/');

        // depth limit
        if (substr_count($directoryPath, '/') >= $assetSource->getAssetSourceOptions()['mapping']['directoriesMaximumDepth']) {
            return false;
        }

        // full match
        if (array_key_exists($directoryPath, $directoriesMapping)) {
            return $directoriesMapping[$directoryPath]['asAssetCollection'];
        }

        // glob match
        foreach ($directoriesMapping as $mappedCategory => $mapping) {
            if (fnmatch($mappedCategory, $directoryPath)) {
                return $mapping['asAssetCollection'];
            }
        }

        return false;
    }

    private function getPixxioAssetProxy(mixed $asset, string $assetSourceIdentifier): ?PixxioAssetProxy
    {
        if (!$asset instanceof AssetSourceAwareInterface) {
            return null;
        }
        if (!$asset instanceof Asset) {
            return null;
        }
        if ($asset->getAssetSourceIdentifier() !== $assetSourceIdentifier) {
            return null;
        }

        try {
            $assetProxy = $asset->getAssetProxy();
        } catch (AccessToAssetDeniedException $exception) {
            $this->outputLine('   error   %s', [$exception->getMessage()]);
            return null;
        }

        if (!$assetProxy instanceof PixxioAssetProxy) {
            $this->outputLine('   error   Asset "%s" (%s) could not be accessed via pixx.io API', [$asset->getLabel(), $asset->getIdentifier()]);
            return null;
        }

        return $assetProxy;
    }
}
