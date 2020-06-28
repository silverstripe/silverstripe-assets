<?php

namespace App\BatchPublish\Page;

use App\Queue\Factory;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Versioned\Versioned;

class Task extends Factory\Task
{

    private const CHUNK_SIZE = 1;

    /**
     * @var string
     */
    private static $segment = 'batch-publish-page-task';

    public function getDescription(): string
    {
        return 'Generate page publish jobs';
    }

    /**
     * @param HTTPRequest $request
     */
    public function run($request): void // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        Versioned::withVersionedMode(function () use ($request): void {
            Versioned::set_stage(Versioned::LIVE);

            $list = SiteTree::get()->sort('ID', 'ASC');
            $this->queueJobsFromList($request, $list, Job::class, self::CHUNK_SIZE);
        });
    }
}
