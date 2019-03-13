<?php

namespace SilverStripe\Dev\Tasks;

use SilverStripe\Assets\FilesystemSyncTaskHelper;
use SilverStripe\Dev\BuildTask;
use SilverStripe\AssetAdmin\Helper\ImageThumbnailHelper;

/**
 * This task will synchronise the filesystem with the database.
 *
 * The execution works like this:
 *
 * - if the skip_validate_database argument is not set (default):
 *  iterate files in db and check they have valid files in the filesystem - if not, remove the db entries
 *
 * - files in legacy folders create db entries - the file is then deleted
 *
 * - if the delete_broken argument is not set (default):
 *  files in hashed folders without db entries have db entries created
 * - if the delete_broken argument is set:
 *  files in hashed folders without db entries are deleted
 *  db entry files without assets are deleted
 *
 * - delete folders that are now empty
 *
 * - generate thumbnails
 */
class FilesystemSyncTask extends BuildTask
{
    private static $segment = 'FilesystemSyncTask';

    protected $title = 'Filesystem Sync Task';

    protected $description = '
        Syncs files in the assets folder with the database.
        Files in the assets folder that do not exist in the database will be added.
        This is useful if you restore restore a file-only snapshot or want to sync a lot of files.
		Parameters:
		- path: Sets path to sync. Defaults to the root assets path.
		- draft: Files will not be published if set.
		- delete_broken: Hashed files without a matching database entry will be removed
		  and database file records will be removed if their asset is missing.
    ';

    public function run($request)
    {
        $results = FilesystemSyncTaskHelper::singleton()->run($request->getVars());

        // Generate Thumbnails
        ImageThumbnailHelper::singleton()->run();
    }
}
