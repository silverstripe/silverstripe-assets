<?php

namespace SilverStripe\Assets\Dev\Tasks;

use League\Flysystem\Filesystem;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SilverStripe\Assets\File;
use SilverStripe\Assets\FilenameParsing\LegacyFileIDHelper;
use SilverStripe\Assets\FilenameParsing\NaturalFileIDHelper;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Dev\Deprecation;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

/**
 * Service to migrate legacy format thumbnails, to avoid regenerating them on demand.
 * Related to SilverStripe\AssetAdmin\Helper\ImageThumbnailHelper,
 * which proactively generates the (new) thumbnails required for asset-admin previews.
 *
 * Migrates thumbnails regardless whether their original file still exists,
 * since they might still be hot-linked. Relies on the legacy file format redirections
 * introduced in 4.3.3 and 4.4.0 for those hot-links to continue resolving.
 *
 * Example file format:
 * Before (3.x): `assets/my-folder/_resampled/PadWzYwLDYwLCJGRkZGRkYiLDBd/FillWzYwLDMwXQ/my-image.jpg`
 * After (4.x): `assets/my-folder/0ec62bd1c4/my-image__PadWzYwLDYwLCJGRkZGRkYiLDBd_CropHeightWzMwXQ.jpg`
 *
 * Limitations:
 * - Does not migrate legacy thumbnails where the original file or folder
 *   has been renamed since an earlier 4.x migration run
 * - Does not filter out unused CMS thumbnails (they're using a new size now)
 * - Does not move legacy thumbnails to the protected store if the original file
 *   has been unpublished or protected since an earlier 4.x migration run
 * @deprecated 1.12.0 Will be removed without equivalent functionality to replace it
 */
class LegacyThumbnailMigrationHelper
{
    use Injectable;
    use Configurable;

    private static $dependencies = [
        'logger' => '%$' . LoggerInterface::class,
    ];

    /** @var LoggerInterface */
    private $logger;

    public function __construct()
    {
        Deprecation::notice('1.12.0', 'Will be removed without equivalent functionality to replace it', Deprecation::SCOPE_CLASS);
        $this->logger = new NullLogger();
    }

    /**
     * Perform migration
     *
     * @param FlysystemAssetStore $store
     * @return array Map of old to new moved paths
     */
    public function run(FlysystemAssetStore $store)
    {
        // Check if the File dataobject has a "Filename" field.
        // If not, cannot migrate
        /** @skipUpgrade */
        if (!DB::get_schema()->hasField('File', 'Filename')) {
            return [];
        }

        // Set max time and memory limit
        Environment::increaseTimeLimitTo();
        Environment::setMemoryLimitMax(-1);
        Environment::increaseMemoryLimitTo(-1);

        // Loop over all folders
        $allMoved = [];
        $originalState = null;
        if (class_exists(Versioned::class)) {
            $originalState = Versioned::get_reading_mode();
            Versioned::set_stage(Versioned::DRAFT);
        }

        // Migrate root folder (not returned from query)
        $moved = $this->migrateFolder($store, new Folder());
        if ($moved) {
            $allMoved = array_merge($allMoved, $moved);
        }

        // Migrate all nested folders
        $folders = $this->getFolderQuery();
        foreach ($folders->dataQuery()->execute() as $folderData) {
            // More memory efficient way to looping through large sets
            /** @var Folder $folder */
            $folder = $folders->createDataObject($folderData);
            $moved = $this->migrateFolder($store, $folder);
            if ($moved) {
                $allMoved = array_merge($allMoved, $moved);
            }
        }
        if (class_exists(Versioned::class)) {
            Versioned::set_reading_mode($originalState);
        }
        return $allMoved;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Migrate a folder
     *
     * @param FlysystemAssetStore $store
     * @param Folder $folder
     * @param string $legacyFilename
     * @return array Map of old to new file paths (relative to store root)
     */
    protected function migrateFolder(FlysystemAssetStore $store, Folder $folder)
    {
        $moved = [];

        // Get normalised store relative path
        $folderPath = preg_replace('#^/#', '', $folder->getFilename() ?? '');
        $resampledFolderPath = $folderPath . '_resampled';

        // Legacy thumbnails couldn't have been stored in a protected filesystem
        /** @var Filesystem $filesystem */
        $filesystem = $store->getPublicFilesystem();

        if (!$filesystem->has($resampledFolderPath)) {
            return $moved;
        }

        $failNewerVariant = false;
        $legacyFileIDParser = new LegacyFileIDHelper($failNewerVariant);
        $naturalFileIDParser = new NaturalFileIDHelper();

        $foundError = false;
        // Recurse through folder
        foreach ($filesystem->listContents($resampledFolderPath, true) as $fileInfo) {
            if ($fileInfo['type'] !== 'file') {
                continue;
            }

            $oldResampledPath = $fileInfo['path'];

            $parsedFileID = $legacyFileIDParser->parseFileID($oldResampledPath);

            // If we can't parse the fileID, let's bail on this file and print out an error
            if (!$parsedFileID) {
                $foundError = true;
                $this->logger->error('Could not find valid variants in ' . $oldResampledPath);
                continue;
            }

            // Replicate new variant format.
            // We're always placing these files in the public filesystem, *without* a content hash path.
            // This means you need to run the optional migration task introduced in 4.4,
            // which moves public files out of content hash folders.
            // Image->manipulate() is in charge of creating the full file name (incl. variant),
            // and assumes the manipulation is run on an existing original file, so we can't use it here.
            // Any AssetStore-level filesystem operations (like move()) suffer from the same limitation,
            // so we need to drop down to path based filesystem renames.
            $newResampledPath = $naturalFileIDParser->buildFileID($parsedFileID);

            // Don't overwrite existing files in the new location,
            // they might have been generated based on newer file contents
            if ($filesystem->has($newResampledPath)) {
                $filesystem->delete($oldResampledPath);
                continue;
            }

            $filesystem->rename($oldResampledPath, $newResampledPath);

            $this->logger->info(sprintf('Moved legacy thumbnail %s to %s', $oldResampledPath, $newResampledPath));

            $moved[$oldResampledPath] = $newResampledPath;
        }

        // Remove folder and any subfolders. If one or more thumbnails didn't
        // get migrated leave the folder where it is.
        if (!$foundError) {
            $files = array_filter(
                $filesystem->listContents($resampledFolderPath, true) ?? [],
                function ($file) {
                    return $file['type'] === 'file';
                }
            );
            if (empty($files)) {
                $filesystem->deleteDir($resampledFolderPath);
            } else {
                // This should not be possible. If it is, then there's probably a bug.
                $this->logger->error(sprintf(
                    'Could not remove folder %s because it still contains files. Please submit a bug report at %s.',
                    $oldResampledPath,
                    'https://github.com/silverstripe/silverstripe-assets/issues/new'
                ));
            }
        }

        return $moved;
    }

    /**
     * Get list of Folder dataobjects to inspect for
     *
     * @return \SilverStripe\ORM\DataList
     */
    protected function getFolderQuery()
    {
        $table = DataObject::singleton(File::class)->baseTable();
        // Select all records which have a Filename value, but not FileFilename.
        /** @skipUpgrade */
        return File::get()
            ->filter('ClassName', [Folder::class, 'Folder'])
            ->filter('FileFilename', ['', null])
            ->alterDataQuery(function (DataQuery $query) use ($table) {
                return $query->addSelectFromTable($table, ['Filename']);
            });
    }
}
