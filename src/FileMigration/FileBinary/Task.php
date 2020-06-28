<?php

namespace App\FileMigration\FileBinary;

use App\Queue\Factory;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\ValidationException;

class Task extends Factory\Task
{

    private const CHUNK_SIZE = 10;

    /**
     * @var string
     */
    private static $segment = 'files-binary-migration-task';

    public function getDescription(): string
    {
        return 'Generate file binary migration jobs for all files';
    }

    /**
     * @param HTTPRequest $request
     * @throws ValidationException
     */
    public function run($request): void // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        $list = Helper::singleton()
            ->getFileQuery()
            ->sort('ID', 'ASC');

        $this->queueJobsFromList($request, $list, Job::class, self::CHUNK_SIZE);
    }
}
