<?php

namespace SilverStripe\Assets;

use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Flysystem\PublicAssetAdapter;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\Tasks\FilesystemSyncTask;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * Helper class that syncs the filesystem assets folder with the database
 * @see FilesystemSyncTask
 */
class FilesystemSyncTaskHelper
{
    use Injectable;
    use Configurable;

    /**
     * @var array allowed extensions, only files with these extensions will be migrated
     */
    private $allowedExtensions;

    /**
     * @var bool whether files should be published or not
     */
    private $publish;

    /**
     * @var int[] results data
     */
    private $results;

    /**
     * @var array recursive array of directories to remove.
     * Directories are only removed if empty once syncing is complete.
     */
    private $dirsToRemove;

    /**
     * @var string root path the sync task is ran on
     */
    private $path;

    /**
     * @var bool whether to remove files without a db entry and vice versa
     */
    private $delete_broken;

    /**
     * @var FlysystemAssetStore
     */
    private $flysystemAssetStore;

    /**
     * @var PublicAssetAdapter
     */
    private $publicAssetAdapter;

    /**
     * @param array $options
     * @return array An array containing results
     * @throws \SilverStripe\ORM\ValidationException
     */
    public function run(array $options)
    {
        $this->prepare($options);

        // Check for db files with missing filesystem files and remove those db entries
        $this->deleteBroken();

        // Sync Files
        $this->syncFiles();

        // Remove Directories
        $this->removeDirectories();

        return $this->results;
    }

    /**
     * Prepare for execution
     * @param array $options
     * @throws ValidationException
     */
    private function prepare(array $options)
    {
        // Set max time and memory limit
        Environment::increaseTimeLimitTo();
        Environment::increaseMemoryLimitTo();

        // Prepare for execution
        $this->allowedExtensions = File::getAllowedExtensions();
        $this->path = isset($options['path']) ? $options['path'] : '';
        $draft = isset($options['draft']) ? $options['draft'] : false;
        $this->publish = class_exists(Versioned::class) && !$draft;
        $this->delete_broken = isset($options['delete_broken']) ? $options['delete_broken'] : null;
        $this->dirsToRemove = [];
        $this->results = [
            'filesSyncedWithDb' => 0,
            'dirsSyncedWithDb' => 0,
            'filesRemovedFromDb' => 0,
            'filesRemovedFromFilesystem' => 0,
            'filesSkippedFromFilesystem' => 0
        ];
        $assetStore =  Injector::inst()->get(AssetStore::class);
        if (!$assetStore instanceof FlysystemAssetStore) {
            throw new ValidationException('FlysystemAssetStore is unavailable!');
        }
        /** @var FlysystemAssetStore $flysystemAssetStore */
        $this->flysystemAssetStore = $assetStore;
        /** @var PublicAssetAdapter $adapter */
        $this->publicAssetAdapter = $this->flysystemAssetStore->getPublicFilesystem()->getAdapter();
    }

    private function deleteBroken()
    {
        if ($this->delete_broken) {
            $this->deleteFilesWithMissingAssets();
        }

        DB::alteration_message(
            $this->results['filesRemovedFromDb'] . ' files removed from the database (because they are missing files on the filesystem)',
            "info"
        );
    }

    /**
     * @throws ValidationException
     */
    private function syncFiles()
    {
        foreach ($this->publicAssetAdapter->getFileGenerator($this->path, function ($file) {
            return !$this->skipFile($file);
        }) as $file) {
            if ($this->canSyncFileToDatabase($file)) {
                $this->syncFileToDatabase($file);
            }

            if ($file['type'] == 'dir') {
                $realPath = $this->publicAssetAdapter->relativeToRealPath($file['path']);
                $this->dirsToRemove[]=$realPath;
            }
        }

        DB::alteration_message(
            $this->results['filesSyncedWithDb'] . ' files on the filesystem synced with the database',
            "info"
        );
        DB::alteration_message(
            $this->results['dirsSyncedWithDb'] . ' directories on the filesystem synced with the database',
            "info"
        );
        DB::alteration_message(
            $this->results['filesRemovedFromFilesystem'] . ' hashed files removed from the filesystem (because they are missing matching database entries)',
            "info"
        );
        DB::alteration_message(
            $this->results['filesSkippedFromFilesystem'] . ' files skipped from the filesystem as they already have database entries',
            "info"
        );
    }

    private function removeDirectories()
    {
        foreach (array_reverse($this->dirsToRemove) as $dir) {
            if (count(glob("$dir/*")) === 0) {
                rmdir($dir);
            }
        }
    }

