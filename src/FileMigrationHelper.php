<?php

namespace SilverStripe\Assets;

use Psr\Log\LoggerInterface;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
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
use SilverStripe\ORM\ValidationException;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Assets\Dev\Tasks\FileMigrationHelper as BaseFileMigrationHelper;

/**
 * Service to help migrate File dataobjects to the new APL.
 *
 * This service does not alter these records in such a way that prevents downgrading back to 3.x
 *
 * @deprecated 1.4.0 Use \SilverStripe\Assets\Dev\Tasks\FileMigrationHelper instead
 */
class FileMigrationHelper extends BaseFileMigrationHelper
{
    use Injectable;
    use Configurable;

    /**
     * If a file fails to validate during migration, delete it.
     * If set to false, the record will exist but will not be attached to any filesystem
     * item anymore.
     *
     * @config
     * @var bool
     */
    private static $delete_invalid_files = true;

    /**
     * Specifies the interval for every Nth file looped at which output will be logged.
     *
     * @config
     * @var int
     */
    private static $log_interval = 10;

    private static $dependencies = [
        'logger' => '%$' . LoggerInterface::class,
    ];

    /** @var LoggerInterface|null */
    private $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Perform migration
     *
     * @param string $base Absolute base path (parent of assets folder). Will default to PUBLIC_PATH
     * @return int Number of files successfully migrated
     * @throws \Exception
     */
    public function run($base = null)
    {
        if (empty($base)) {
            $base = PUBLIC_PATH;
        }

        // Check if the File dataobject has a "Filename" field.
        // If not, cannot migrate
        /** @skipUpgrade */
        if (!DB::get_schema()->hasField('File', 'Filename')) {
            return 0;
        }

        // Set max time and memory limit
        Environment::increaseTimeLimitTo();
        Environment::increaseMemoryLimitTo();

        // Loop over all files
        $processedCount = $migratedCount = 0;
        $originalState = null;
        if (class_exists(Versioned::class)) {
            $originalState = Versioned::get_reading_mode();
            Versioned::set_stage(Versioned::DRAFT);
        }

        $query = $this->getFileQuery();
        $total = $query->count();
        foreach ($query as $file) {
            // Bypass the accessor and the filename from the column
            $filename = $file->getField('Filename');
            $success = $this->migrateFile($base, $file, $filename);

            if ($processedCount % $this->config()->get('log_interval') === 0) {
                if ($this->logger) {
                    $this->logger->info("Iterated $processedCount out of $total files. Migrated $migratedCount files.");
                }
            }

            $processedCount++;
            if ($success) {
                $migratedCount++;
            }
        }
        if (class_exists(Versioned::class)) {
            Versioned::set_reading_mode($originalState);
        }
        return $migratedCount;
    }

    /**
     * Migrate a single file
     *
     * @param string $base Absolute base path (parent of assets folder)
     * @param File $file
     * @param string $legacyFilename
     * @return bool True if this file is imported successfully
     * @throws \SilverStripe\ORM\ValidationException
     */
    protected function migrateFile($base, File $file, $legacyFilename)
    {
        $useLegacyFilenames = Config::inst()->get(FlysystemAssetStore::class, 'legacy_filenames');

        // Make sure this legacy file actually exists
        $path = $base . '/' . $legacyFilename;
        if (!file_exists($path)) {
            if ($this->logger) {
                $this->logger->warning("$legacyFilename not migrated because the file does not exist ($path)");
            }
            return false;
        }

        $validationResult = $file->validate();
        if (!$validationResult->isValid()) {
            if ($this->config()->get('delete_invalid_files')) {
                $file->delete();
            }
            if ($this->logger) {
                $messages = implode("\n\n", array_map(function ($msg) {
                    return $msg['message'];
                }, $validationResult->getMessages()));

                $this->logger->warning(
                    sprintf(
                        "%s was not migrated because the file is not valid. More information: %s",
                        $legacyFilename,
                        $messages
                    )
                );
            }
            return false;
        }

        // Fix file classname if it has a classname that's incompatible with it's extention
        $extension = $file->getExtension();
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
        
        if (!is_readable($path)) {
            if ($this->logger) {
                $this->logger->warning(sprintf(
                    'File at %s is not readable and could not be migrated.',
                    $path
                ));
                return false;
            }
        }

        // Copy local file into this filesystem
        $filename = $file->generateFilename();
        $result = $file->setFromLocalFile(
            $path,
            $filename,
            null,
            null,
            [
                'conflict' => $useLegacyFilenames ?
                    AssetStore::CONFLICT_USE_EXISTING :
                    AssetStore::CONFLICT_OVERWRITE
            ]
        );

        // Move file if the APL changes filename value
        if ($result['Filename'] !== $filename) {
            $file->setFilename($result['Filename']);
        }

        // Save and publish
        try {
            $file->write();
        } catch (ValidationException $e) {
            if ($this->logger) {
                $this->logger->error(sprintf(
                    "File %s could not be migrated due to an error. 
                    This problem likely existed before the migration began. Error: %s",
                    $legacyFilename,
                    $e->getMessage()
                ));
            }
            return false;
        }
        if (class_exists(Versioned::class)) {
            $file->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        }

        if (!$useLegacyFilenames) {
            // removing the legacy file since it has been migrated now and not using legacy filenames
            $removed = unlink($path);
            if (!$removed) {
                if ($this->logger) {
                    $this->logger->warning("$legacyFilename was migrated, but failed to remove the legacy file ($path)");
                }
            }
            return $removed;
        }
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
     * @throws \Exception
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

    /**
     * Get map of File IDs to legacy filenames
     *
     * @deprecated 4.4.0
     * @return array
     * @throws \Exception
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
