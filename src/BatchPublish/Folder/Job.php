<?php

namespace App\BatchPublish\Folder;

use App\Queue;
use SilverStripe\Assets\Folder;
use SilverStripe\Versioned\Versioned;

class Job extends Queue\Job
{

    public function getTitle(): string
    {
        return 'Batch publish folder';
    }

    public function hydrate(array $items): void
    {
        $this->items = $items;
    }

    /**
     * @param mixed $item
     */
    protected function processItem($item): void
    {
        Versioned::withVersionedMode(function () use ($item): void {
            Versioned::set_stage(Versioned::DRAFT);

            /** @var Folder $folder */
            $folder = Folder::get()->byID($item);

            if (!$folder) {
                $this->addMessage('Folder not found ' . $item);

                return;
            }

            if ($folder->isPublished()) {
                return;
            }

            $folder->write();

            // force new version to be written
            $folder->copyVersionToStage(Versioned::DRAFT, Versioned::DRAFT);

            $folder->publishRecursive();
        });
    }
}
