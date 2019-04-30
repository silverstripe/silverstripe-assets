<?php

namespace SilverStripe\Assets\Tests\Dev\Tasks;

use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\FilenameParsing\FileIDHelperResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\LegacyFileIDHelper;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Dev\Tasks\LegacyThumbnailMigrationHelper;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Tests\Dev\Tasks\FileMigrationHelperTest\Extension;
use SilverStripe\Core\Convert;
use SilverStripe\Dev\SapphireTest;

class LegacyThumbnailMigrationHelperTest extends SapphireTest
{
    protected $usesTransactions = false;

    protected static $fixture_file = 'LegacyThumbnailMigrationHelperTest.yml';

    protected static $required_extensions = array(
        File::class => array(
            Extension::class,
        )
    );

    /**
     * get the BASE_PATH for this test
     *
     * @return string
     */
    protected function getBasePath()
    {
        // Note that the actual filesystem base is the 'assets' subdirectory within this
        return $this->joinPaths(ASSETS_PATH, '/LegacyThumbnailMigrationHelperTest');
    }


    public function setUp()
    {
        parent::setUp();

        // Set backend root to /LegacyThumbnailMigrationHelperTest/assets
        TestAssetStore::activate('LegacyThumbnailMigrationHelperTest/assets');

        // Ensure that each file has a local record file in this new assets base
        $from = $this->joinPaths(__DIR__, '../../ImageTest/test-image-low-quality.jpg');
        foreach (File::get()->exclude('ClassName', Folder::class) as $file) {
            /** @var $file File */
            $file->setFromLocalFile($from, $file->generateFilename());
            $file->write();
            $file->publishFile();
            $dest = $this->joinPaths(TestAssetStore::base_path(), $file->generateFilename());
            Filesystem::makeFolder(dirname($dest));
            copy($from, $dest);
        }
    }

    public function tearDown()
    {
        TestAssetStore::reset();
        Filesystem::removeFolder($this->getBasePath());
        parent::tearDown();
    }

    /**
     * Tests cases where files have already been migrated to 4.3 or older,
     * and some images have since been unpublished or otherwise marked protected.
     * The previously orphaned thumbnails wouldn't have been moved to the protected store in this case.
     * The migration task should find those orphans, and reunite them with the original image.
     * And they lived happily ever after.
     */
    public function testMigratesThumbnailsForProtectedFiles()
    {
        /** @var TestAssetStore $store */
        $store = singleton(AssetStore::class); // will use TestAssetStore

        // Remove the legacy helper, otherwise it'll move the _resampled files as well,
        // which "pollutes" our test case.
        /** @var FileIDHelperResolutionStrategy $strategy */
        $publicStrategy = $store->getPublicResolutionStrategy();
        $helpers = $publicStrategy->getResolutionFileIDHelpers();
        $origHelpers = [];
        foreach ($helpers as $i => $helper) {
            $origHelpers[] = clone $helper;
            if ($helper instanceof LegacyFileIDHelper) {
                unset($helpers[$i]);
            }
        }
        $publicStrategy->setResolutionFileIDHelpers($helpers);
        $store->setPublicResolutionStrategy($publicStrategy);

        /** @var Image $image */
        $image = $this->objFromFixture(Image::class, 'nested');

        $formats = ['ResizedImage' => [60,60]];
        $expectedLegacyPath = $this->createLegacyResampledImageFixture($store, $image, $formats);

        // Protect file *after* creating legacy fixture,
        // but without moving the _resampled "orphan"
        $image->protectFile();
        $image->write();

        // We need to retain the legacy helper for later assertions.
        $publicStrategy->setResolutionFileIDHelpers($origHelpers);
        $store->setPublicResolutionStrategy($publicStrategy);

        $helper = new LegacyThumbnailMigrationHelper();
        $moved = $helper->run($store);

        $this->assertCount(1, $moved);

        $publicVariants = $store->getPublicResolutionStrategy()
            ->findVariants(
                new ParsedFileID($image->Filename, $image->Hash),
                $store->getPublicFilesystem()
            );
        $this->assertCount(0, $publicVariants);

        $protectedVariants = $store->getProtectedResolutionStrategy()
            ->findVariants(
                new ParsedFileID($image->Filename, $image->Hash),
                $store->getProtectedFilesystem()
            );

        // findVariants() returns *both* the original file and the variant
        // See FileIDHelperResolutionStrategyTest->testFindVariant()
        $this->assertCount(2, $protectedVariants);
    }

