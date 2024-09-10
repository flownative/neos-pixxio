<?php
declare(strict_types=1);

namespace Flownative\Pixxio\Command;

use Flownative\Pixxio\AssetSource\PixxioAssetProxy;
use Flownative\Pixxio\AssetSource\PixxioAssetProxyRepository;
use Flownative\Pixxio\AssetSource\PixxioAssetSource;
use Flownative\Pixxio\Exception\AccessToAssetDeniedException;
use Flownative\Pixxio\Exception\AuthenticationFailedException;
use Flownative\Pixxio\Exception\ConnectionException;
use Flownative\Pixxio\Exception\MissingClientSecretException;
use GuzzleHttp\Utils;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Media\Domain\Model\Asset;
use Neos\Media\Domain\Model\AssetCollection;
use Neos\Media\Domain\Model\AssetSource\AssetSourceAwareInterface;
use Neos\Media\Domain\Repository\AssetCollectionRepository;
use Neos\Media\Domain\Repository\AssetRepository;
use Neos\Media\Domain\Repository\TagRepository;
use Neos\Media\Domain\Service\AssetSourceService;

class PixxioCommandController extends CommandController
{
    /**
     * @Flow\Inject
     * @var AssetRepository
     */
    protected $assetRepository;

    /**
     * @Flow\Inject
     * @var AssetSourceService
     */
    protected $assetSourceService;

    /**
     * @Flow\Inject
     * @var TagRepository
     */
    protected $tagRepository;

    /**
     * @Flow\Inject
     * @var AssetCollectionRepository
     */
    protected $assetCollectionRepository;

    /**
     * @Flow\InjectConfiguration(path="mapping", package="Flownative.Pixxio")
     */
    protected array $mapping = [];

    /**
     * @Flow\InjectConfiguration(path="assetSources", package="Neos.Media")
     */
    protected array $assetSourcesConfiguration = [];

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
        } catch (MissingClientSecretException) {
            $this->outputLine('<error>Authentication error: Missing client secret</error>');
            exit(1);
        } catch (AuthenticationFailedException $exception) {
            $this->outputLine('<error>Authentication error: %s</error>', [$exception->getMessage()]);
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

    /**
     * Update metadata
     *
     * @param string $assetSource
     * @param bool $quiet
     * @throws IllegalObjectTypeException
     */
    public function updateMetadataCommand(string $assetSource = 'flownative-pixxio', bool $quiet = false): void
    {
        $assetSourceIdentifier = $assetSource;
        $iterator = $this->assetRepository->findAllIterator();

        !$quiet && $this->outputLine('<b>Updating metadata of currently used assets from source "%s":</b>', [$assetSourceIdentifier]);

        $pixxioAssetSource = new PixxioAssetSource($assetSourceIdentifier, $this->assetSourcesConfiguration[$assetSourceIdentifier]['assetSourceOptions']);

        $assetProxyRepository = $pixxioAssetSource->getAssetProxyRepository();
        assert($assetProxyRepository instanceof PixxioAssetProxyRepository);
        $assetProxyRepository->getAssetProxyCache()->flush();

        $assetsWereUpdated = false;

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
                $asset->setTitle($newCopyrightNotice);
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
     * @param string $assetSourceIdentifier Name of the pixx.io asset source (defaults to "flownative-pixxio")
     * @param bool $quiet If set, only errors will be displayed.
     * @param bool $dryRun If set, no changes will be made.
     * @return void
     * @throws IllegalObjectTypeException
     * @throws ConnectionException
     */
    public function importCategoriesAsCollectionsCommand(string $assetSourceIdentifier = 'flownative-pixxio', bool $quiet = true, bool $dryRun = false): void
    {
        !$quiet && $this->outputLine('<b>Importing categories as asset collections via pixx.io API</b>');

        try {
            $pixxioAssetSource = new PixxioAssetSource($assetSourceIdentifier, $this->assetSourcesConfiguration[$assetSourceIdentifier]['assetSourceOptions']);
            $cantoClient = $pixxioAssetSource->getPixxioClient();
        } catch (\Exception) {
            $this->outputLine('<error>pixx.io client could not be created</error>');
            $this->quit(1);
        }

        $response = $cantoClient->getCategories();
        $responseObject = Utils::jsonDecode($response->getBody()->getContents());
        foreach ($responseObject->categories as $categoryPath) {
            $categoryPath = ltrim($categoryPath, '/');
            if ($this->shouldBeImportedAsAssetCollection($categoryPath)) {
                $assetCollection = $this->assetCollectionRepository->findOneByTitle($categoryPath);

                if ($assetCollection instanceof AssetCollection) {
                    !$quiet && $this->outputLine('= %s', [$categoryPath]);
                } else {
                    if (!$dryRun) {
                        $assetCollection = new AssetCollection($categoryPath);
                        $this->assetCollectionRepository->add($assetCollection);
                    }
                    !$quiet && $this->outputLine('+ %s', [$categoryPath]);
                }
            } else {
                !$quiet && $this->outputLine('o %s', [$categoryPath]);
            }
        }

        !$quiet && $this->outputLine('<success>Import done.</success>');
    }

    public function shouldBeImportedAsAssetCollection(string $categoryPath): bool
    {
        $categoriesMapping = $this->mapping['categories'];
        if (empty($categoriesMapping)) {
            $this->outputLine('<error>No categories configured for mapping</error>');
            $this->quit(1);
        }

        $categoryPath = ltrim($categoryPath, '/');

        // depth limit
        if (substr_count($categoryPath, '/') >= $this->mapping['categoriesMaximumDepth']) {
            return false;
        }

        // full match
        if (array_key_exists($categoryPath, $categoriesMapping)) {
            return $categoriesMapping[$categoryPath]['asAssetCollection'];
        }

        // glob match
        foreach ($categoriesMapping as $mappedCategory => $mapping) {
            if (fnmatch($mappedCategory, $categoryPath)) {
                return $mapping['asAssetCollection'];
            }
        }

        return false;
    }
}
