<?php

namespace SilverStripe\Assets\Tests\Dev\Tasks;

use SilverStripe\Assets\FilenameParsing\FileIDHelperResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\NaturalFileIDHelper;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\FileHashingService;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Versioned\Versioned;

/**
 * We're testing a scenario where someone is migrating from an SS4.3 install with legacy filenames enabled.
 */
class SS4LegacyFileMigrationHelperTest extends SS4FileMigrationHelperTest
{
    /**
     * Called by set up before creating all the fixture entries. Defines the original startegies for the assets store.
     */
    protected function defineOriginStrategy()
    {
        /** @var FlysystemAssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);

        $naturalHelper = new NaturalFileIDHelper();

        $protected = FileIDHelperResolutionStrategy::create();
        $protected->setVersionedStage(Versioned::DRAFT);
        $protected->setDefaultFileIDHelper($naturalHelper);
        $protected->setResolutionFileIDHelpers([$naturalHelper]);

        $store->setProtectedResolutionStrategy($protected);

        $public = FileIDHelperResolutionStrategy::create();
        $public->setVersionedStage(Versioned::LIVE);
        $public->setDefaultFileIDHelper($naturalHelper);
        $public->setResolutionFileIDHelpers([$naturalHelper]);

        $store->setPublicResolutionStrategy($public);
    }

    protected function lookAtRestrictedFile($restrictedFileID)
    {
        // Legacy files names did not allow you to have a restricted file in draft and live simultanously
    }

    public function testMigration()
    {
        // We're overriding testMigration just to make SS4LegacyFileMigrationHelperTest is in the exception
        // stack if/when the test fails
        parent::testMigration();
    }
}
