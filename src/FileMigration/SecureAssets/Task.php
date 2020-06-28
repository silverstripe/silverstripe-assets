<?php

namespace App\FileMigration\SecureAssets;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\ValidationException;
use Symbiote\QueuedJobs\Services\QueuedJobService;

class Task extends BuildTask
{

    /**
     * @var string
     */
    private static $segment = 'secure-assets-migration-task';

    public function getDescription(): string
    {
        return 'Generate secure assets job';
    }

    /**
     * @param HTTPRequest $request
     * @throws ValidationException
     */
    public function run($request): void // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        $service = QueuedJobService::singleton();
        $job = new Job();
        $service->queueJob($job);
    }
}
