<?php

namespace App\FileMigration\SecureAssets;

use League\Flysystem\Filesystem;
use SilverStripe\Assets\Dev\Tasks\SecureAssetsMigrationHelper;

class Helper extends SecureAssetsMigrationHelper
{

    public function migrateFolder(Filesystem $filesystem, $path) // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        // public scope change
        return parent::migrateFolder($filesystem, $path);
    }
}
