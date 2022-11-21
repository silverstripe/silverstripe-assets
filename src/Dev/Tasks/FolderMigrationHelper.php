<?php
namespace SilverStripe\Assets\Dev\Tasks;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\Deprecation;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataQuery;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

/**
 * Service to help migrate Folder dataobjects to the new database format.
 *
 * This service does not alter these records in such a way that prevents downgrading back to 3.x
 *
 * @deprecated 1.12.0 Will be removed without equivalent functionality to replace it
 *
 */
class FolderMigrationHelper
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

    public function __construct()
    {
        Deprecation::notice('1.12.0', 'Will be removed without equivalent functionality to replace it', Deprecation::SCOPE_CLASS);
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
    public function run()
    {
        if (empty($base)) {
            $base = PUBLIC_PATH;
        }

        // Set max time and memory limit
        Environment::increaseTimeLimitTo();
        Environment::setMemoryLimitMax(-1);
        Environment::increaseMemoryLimitTo(-1);

        $this->logger->notice('Migrate 3.x folder database records to new format');
        $ss3Count = $this->ss3Migration($base);

        return $ss3Count;
    }

    protected function ss3Migration($base)
    {
        // Check if the File dataobject has a "Filename" field.
        // If not, cannot migrate
        /** @skipUpgrade */
        if (!DB::get_schema()->hasField('File', 'Filename')) {
            return 0;
        }

        if (!class_exists(Versioned::class) || !Folder::has_extension(Versioned::class)) {
            $this->logger->info(sprintf('Folders are not versioned. Skipping migration.'));
            return 0;
        }

        // Check if we have folders to migrate
        $totalCount = $this->getQuery()->count();
        if (!$totalCount) {
            $this->logger->info('No folders required migrating');
            return 0;
        }

        $this->logger->debug(sprintf('Migrating %d folders', $totalCount));

        // Set up things before going into the loop
        $processedCount = 0;
        $successCount = 0;
        $errorsCount = 0;

        // Loop over the files to migrate
        foreach ($this->chunk($this->getQuery()) as $item) {
            ++$processedCount;

            // Bypass the accessor and the filename from the column
            $name = $item->getField('Filename');

            $this->logger->info(sprintf('Migrating folder: %s', $name));

            try {
                $item->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
                ++$successCount;
            } catch (\Exception $e) {
                $this->logger->error(sprintf('Could not migrate folder: %s', $name), ['exception' => $e]);
                ++$errorsCount;
            }
        }

        // Show summary of results
        if ($processedCount > 0) {
            $this->logger->info(sprintf('%d legacy folders have been processed.', $processedCount));
            $this->logger->info(sprintf('%d migrated successfully', $successCount));
            $this->logger->info(sprintf('%d errors', $errorsCount));
        } else {
            $this->logger->info('No 3.x legacy folders required migration found.');
        }

        return $processedCount;
    }

    /**
     * Get list of File dataobjects to import
     *
     * @return DataList
     */
    protected function getQuery()
    {
        $versionedExtension = Injector::inst()->get(Versioned::class);

        $schema = DataObject::getSchema();
        $baseDataClass = $schema->baseDataClass(Folder::class);
        $baseDataTable = $schema->tableName($baseDataClass);
        $liveDataTable = $versionedExtension->stageTable($baseDataTable, Versioned::LIVE);

        $query = Folder::get()->leftJoin(
            $liveDataTable,
            sprintf('"Live"."ID" = %s."ID"', Convert::symbol2sql($baseDataTable)),
            'Live'
        )->alterDataQuery(static function (DataQuery $q) {
            $q->selectField('"Filename"');  // used later for logging processed folders
            $q->selectField('"Live"."ID"', 'LiveID');  // needed for having clause to work
            $q->having(['"LiveID" IS NULL']);  // filters all folders without a record in the live table
        });

        return $query;
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
            foreach ($chunk as $item) {
                yield $item;
            }
            if ($chunk->count() == 0) {
                break;
            }
            $greaterThanID = $item->ID;
        }
    }
}