    /**
     * Whether the provided file should be skipped for syncing or not.
     * @param array $file
     * @return bool
     */
    private function skipFile($file)
    {
        // skip files starting with:
        $skipsStartingWith = [
            '_',
            '.'
        ];
        foreach ($skipsStartingWith as $skipStartingWith) {
            if (substr(basename($file['path']), 0, strlen($skipStartingWith)) === $skipStartingWith) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the provided file should be synced with the database or not.
     * @param array $fileTuple
     * @return bool
     */
    private function canSyncFileToDatabase(array $fileTuple)
    {
        $realPath = $this->publicAssetAdapter->relativeToRealPath($fileTuple['path']);

        if ($fileTuple['type'] == 'dir') {
            // check if folder with hash exists in same folder
            $sha1Short = basename($fileTuple['path']);
            $folder = Folder::find($sha1Short);

            $table = DataObject::singleton(File::class)->baseTable();
            $fileExists = DB::prepared_query("SELECT COUNT(*) FROM \"$table\" WHERE \"FileHash\" LIKE ?", ["$sha1Short%"])->value();
            if ($folder || $fileExists) {
                $this->results['filesSkippedFromFilesystem']++;
                return false;
            }

            // check if folder exists already in database
            $path = $fileTuple['path'];
            /** @var File $file */
            $file = File::find($path);
            if ($file) {
                $this->results['filesSkippedFromFilesystem']++;
                return false;
            }
        } else {
            // all variants are removed
            $parts = $this->flysystemAssetStore->parseFileID($fileTuple['path']);
            if ($parts['Variant']) {
                return false;
            } elseif ($parts['Filename']) {
                // hashed files are synced if their db entry is missing
                $file = $this->flysystemAssetStore::getFileByFilename($fileTuple['path']);
                if ($file) {
                    $this->results['filesSkippedFromFilesystem']++;
                    return false;
                }

                return true;
            }

            $file = File::find($fileTuple['path']);
            if ($file) {
                $this->results['filesSkippedFromFilesystem']++;
                return false;
            } elseif ($this->delete_broken) {
                // file exists in filesystem but not DB and is in a hash folder
                $contents = file_get_contents($realPath);
                $sha1 = sha1($contents);

                $parts = explode(DIRECTORY_SEPARATOR, $fileTuple['path']);
                $parent = array_pop($parts);
                if (substr($sha1, 0, 10) == $parent) {
                    unlink($realPath);
                    $this->dirsToRemove[]=dirname($realPath);
                    $this->results['filesRemovedFromFilesystem']++;
                    return false;
                }
            }

            // require extensions for files
            $extension = pathinfo($fileTuple['path'], PATHINFO_EXTENSION);
            if (!$extension) {
                return false;
            }

            // require file to use allowed extension
            if (!in_array($extension, $this->allowedExtensions)) {
                return false;
            }

            // check if parent is a hash
            $path = dirname($fileTuple['path']);
            $exploded = explode(DIRECTORY_SEPARATOR, $path);
            $parent = array_pop($exploded);
            $table = DataObject::singleton(File::class)->baseTable();
            $parentExists = DB::prepared_query("SELECT COUNT(*) FROM \"$table\" WHERE \"FileHash\" LIKE ?", ["$parent%"])->value();
            if ($parentExists) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sync the provided file with the database.
     * @param array $fileTuple
     * @throws \SilverStripe\ORM\ValidationException
     */
    private function syncFileToDatabase(array $fileTuple)
    {
        $realPath = $this->publicAssetAdapter->relativeToRealPath($fileTuple['path']);
        $relativePath = substr($realPath, strlen($this->publicAssetAdapter->getPathPrefix()));
        $relativeParentPath = dirname($relativePath);

        // Create the file
        if ($fileTuple['type'] == 'dir') {
            /** @var Folder $file */
            $file = Injector::inst()->create(Folder::class);
            $file->setFilename(basename($fileTuple['path']));
        } else {
            $fileClass = File::get_class_for_file_extension(pathinfo($fileTuple['path'], PATHINFO_EXTENSION));
            /** @var File $file */
            $file = Injector::inst()->create($fileClass);
            $file->setFromLocalFile($realPath, $fileTuple['path']);
        }

        /** @var File $parentFile */
        $parentFile = File::find($relativeParentPath);
        if ($parentFile) {
            $file->ParentID = $parentFile->ID;
        }

        $file->write();

        // Publish the file
        if ($this->publish) {
            $file->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        }

        // Remove the original file
        if ($fileTuple['type'] == 'dir') {
            $this->results['dirsSyncedWithDb']++;
        } else {
            unlink($realPath);
            $this->results['filesSyncedWithDb']++;
        }
    }

    private function deleteFilesWithMissingAssets()
    {
        /** @var File $file */
        foreach (File::get() as $file) {
            if ($file->exists()) {
                continue;
            }

            $file->delete();

            $this->results['filesRemovedFromDb']++;
        }
    }
}
