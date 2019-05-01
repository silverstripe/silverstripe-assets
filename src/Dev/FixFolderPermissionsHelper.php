<?php

namespace SilverStripe\Dev\Tasks;

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

    /**
     * @return int Returns the number of records updated.
     */
    public function run()
    {
        SQLUpdate::create(
            '"' . File::singleton()->baseTable() . '"',
            ['CanViewType' => 'Inherit'],
            ['ISNULL("CanViewType")', 'ClassName' => Folder::class]
        )->execute();

        // This part won't work if run from the CLI, because Apache and the CLI don't share the same cache.
        InheritedPermissionFlusher::flush();

        return DB::affected_rows();
    }
}
