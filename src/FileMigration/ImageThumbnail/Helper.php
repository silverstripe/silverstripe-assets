<?php

namespace App\FileMigration\ImageThumbnail;

use SilverStripe\AssetAdmin\Helper\ImageThumbnailHelper;
use SilverStripe\Assets\File;

class Helper extends ImageThumbnailHelper
{

    public function generateThumbnails(File $file): array
    {
        // public scope change
        return parent::generateThumbnails($file);
    }
}
