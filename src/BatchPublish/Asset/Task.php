<?php

namespace App\BatchPublish\Asset;

use App\Queue\Factory;
use SilverStripe\Assets\File;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Versioned\Versioned;

class Task extends Factory\Task
{

    private const CHUNK_SIZE = 5;

    /**
     * @var string
     */
    private static $segment = 'batch-publish-asset-task';

    public function getDescription(): string
    {
        return 'Generate asset publish jobs';
    }

    /**
     * @param HTTPRequest $request
     */
    public function run($request): void // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        Versioned::withVersionedMode(function () use ($request): void {
            Versioned::set_stage(Versioned::LIVE);

            $list = File::get()->sort('ID', 'ASC');
            $this->queueJobsFromList($request, $list, Job::class, self::CHUNK_SIZE);
        });
    }
}
