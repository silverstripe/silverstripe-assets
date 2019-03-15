<?php

namespace SilverStripe\Assets;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLUpdate;
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
     * If set to true, this will ensure that when Files are migrated, its 'LastEdited' date remains as its original date not updated to the date at which the task is run.
     *
     * @var boolean
     */
    private static $keep_lastedited_date = false;

    /**
     * Perform migration
     *
     * @param string $base Absolute base path (parent of assets folder). Will default to PUBLIC_PATH
     * @return int Number of files successfully migrated
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
        $count = 0;
        $originalState = null;
        if (class_exists(Versioned::class)) {
            $originalState = Versioned::get_reading_mode();
            Versioned::set_stage(Versioned::DRAFT);
        }

        foreach ($this->getFileQuery() as $file) {
            // Bypass the accessor and the filename from the column
            $filename = $file->getField('Filename');

            $success = $this->migrateFile($base, $file, $filename);
            if ($success) {
                $count++;
            }
        }
        if (class_exists(Versioned::class)) {
            Versioned::set_reading_mode($originalState);
        }
        return $count;
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
        $useLegacyFilenames = Config::inst()->get(FlysystemAssetStore::class, 'legacy_filenames');

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
        $result = $file->setFromLocalFile(
            $path,
            $filename,
            null,
            null,
            [
                'conflict' => $useLegacyFilenames ?
                AssetStore::CONFLICT_USE_EXISTING :
                AssetStore::CONFLICT_OVERWRITE,
            ]
        );

        // Move file if the APL changes filename value
        if ($result['Filename'] !== $filename) {
            $file->setFilename($result['Filename']);
        }

        // set original 'LastEdited' date if static is enabled
        $lastEditedDate = '';
        if ($this->config()->get('keep_lastedited_date')) {
            $lastEditedDate = $file->LastEdited;
        }

        // Save and publish
        $file->write();
        if (class_exists(Versioned::class)) {
            $file->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        }

        // if 'LastEdited' date static is enabled, update file's last edited using SQL query
        if ($this->config()->get('keep_lastedited_date') && $lastEditedDate) {
            $update = SQLUpdate::create('"File"')->addWhere(['ID' => $file->ID]);
            $update->assign('"LastEdited"', $lastEditedDate);
        }

        if (!$useLegacyFilenames) {
            // removing the legacy file since it has been migrated now and not using legacy filenames
            return unlink($path);
        }
        return true;
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
