<?php

namespace App\FileMigration\ImageThumbnail;

use App\Queue\Factory;
use SilverStripe\Assets\Image;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\ValidationException;

class Task extends Factory\Task
{

    private const CHUNK_SIZE = 100;

    /**
     * @var string
     */
    private static $segment = 'image-thumbnail-migration-task';

    public function getDescription(): string
    {
        return 'Generate image thumbnail migration jobs for all files';
    }

    /**
     * @param HTTPRequest $request
     * @throws ValidationException
     */
    public function run($request): void // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        $list = Image::get()->sort('ID', 'ASC');
        $this->queueJobsFromList($request, $list, Job::class, self::CHUNK_SIZE);
    }
}
