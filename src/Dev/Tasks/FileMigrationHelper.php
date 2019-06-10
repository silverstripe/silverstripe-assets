<?php
namespace SilverStripe\Assets\Dev\Tasks;

use LogicException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SilverStripe\Assets\File;
use SilverStripe\Assets\FilenameParsing\FileIDHelperResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\FileResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\LegacyFileIDHelper;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\FileHashingService;
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
        'logger' => '%$' . LoggerInterface::class . '.quiet',
    ];

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var FlysystemAssetStore
     */
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

    /**
     * @param LoggerInterface $logger
     * @return $this
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
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
        Environment::setMemoryLimitMax(-1);
        Environment::increaseMemoryLimitTo(-1);
        
        /** @var FileHashingService $hasher */
        $hasher = Injector::inst()->get(FileHashingService::class);
        $hasher::flush();

        $this->logger->notice('Step 1/2: Migrate 3.x legacy files to new format');
        $ss3Count = $this->ss3Migration($base);

        $this->logger->notice('Step 2/2: Migrate files in <4.4 format to new format');

        $ss4Count = 0;
        if (class_exists(Versioned::class) && File::has_extension(Versioned::class)) {
            Versioned::prepopulate_versionnumber_cache(File::class, Versioned::LIVE);
            Versioned::prepopulate_versionnumber_cache(File::class, Versioned::DRAFT);

            $stages = [Versioned::LIVE => 'live', Versioned::DRAFT => 'draft'];
            foreach ($stages as $stageId => $stageName) {
                $this->logger->info(sprintf('Migrating files in the "%s" stage', $stageName));
                $count = Versioned::withVersionedMode(function () use ($stageId, $stageName) {
                    Versioned::set_stage($stageId);
                    return $this->normaliseAllFiles(sprintf('on the "%s" stage', $stageName));
                });
                if ($count) {
                    $this->logger->info(sprintf('Migrated %d files in the "%s" stage', $count, $stageName));
                } else {
                    $this->logger->info(sprintf(
                        'No files required migrating in the "%s" stage',
                        $stageName
                    ));
                }

                $ss4Count += $count;
            }
        } else {
            $ss4Count = $this->normaliseAllFiles('');
            $this->logger->info(sprintf('Migrated %d files', $ss4Count));
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

        // Check if we have files to migrate
        $totalCount = $this->getFileQuery()->count();
        if (!$totalCount) {
            $this->logger->info('No files required migrating');
            return 0;
        }

        $this->logger->debug(sprintf('Migrating %d files', $totalCount));

        // Create a temporary SS3 Legacy File Resolution strategy for migrating SS3 Files
        $initialStrategy = $this->store->getPublicResolutionStrategy();
        $ss3Strategy = $this->buildSS3MigrationStrategy($initialStrategy);
        if (!$ss3Strategy) {
            $this->logger->warning(
                'Skipping this step because the asset store is using an unsupported public file ' .
                'resolution strategy.'
            );
            return 0;
        }
        $this->store->setPublicResolutionStrategy($ss3Strategy);

        // Force stage to draft
        if (class_exists(Versioned::class) && File::has_extension(Versioned::class)) {
            $originalState = Versioned::get_reading_mode();
            Versioned::set_stage(Versioned::DRAFT);
        }

        // Set up things before going into the loop
        $ss3Count = 0;
        $originalState = null;

        // Loop over the files to migrate
        try {
            foreach ($this->getLegacyFileQuery() as $file) {
                // Bypass the accessor and the filename from the column
                $filename = $file->getField('Filename');
                $success = $this->migrateFile($base, $file, $filename);
                if ($success) {
                    $ss3Count++;
                }
            }
        } finally {
            // Reset back to our initial state no matter what
            if (class_exists(Versioned::class)) {
                Versioned::set_reading_mode($originalState);
            }
            $this->store->setPublicResolutionStrategy($initialStrategy);
        }

        // Show summary of results
        if ($ss3Count > 0) {
            $this->logger->info(sprintf('%d 3.x legacy files have been migrated.', $ss3Count));
        } else {
            $this->logger->info(sprintf('No 3.x legacy files required migrating.', $ss3Count));
        }

        return $ss3Count;
    }

    /**
     * Construct a temporary SS3 File Resolution Strategy based off the provided initial strategy.
     * If `$initialStrategy` is not suitable for a migration, we return null.
     * We're overriding the helpers to avoid doing unnecessary checks.
     * @param FileResolutionStrategy $initialStrategy
     * @return int|FileIDHelperResolutionStrategy
     */
    private function buildSS3MigrationStrategy(FileResolutionStrategy $initialStrategy)
    {
        // If the project is using a custom FileResolutionStrategy, we can't be confident that our migration won't
        // break stuff, so let's bail
        if (!$initialStrategy instanceof FileIDHelperResolutionStrategy) {
            return null;
        }

        // Let's make sure the initial strategy contains a LegacyFileIDHelper. If it doesn't, the owner of the project
        // has explicitly disabled Legacy resolution, so there's no SS3 files to migrate
        $foundLegacyHelper = false;
        foreach ($initialStrategy->getResolutionFileIDHelpers() as $helper) {
            if ($helper instanceof LegacyFileIDHelper) {
                $foundLegacyHelper = true;
                break;
            }
        }
        if (!$foundLegacyHelper) {
            return null;
        }

        // Build the migration strategy
        $ss3Strategy = FileIDHelperResolutionStrategy::create();
        $ss3Strategy->setDefaultFileIDHelper($initialStrategy->getDefaultFileIDHelper());
        $ss3Strategy->setResolutionFileIDHelpers([new LegacyFileIDHelper(false)]);

        return $ss3Strategy;
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
        $path = $this->findNativeFile($base, $file, $legacyFilename);
        if ($path === false) {
            return false;
        }

        // Fix file classname if it has a classname that's incompatible with its extention
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

        // Remove invalid files
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
                        "  %s was not migrated because the file is not valid. More information: %s",
                        $legacyFilename,
                        $messages
                    )
                );
            }
            return false;
        }

        // Copy local file into this filesystem
        $dbFilename = $file->generateFilename();
        $fsFilename = $path === true ? $this->stripAssetsDir($legacyFilename) : $this->stripAssetsDir($path, $base);
        if ($path === true && $fsFilename == $dbFilename) {
            $results = $this->store->normalisePath($dbFilename);
        } else {
            $results = $this->store->setFromLocalFile(
                $base . DIRECTORY_SEPARATOR . ASSETS_DIR . DIRECTORY_SEPARATOR . $fsFilename,
                $dbFilename,
                null,
                null,
                AssetStore::VISIBILITY_PROTECTED
            );
            $this->store->delete($fsFilename, $results['Hash']);
        }

        // Move file if the APL changes filename value
        $file->File->Filename = $results['Filename'];
        $file->File->Hash = $results['Hash'];
        $file->setField('Filename', null);

        // Save and publish
        try {
            if (class_exists(Versioned::class)) {
                $file->writeToStage(Versioned::LIVE);
            } else {
                $file->write();
            }
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

        if ($dbFilename == $file->getFilename()) {
            $this->logger->info(sprintf('* SS3 file %s converted to SS4 format', $file->getFilename()));
        } else {
            $this->logger->warning(sprintf(
                '* SS3 file %s converted to SS4 format but was renamed to %s',
                $dbFilename,
                $file->getFilename()
            ));
        }

        if (!empty($results['Operations'])) {
            foreach ($results['Operations'] as $origin => $destination) {
                $this->logger->debug(sprintf('  related thumbnail %s moved to %s', $origin, $destination));
            }
        }

        return true;
    }

    /**
     * Look for a file by `$legacyFilename`. If an exact match is found return true. If no match is found return false.
     * If a match using a different case is found use the path of that file.
     * @param string $base Location of the assets folder, usually equals to ASSETS_PATH
     * @param File $file
     * @param string $legacyFilename SS3 filename prefix with ASSETS_DIR
     * @return bool|string True if file is found as-is, false if file is missing or string to alternative file
     */
    private function findNativeFile($base, File $file, $legacyFilename)
    {
        $path = $base . DIRECTORY_SEPARATOR . $legacyFilename;
        if (file_exists($path)) {
            return true;
        }

        $strippedLegacyFilename = $this->stripAssetsDir($legacyFilename);

        // Try to find an alternative file
        $legacyFilenameGlob = preg_replace_callback('/[a-z]/i', function ($matches) {
            return sprintf('[%s%s]', strtolower($matches[0]), strtoupper($matches[0]));
        }, $strippedLegacyFilename);
        
        $files = glob($base . DIRECTORY_SEPARATOR . ASSETS_DIR . DIRECTORY_SEPARATOR . $legacyFilenameGlob);

        switch (sizeof($files)) {
            case 0:
                $this->logger->error(sprintf(
                    '%s could not be migrated because no matching file was found.',
                    $legacyFilename
                ));
                return false;
            case 1:
                $path = $files[0];
                break;
            default:
                // We found multiple files with same casing, life's just too dam complicated
                $this->logger->error(sprintf(
                    '%s could not be migrated because no matching file was found. ' .
                    'Other files with alternative casing exists: %s',
                    $legacyFilename,
                    implode(', ', $files)
                ));
                return false;
        }

        // Make sure we do not have an unmigrated or migrated DB entry for our alternative file.
        $strippedPath = $this->stripAssetsDir($path, $base);
        $unmigratedFiles = $this->getFileQuery()
            ->filter('Filename:case', ASSETS_DIR . '/' . $strippedPath)
            ->exclude('ID', $file->ID);
        $migratedFiles = File::get()->filter('FileFilename:case', $strippedPath)
            ->exclude('ID', $file->ID);

        if ($unmigratedFiles->count() > 0 || $migratedFiles->count() > 0) {
            $this->logger->error(sprintf(
                '%s could not be migrated because no matching file was found.',
                $legacyFilename
            ));
            return false;
        }

        // We can use our alternative file
        $this->logger->warning(sprintf(
            '%s could not be found, but a file with an alternative casing was identified. %s will be used instead.',
            $legacyFilename,
            $strippedPath
        ));
        return $path;
    }

    /**
     * Given a path, remove the asset dir folder and the optional base path.
     * @param $path
     * @param string $base
     * @return string
     */
    private function stripAssetsDir($path, $base = '')
    {
        return preg_replace(
            sprintf(
                '#^%s%s%s?#',
                $base ? $base . DIRECTORY_SEPARATOR : DIRECTORY_SEPARATOR . '?',
                ASSETS_DIR,
                DIRECTORY_SEPARATOR
            ),
            '',
            $path
        );
    }

    /**
     * Go through the list of files and make sure each one is at its default location
     * @param string $stageString Complement of information to append to the confirmation message
     * @param bool $skipIdenticalStages Whatever files that are already present on an other stage should be skipped
     * @return int
     */
    protected function normaliseAllFiles($stageString, $skipIdenticalStages = false)
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
                    '  Can not migrate %s / %s because it does not exist %s',
                    $file->File->Filename,
                    $file->File->Hash,
                    $stageString
                ));
                continue;
            }

            $results = $this->store->normalise($file->File->Filename, $file->File->Hash);
            if ($results && !empty($results['Operations'])) {
                $this->logger->debug(
                    sprintf('  %s has been migrated %s', $file->getFilename(), $stageString)
                );
                foreach ($results['Operations'] as $origin => $destination) {
                    $this->logger->debug(sprintf(' related thumbnail %s moved to %s', $origin, $destination));
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
