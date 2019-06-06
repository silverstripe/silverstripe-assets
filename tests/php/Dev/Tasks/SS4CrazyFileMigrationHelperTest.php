<?php

namespace SilverStripe\Assets\Tests\Dev\Tasks;

use SilverStripe\Assets\FilenameParsing\FileIDHelperResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\HashFileIDHelper;
use SilverStripe\Assets\FilenameParsing\LegacyFileIDHelper;
use SilverStripe\Assets\FilenameParsing\NaturalFileIDHelper;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\FileHashingService;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Versioned\Versioned;

/**
 * We're testing an impossible scenario where our user was using hash files for protected files and SS3 format for
 * public ones. We're transitioning to a scenario where Hash paths are used for both protected and public files.
 *
 * This is meant to test the robustness of the solution under a weird set up.
 */
class SS4CrazyFileMigrationHelperTest extends SS4FileMigrationHelperTest
{
    /**
     * Called by set up before creating all the fixture entries. Defines the original startegies for the assets store.
     */
    protected function defineOriginStrategy()
    {
        /** @var FlysystemAssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);

        $hashHelper = new HashFileIDHelper();
        $legacyHelper = new LegacyFileIDHelper();

        $protected = FileIDHelperResolutionStrategy::create();
        $protected->setVersionedStage(Versioned::DRAFT);
        $protected->setDefaultFileIDHelper($hashHelper);
        $protected->setResolutionFileIDHelpers([$hashHelper]);

        $store->setProtectedResolutionStrategy($protected);

        $public = FileIDHelperResolutionStrategy::create();
        $public->setVersionedStage(Versioned::LIVE);
        $public->setDefaultFileIDHelper($legacyHelper);
        $public->setResolutionFileIDHelpers([$legacyHelper]);

        $store->setPublicResolutionStrategy($public);
    }

    protected function defineDestinationStrategy()
    {
        /** @var FlysystemAssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);

        $hashHelper = new HashFileIDHelper();
        $naturalPath = new NaturalFileIDHelper();
        $legacyHelper = new LegacyFileIDHelper();

        $protected = FileIDHelperResolutionStrategy::create();
        $protected->setVersionedStage(Versioned::DRAFT);
        $protected->setDefaultFileIDHelper($hashHelper);
        $protected->setResolutionFileIDHelpers([$hashHelper]);

        $store->setProtectedResolutionStrategy($protected);

        $public = FileIDHelperResolutionStrategy::create();
        $public->setVersionedStage(Versioned::LIVE);
        $public->setDefaultFileIDHelper($hashHelper);
        $public->setResolutionFileIDHelpers([$hashHelper, $naturalPath, $legacyHelper]);

        $store->setPublicResolutionStrategy($public);
    }
}
