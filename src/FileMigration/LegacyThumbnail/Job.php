<?php

namespace App\FileMigration\LegacyThumbnail;

use App\Queue;
use RuntimeException;
use SilverStripe\Assets\Dev\Tasks\LegacyThumbnailMigrationHelper;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Environment;
use SilverStripe\Versioned\Versioned;

class Job extends Queue\Job
{

    public function getTitle(): string
    {
        return 'Legacy thumbnail migration job';
    }

    public function hydrate(array $items): void
    {
        $this->items = $items;
    }

    /**
     * Code taken from @see LegacyThumbnailMigrationHelper::run()
     *
     * @param mixed $item
     */
    protected function processItem($item): void
    {
        // Set max time and memory limit
        Environment::increaseTimeLimitTo();
        Environment::setMemoryLimitMax(-1);
        Environment::increaseMemoryLimitTo(-1);

        Versioned::withVersionedMode(function () use ($item): void {
            Versioned::set_stage(Versioned::DRAFT);

            $logger = new Queue\Logger();
            $logger->setJob($this);

            $folder = $item
                ? File::get()->byID($item)
                : Folder::create();

            if ($folder === null) {
                throw new RuntimeException(sprintf('Legacy folder not found for file %d', $item));
            }

            $result = Helper::create()
                ->setLogger($logger)
                ->migrateFolder(singleton(AssetStore::class), $folder);

            if (count($result) > 0) {
                return;
            }

            $this->addMessage(sprintf('Nothing moved for folder for file %d', $item));
        });
    }
}
