<?php

namespace App\BatchPublish\Page;

use App\Queue;
use Page;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Versioned\Versioned;

class Job extends Queue\Job
{

    public function getTitle(): string
    {
        return 'Batch publish page';
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

            /** @var SiteTree $page */
            $page = SiteTree::get()->byID($item);

            if ($page === null) {
                $this->addMessage('Page not found ' . $item);

                return;
            }

            Page::singleton()->withSkippedSiblingSortPublish(static function () use ($page): void {
                $page->write();

                // force new version to be written
                $page->copyVersionToStage(Versioned::DRAFT, Versioned::DRAFT);

                $page->publishRecursive();
            });
        });
    }
}
