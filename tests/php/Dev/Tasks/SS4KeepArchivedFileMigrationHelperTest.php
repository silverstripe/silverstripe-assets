<?php

namespace SilverStripe\Assets\Tests\Dev\Tasks;

use SilverStripe\Assets\AssetControlExtension;
use SilverStripe\Assets\File;
use SilverStripe\Assets\FilenameParsing\FileIDHelperResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\NaturalFileIDHelper;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Versioned\Versioned;

/**
 * Test that our FileMigrationHelper works when keep archived is enabled
 */
class SS4KeepArchivedFileMigrationHelperTest extends SS4FileMigrationHelperTest
{

    public function testMigration()
    {
        parent::testMigration();

        // We're adding some extra test on top of the other ones to fetch the archived files
        Versioned::withVersionedMode(function () {
            Versioned::set_reading_mode('Archive.2000-01-01 12:00:00.Live');
            foreach (File::get()->filter('ClassName', File::class) as $file) {
                $this->assertFileAt($file, AssetStore::VISIBILITY_PROTECTED, 'Archived');
            }

            foreach (Image::get() as $image) {
                $this->assertImageAt($image, AssetStore::VISIBILITY_PROTECTED, 'Archived');
            }
        });
    }

    protected function defineOriginStrategy()
    {
        parent::defineOriginStrategy();

        File::config()->set('keep_archived_assets', true);
    }
}
