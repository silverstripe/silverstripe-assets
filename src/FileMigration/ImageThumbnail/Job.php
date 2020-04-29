<?php

namespace App\FileMigration\ImageThumbnail;

use App\Queue;
use SilverStripe\AssetAdmin\Helper\ImageThumbnailHelper;
use SilverStripe\Assets\File;

class Job extends Queue\Job
{

    use Queue\ExecutionTime;

    private const TIME_LIMIT = 600;

    public function getTitle(): string
    {
        return 'Image thumbnail migration job';
    }

    public function hydrate(array $items): void
    {
        $this->items = $items;
    }

    public function setup(): void
    {
        $this->remaining = $this->items;
        $this->totalSteps = count($this->items);
    }

    /**
     * Code taken from @see ImageThumbnailHelper::run()
     *
     * @param mixed $item
     */
    protected function processItem($item): void
    {
        $this->withExecutionTime(self::TIME_LIMIT, function () use ($item): void {
            /** @var File $file */
            $file = File::get()->byID($item);

            // Skip if file is not an image
            if (!$file->getIsImage()) {
                $this->addMessage(printf('File is not an image: %s', $file->Filename));

                return;
            }

            $logger = new Queue\Logger();
            $logger->setJob($this);

            $helper = Helper::create()
                ->setLogger($logger);

            $generated = $helper->generateThumbnails($file);

            if (count($generated) === 0) {
                return;
            }

            $this->addMessage(sprintf('Generated thumbnail for %s', $file->Filename));
        });
    }
}