    public function testMigratesWithExistingThumbnailInNewLocation()
    {
        /** @var TestAssetStore $store */
        $store = singleton(AssetStore::class); // will use TestAssetStore

        /** @var Image $image */
        $image = $this->objFromFixture(Image::class, 'nested');

        $formats = ['ResizedImage' => [60,60]];
        $expectedNewPath = $this->getNewResampledPath($image, $formats, $keep = true);
        $expectedLegacyPath = $this->createLegacyResampledImageFixture($store, $image, $formats);

        $helper = new LegacyThumbnailMigrationHelper();
        $moved = $helper->run($store);
        $this->assertCount(0, $moved);

        // Moved contains store relative paths
        $base = TestAssetStore::base_path();

        $this->assertFileNotExists(
            $this->joinPaths($base, $expectedLegacyPath),
            'Legacy file has been removed'
        );
        $this->assertFileExists(
            $this->joinPaths($base, $expectedNewPath),
            'New file is retained (potentially with newer content)'
        );
    }

    public function testMigratesMultipleFilesInSameFolder()
    {
        /** @var TestAssetStore $store */
        $store = singleton(AssetStore::class); // will use TestAssetStore

        /** @var Image[] $image */
        $images = [
            $this->objFromFixture(Image::class, 'nested'),
            $this->objFromFixture(Image::class, 'nested-sibling')
        ];

        // Use same format for *both* files (edge cases!)
        $expected = [];
        $formats = ['ResizedImage' => [60,60]];
        foreach ($images as $image) {
            $expectedNewPath = $this->getNewResampledPath($image, $formats);
            $expectedLegacyPath = $this->createLegacyResampledImageFixture($store, $image, $formats);
            $expected[$expectedLegacyPath] = $expectedNewPath;
        }

        $helper = new LegacyThumbnailMigrationHelper();
        $moved = $helper->run($store);
        $this->assertCount(2, $moved);

        // Moved contains store relative paths
        $base = TestAssetStore::base_path();

        foreach ($expected as $expectedLegacyPath => $expectedNewPath) {
            $this->assertFileNotExists(
                $this->joinPaths($base, $expectedLegacyPath),
                'Legacy file has been removed'
            );
            $this->assertFileExists(
                $this->joinPaths($base, $expectedNewPath),
                'New file is retained (potentially with newer content)'
            );
        }
    }


    /**
     * @dataProvider dataProvider
     */
    public function testMigrate($fixtureId, $formats)
    {
        /** @var TestAssetStore $store */
        $store = singleton(AssetStore::class); // will use TestAssetStore

        /** @var Image $image */
        $image = $this->objFromFixture(Image::class, $fixtureId);

        // Simulate where the new thumbnail would be created by the system.
        // Important to do this *before* creating the legacy file,
        // because the LegacyFileIDHelper will pick it up as the "new" location otherwise
        $expectedNewPath = $this->getNewResampledPath($image, $formats);
        $expectedLegacyPath = $this->createLegacyResampledImageFixture($store, $image, $formats);

        $helper = new LegacyThumbnailMigrationHelper();
        $moved = $helper->run($store);
        $this->assertCount(1, $moved);

        // Moved contains store relative paths
        $base = TestAssetStore::base_path();

        $this->assertArrayHasKey(
            $expectedLegacyPath,
            $moved
        );
        $this->assertEquals(
            $moved[$expectedLegacyPath],
            $expectedNewPath,
            'New file is mapped as expected'
        );
        $this->assertFileNotExists(
            $this->joinPaths($base, $expectedLegacyPath),
            'Legacy file has been removed'
        );
        $this->assertFileExists(
            $this->joinPaths($base, $expectedNewPath),
            'New file has been created'
        );
        $origFolder = $image->Parent()->getFilename();
        $this->assertEquals(
            $origFolder ? $origFolder : '.' . DIRECTORY_SEPARATOR,
            dirname($expectedNewPath) . DIRECTORY_SEPARATOR,
            'Thumbnails are created in same folder as original file'
        );
    }

