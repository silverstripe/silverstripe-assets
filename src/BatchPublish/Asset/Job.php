<?php

namespace App\BatchPublish\Asset;

use App\Queue;
use SilverStripe\Assets\File;
use SilverStripe\Versioned\Versioned;

class Job extends Queue\Job
{

    public function getTitle(): string
    {
        return 'Batch publish asset';
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

            /** @var File $file */
            $file = File::get()->byID($item);

            if (!$file || !$file->exists()) {
                $this->addMessage('File not found ' . $item);

                return;
            }

            $file->write();

            // force new version to be written
            $file->copyVersionToStage(Versioned::DRAFT, Versioned::DRAFT);

            $file->publishRecursive();
        });
    }
}
