<?php

namespace SilverStripe\Assets\Dev\Tasks;

use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

/**
 * Service to help migrate File dataobjects to the new APL.
 *
 * This service does not alter these records in such a way that prevents downgrading back to 3.x
 */
class FileMigrationHelper
{
    use Injectable;
    use Configurable;

    private static $dependencies = [
        'logger' => '%$' . LoggerInterface::class,
    ];

    /** @var LoggerInterface */
    private $logger;

    /** @var FlysystemAssetStore */
    private $store;

    /**
     * If a file fails to validate during migration, delete it.
     * If set to false, the record will exist but will not be attached to any filesystem
     * item anymore.
     *
     * @config
     * @var bool
     */
    private static $delete_invalid_files = true;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Perform migration
     *
     * @param string $base Absolute base path (parent of assets folder). Will default to PUBLIC_PATH
     * @return int Number of files successfully migrated
     */
    public function run($base = null)
    {
        $this->store = Injector::inst()->get(AssetStore::class);
        if (!$this->store instanceof AssetStore || !method_exists($this->store, 'normalisePath')) {
            throw new LogicException(
                'FileMigrationHelper: Can not run if the default asset store does not have a `normalisePath` method.'
            );
        }

        if (empty($base)) {
            $base = PUBLIC_PATH;
        }

        // Set max time and memory limit
        Environment::increaseTimeLimitTo();
        Environment::increaseMemoryLimitTo();

        $this->logger->info('MIGRATING SILVERSTRIPE 3 LEGACY FILES');
        $ss3Count = $this->ss3Migration($base);

        $this->logger->info('NORMALISE SILVERTSTRIPE 4 FILES');
        $ss4Count = 0;
        if (class_exists(Versioned::class) && File::has_extension(Versioned::class)) {
            Versioned::prepopulate_versionnumber_cache(File::class, Versioned::LIVE);
            Versioned::prepopulate_versionnumber_cache(File::class, Versioned::DRAFT);

            $this->logger->info('Looking at live files');
            $ss4Count += Versioned::withVersionedMode(function () {
                Versioned::set_stage(Versioned::LIVE);
                return $this->normaliseAllFiles('on the live stage');
            });

            $this->logger->info('Looking at draft files');
            $ss4Count += Versioned::withVersionedMode(function () {
                Versioned::set_stage(Versioned::DRAFT);
                return $this->normaliseAllFiles('on the draft stage', true);
            });
        } else {
            $ss4Count = $this->normaliseAllFiles('');
        }

        if ($ss4Count > 0) {
            $this->logger->info(sprintf('%d files were normalised', $ss4Count));
        } else {
            $this->logger->info('No files needed to be normalised');
        }

        return $ss3Count + $ss4Count;
    }

    protected function ss3Migration($base)
    {
        // Check if the File dataobject has a "Filename" field.
        // If not, cannot migrate
        /** @skipUpgrade */
        if (!DB::get_schema()->hasField('File', 'Filename')) {
            return 0;
        }

        // Clean up SS3 files
        $ss3Count = 0;
        $originalState = null;
        if (class_exists(Versioned::class) && File::has_extension(Versioned::class)) {
            $originalState = Versioned::get_reading_mode();
            Versioned::set_stage(Versioned::DRAFT);
        }

        foreach ($this->getLegacyFileQuery() as $file) {
            // Bypass the accessor and the filename from the column
            $filename = $file->getField('Filename');

            $success = $this->migrateFile($base, $file, $filename);
            if ($success) {
                $ss3Count++;
            }
        }

        // Show summary of results
        if ($ss3Count > 0) {
            $this->logger->info(sprintf('%d legacy files have been migrated.', $ss3Count));
        } else {
            $this->logger->info(sprintf('No SilverStripe 3 files have been migrated.', $ss3Count));
        }

        if (class_exists(Versioned::class)) {
            Versioned::set_reading_mode($originalState);
        }

        return $ss3Count;
    }