    public function dataProvider()
    {
        return [
            'Single variant toplevel' => [
                'toplevel',
                ['ResizedImage' => [60,60]]
            ],
            'Multi variant toplevel' => [
                'toplevel',
                ['ResizedImage' => [60,60], 'CropHeight' => [30]]
            ],
            'Multi variant nested' => [
                'nested',
                ['ResizedImage' => [60,60], 'CropHeight' => [30]]
            ]
        ];
    }

    /**
     * @param Image $baseImage
     * @param array
     * @return string Path relative to the asset store root.
     */
    protected function createLegacyResampledImageFixture(AssetStore $store, Image $baseImage, $formats)
    {
        $resampledRelativePath = $this->legacyCacheFilename($baseImage, $formats);

        // Using raw copy operation since File->copyFile() messes with the _resampled path nane,
        // and anything on asset abstraction unhelpfully copies
        // existing (new style) variants as well (creating false positives)
        $origPath = $this->joinPaths(
            TestAssetStore::base_path(),
            $baseImage->generateFilename()
        );
        $resampledPath = $this->joinPaths(
            TestAssetStore::base_path(),
            $resampledRelativePath
        );
        Filesystem::makeFolder(dirname($resampledPath));
        copy($origPath, $resampledPath);

        return $resampledRelativePath;
    }

    /**
     * Replicates the logic of creating 3.x style formatted images,
     * based on Image->cacheFilename() and Image->generateFormattedImage().
     * Will create folder structures required for this.
     *
     * @return string Path relative to the asset store root.
     */
    protected function legacyCacheFilename($image, $formats)
    {
        $cacheFilename = '';

        if ($image->Parent()->exists()) {
            $cacheFilename = $image->Parent()->Filename;
        }

        $cacheFilename = $this->joinPaths($cacheFilename, '_resampled');

        foreach($formats as $format => $args) {
            $cacheFilename = $this->joinPaths(
                $cacheFilename,
                $format . Convert::base64url_encode($args)
            );
        }

        $cacheFilename = $this->joinPaths(
            $cacheFilename,
            basename($image->generateFilename())
        );

        return $cacheFilename;
    }

    /**
     * Create a file variant to get its path, but then remove it.
     * We want to check that it's moved into the same location
     * through the migration task further along the test.
     *
     * @param Image $image
     * @param array $formats
     * @return String Path relative to the asset store root
     */
    protected function getNewResampledPath(Image $image, $formats, $keep = false)
    {
        $resampleds = [];

        /** @var Image $newResampledImage */
        $resampled = $image;

        // Perform the manipulation (only to get the resulting path)
        foreach($formats as $format => $args) {
            $resampled = call_user_func_array([$resampled, $format], $args);
            $resampleds [] = $resampled;
        }

        $path = TestAssetStore::getLocalPath($resampled, false, true); // relative to store
        $path = preg_replace('#^/#', '', $path); // normalise with other store relative paths

        // Not using File->delete() since that actually deletes the original file, not only variant.
        if (!$keep) {
            foreach ($resampleds as $resampled) {
                unlink(TestAssetStore::getLocalPath($resampled));
            }
        }

        return $path;
    }

    /**
     * @return string
     */
    protected function joinPaths() {
        $paths = array();

        foreach (func_get_args() as $arg) {
            if ($arg !== '') {
                $paths[] = $arg;
            }
        }

        return preg_replace('#/+#','/',join('/', $paths));
    }
}
