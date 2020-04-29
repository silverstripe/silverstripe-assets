<?php

namespace App\FileMigration\LegacyThumbnail;

use SilverStripe\Assets\Dev\Tasks\LegacyThumbnailMigrationHelper;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Folder;
use SilverStripe\ORM\DataList;

class Helper extends LegacyThumbnailMigrationHelper
{

    public function getFolderQuery(): DataList
    {
        // public scope change
        return parent::getFolderQuery();
    }

    public function migrateFolder(FlysystemAssetStore $store, Folder $folder): array
    {
        // public scope change
        return parent::migrateFolder($store, $folder);
    }
}
