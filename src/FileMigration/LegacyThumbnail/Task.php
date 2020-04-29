<?php

namespace App\FileMigration\LegacyThumbnail;

use App\Queue\Factory;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Versioned\Versioned;

class Task extends Factory\Task
{

    private const CHUNK_SIZE = 20;

    /**
     * @var string
     */
    private static $segment = 'legacy-thumbnail-migration-task';

    public function getDescription(): string
    {
        return 'Generate legacy thumbnail migration job for all folders';
    }

    /**
     * @param HTTPRequest $request
     * @throws ValidationException
     */
    public function run($request): void // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        $ids = $this->getItemsToProcess();
        $this->queueJobsFromIds($request, $ids, Job::class, self::CHUNK_SIZE);
    }

    /**
     * Code taken from @see LegacyThumbnailMigrationHelper::run()
     *
     * @return array
     */
    private function getItemsToProcess(): array
    {
        // Check if the File dataobject has a "Filename" field.
        // If not, cannot migrate
        if (!DB::get_schema()->hasField('File', 'Filename')) {
            return [];
        }

        return Versioned::withVersionedMode(static function (): array {
            Versioned::set_stage(Versioned::DRAFT);

            // we start with just the root folder
            $ids = [0];

            // Migrate all nested folders
            $folders = Helper::create()
                ->getFolderQuery()
                ->sort('ID', 'ASC')
                ->columnUnique('ID');

            foreach ($folders as $id) {
                if (!$id) {
                    continue;
                }

                $ids[] = (int) $id;
            }

            return $ids;
        });
    }
}
