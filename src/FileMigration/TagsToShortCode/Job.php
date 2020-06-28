<?php

namespace App\FileMigration\TagsToShortCode;

use App\Queue;
use SilverStripe\Assets\Dev\Tasks\TagsToShortcodeHelper;

/**
 * Class Job
 *
 * @property string $table
 * @property string $field
 * @package App\FileMigration\TagsToShortCode
 */
class Job extends Queue\Job
{

    public function getTitle(): string
    {
        return 'Tags to short code migration job';
    }

    public function hydrate(array $items): void
    {
        $this->items = $items;
    }

    /**
     * Code taken from @see TagsToShortcodeHelper::run()
     *
     * @param mixed $item
     */
    protected function processItem($item): void
    {
        $table = array_shift($item);
        $field = array_shift($item);
        $id = array_shift($item);

        $logger = new Queue\Logger();
        $logger->setJob($this);

        $helper = Helper::create();
        $helper->setLogger($logger);

        // Update table
        $helper->updateTable($table, $field, [$id]);
    }
}
