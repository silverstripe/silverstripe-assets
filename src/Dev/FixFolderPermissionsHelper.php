<?php

namespace SilverStripe\Dev\Tasks;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLUpdate;
use SilverStripe\Security\InheritedPermissionFlusher;

/**
 * Files imported from SS3 might end up with broken permissions if there is a case conflict.
 * @see https://github.com/silverstripe/silverstripe-secureassets
 * This helper class resets the `CanViewType` of files that are `NULL`.
 * You need to flush your cache after running this via CLI.
 */
class FixFolderPermissionsHelper
{
    use Injectable;

    private static $dependencies = [
        'logger' => '%$' . LoggerInterface::class,
    ];

    /** @var LoggerInterface */
    private $logger;

    public function __construct()
    {
        $this->logger = new NullLogger();
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * @return int Returns the number of records updated.
     */
    public function run()
    {
        SQLUpdate::create()
            ->setTable('"' . File::singleton()->baseTable() . '"')
            ->setAssignments(['"CanViewType"' => 'Inherit'])
            ->setWhere([
                '"CanViewType" IS NULL',
                '"ClassName"' => Folder::class
            ])
            ->execute();

        // This part won't work if run from the CLI, because Apache and the CLI don't share the same cache.
        InheritedPermissionFlusher::flush();

        return DB::affected_rows();
    }
}