    /**
     * Migrate a single file
     *
     * @param string $base Absolute base path (parent of assets folder)
     * @param File $file
     * @param string $legacyFilename
     * @return bool True if this file is imported successfully
     */
    protected function migrateFile($base, File $file, $legacyFilename)
    {
        // Make sure this legacy file actually exists
        $path = $base . '/' . $legacyFilename;
        if (!file_exists($path)) {
            return false;
        }

        // Remove this file if it violates allowed_extensions
        $allowed = array_filter(File::getAllowedExtensions());
        $extension = strtolower($file->getExtension());
        if (!in_array($extension, $allowed)) {
            if ($this->config()->get('delete_invalid_files')) {
                $file->delete();
            }
            return false;
        }

        // Fix file classname if it has a classname that's incompatible with it's extention
        if (!$this->validateFileClassname($file, $extension)) {
            // We disable validation (if it is enabled) so that we are able to write a corrected
            // classname, once that is changed we re-enable it for subsequent writes
            $validationEnabled = DataObject::Config()->get('validation_enabled');
            if ($validationEnabled) {
                DataObject::Config()->set('validation_enabled', false);
            }
            $destinationClass = $file->get_class_for_file_extension($extension);
            $file = $file->newClassInstance($destinationClass);
            $fileID = $file->write();
            if ($validationEnabled) {
                DataObject::Config()->set('validation_enabled', true);
            }
            $file = File::get_by_id($fileID);
        }

        // Copy local file into this filesystem
        $filename = $file->generateFilename();
        $results = $this->store->normalisePath($filename);

        // Move file if the APL changes filename value
        $file->File->Filename = $results['Filename'];
        $file->File->Hash = $results['Hash'];


        // Save and publish
        $file->write();
        if (class_exists(Versioned::class)) {
            $file->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        }

        $this->logger->info(sprintf('* SS3 file %s converted to SS4 format', $file->getFilename()));
        if (!empty($results['Operations'])) {
            foreach ($results['Operations'] as $origin => $destination) {
                $this->logger->info(sprintf('  * %s moved to %s', $origin, $destination));
            }
        }

        return true;
    }

    /**
     * Go through the list of files and make sure each one is at its default location
     * @param string $stageString Complement of information to append to the confirmation message
     * @param bool $skipIdenticalStages Whatever files that are already present on an other stage should be skipped
     * @return int
     */
    protected function normaliseAllFiles($stageString, $skipIdenticalStages=false)
    {
        $count = 0;

        $files = $this->chunk(File::get()->exclude('ClassName', [Folder::class, 'Folder']));

        /** @var File $file */
        foreach ($files as $file) {
            // There's no point doing those checks the live and draft file are the same
            if ($skipIdenticalStages && !$file->stagesDiffer()) {
                continue;
            }

            if (!$this->store->exists($file->File->Filename, $file->File->Hash)) {
                $this->logger->warning(sprintf(
                    'Can not normalise %s / %s because it does not exists.',
                    $file->File->Filename,
                    $file->File->Hash
                ));
                continue;
            }

            $results = $this->store->normalise($file->File->Filename, $file->File->Hash);
            if ($results && !empty($results['Operations'])) {
                $this->logger->info(
                    sprintf('* %s has been normalised %s', $file->getFilename(), $stageString)
                );
                foreach ($results['Operations'] as $origin => $destination) {
                    $this->logger->info(sprintf('  * %s moved to %s', $origin, $destination));
                }
                $count++;
            }
        }

        return $count;
    }
    
    /**
     * Check if a file's classname is compatible with it's extension
     *
     * @param File $file
     * @param string $extension
     * @return bool
     */
    protected function validateFileClassname($file, $extension)
    {
        $destinationClass = $file->get_class_for_file_extension($extension);
        return $file->ClassName === $destinationClass;
    }

    /**
     * Get list of File dataobjects to import
     *
     * @return DataList
     */
    protected function getFileQuery()
    {
        $table = DataObject::singleton(File::class)->baseTable();
        // Select all records which have a Filename value, but not FileFilename.
        /** @skipUpgrade */
        return File::get()
            ->exclude('ClassName', [Folder::class, 'Folder'])
            ->filter('FileFilename', array('', null))
            ->where(sprintf(
                '"%s"."Filename" IS NOT NULL AND "%s"."Filename" != \'\'',
                $table,
                $table
            )) // Non-orm field
            ->alterDataQuery(function (DataQuery $query) use ($table) {
                return $query->addSelectFromTable($table, ['Filename']);
            });
    }

    protected function getLegacyFileQuery()
    {
        return $this->chunk($this->getFileQuery());
    }

    /**
     * Split queries into smaller chunks to avoid using too much memory
     * @param DataList $query
     * @return Generator
     */
    private function chunk(DataList $query)
    {
        $chunkSize = 100;
        $greaterThanID = 0;
        $query = $query->limit($chunkSize)->sort('ID');
        while ($chunk = $query->filter('ID:GreaterThan', $greaterThanID)) {
            foreach ($chunk as $file) {
                yield $file;
            }
            if ($chunk->count() == 0) {
                break;
            }
            $greaterThanID = $file->ID;
        }
    }

    /**
     * Get map of File IDs to legacy filenames
     *
     * @deprecated 4.4.0
     * @return array
     */
    protected function getFilenameArray()
    {
        $table = DataObject::singleton(File::class)->baseTable();
        // Convert original query, ensuring the legacy "Filename" is included in the result
        /** @skipUpgrade */
        return $this
            ->getFileQuery()
            ->dataQuery()
            ->selectFromTable($table, ['ID', 'Filename'])
            ->execute()
            ->map(); // map ID to Filename
    }
}
