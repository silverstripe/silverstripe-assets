<?php

namespace App\FileMigration\FixFolderPermission;

use App\Queue;
use SilverStripe\Dev\Tasks\FixFolderPermissionsHelper;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;

class Job extends AbstractQueuedJob
{

    public function getTitle(): string
    {
        return 'Fix folder permissions job';
    }

    public function getJobType(): int
    {
        return QueuedJob::QUEUED;
    }

    public function setup(): void
    {
        $this->totalSteps = 1;
    }

    public function process(): void
    {
        $logger = new Queue\Logger();
        $logger->setJob($this);

        $count = FixFolderPermissionsHelper::singleton()
            ->setLogger($logger)
            ->run();

        $message = $count > 0
            ? sprintf('Repaired %s folders with broken CanViewType settings', $count)
            : 'No folders required fixes';

        $this->addMessage($message);
        $this->currentStep += 1;
        $this->isComplete = true;
    }
}
