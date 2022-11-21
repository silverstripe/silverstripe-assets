<?php

namespace SilverStripe\Assets\Tests\Dev\Tasks;

use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Dev\Tasks\LegacyThumbnailMigrationHelper;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Tests\Dev\Tasks\FileMigrationHelperTest\Extension;
use SilverStripe\Core\Convert;
use SilverStripe\Dev\Deprecation;
use SilverStripe\Dev\SapphireTest;

class LegacyThumbnailMigrationHelperTest extends SapphireTest
{
    const CORE_VERSION_3_0_0 = '300';

    const CORE_VERSION_3_3_0 = '330';

    protected $usesTransactions = false;

    protected static $fixture_file = 'LegacyThumbnailMigrationHelperTest.yml';

    protected static $required_extensions = [
        File::class => [
            Extension::class,
        ]
    ];

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


    protected function setUp(): void
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
            Filesystem::makeFolder(dirname($dest ?? ''));
            copy($from ?? '', $dest ?? '');
        }
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        Filesystem::removeFolder($this->getBasePath());
        parent::tearDown();
    }

    /**
     * @dataProvider coreVersionsProvider
     */
    public function testMigratesWithExistingThumbnailInNewLocation($coreVersion)
    {
        if (Deprecation::isEnabled()) {
            $this->markTestSkipped('Test calls deprecated code');
        }
        /** @var TestAssetStore $store */
        $store = singleton(AssetStore::class); // will use TestAssetStore

        /** @var Image $image */
        $image = $this->objFromFixture(Image::class, 'nested');

        $formats = ['ResizedImage' => [60,60]];
        $expectedNewPath = $this->getNewResampledPath($image, $formats, $keep = true);
        $expectedLegacyPath = $this->createLegacyResampledImageFixture($store, $image, $formats, $coreVersion);

        $helper = new LegacyThumbnailMigrationHelper();
        $moved = $helper->run($store);
        $this->assertCount(0, $moved);

        // Moved contains store relative paths
        $base = TestAssetStore::base_path();

        $this->assertFileDoesNotExist(
            $this->joinPaths($base, $expectedLegacyPath),
            'Legacy file has been removed'
        );
        $this->assertFileExists(
            $this->joinPaths($base, $expectedNewPath),
            'New file is retained (potentially with newer content)'
        );
    }

    /**
     * @dataProvider coreVersionsProvider
     */
    public function testMigratesMultipleFilesInSameFolder($coreVersion)
    {
        if (Deprecation::isEnabled()) {
            $this->markTestSkipped('Test calls deprecated code');
        }
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
            $expectedLegacyPath = $this->createLegacyResampledImageFixture($store, $image, $formats, $coreVersion);
            $expected[$expectedLegacyPath] = $expectedNewPath;
        }

        $helper = new LegacyThumbnailMigrationHelper();
        $moved = $helper->run($store);
        $this->assertCount(2, $moved);

        // Moved contains store relative paths
        $base = TestAssetStore::base_path();

        foreach ($expected as $expectedLegacyPath => $expectedNewPath) {
            $this->assertFileDoesNotExist(
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
    public function testMigrate($fixtureId, $formats, $coreVersion)
    {
        if (Deprecation::isEnabled()) {
            $this->markTestSkipped('Test calls deprecated code');
        }
        /** @var TestAssetStore $store */
        $store = singleton(AssetStore::class); // will use TestAssetStore

        /** @var Image $image */
        $image = $this->objFromFixture(Image::class, $fixtureId);

        // Simulate where the new thumbnail would be created by the system.
        // Important to do this *before* creating the legacy file,
        // because the LegacyFileIDHelper will pick it up as the "new" location otherwise
        $expectedNewPath = $this->getNewResampledPath($image, $formats);
        $expectedLegacyPath = $this->createLegacyResampledImageFixture($store, $image, $formats, $coreVersion);

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
        $this->assertFileDoesNotExist(
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
            dirname($expectedNewPath ?? '') . DIRECTORY_SEPARATOR,
            'Thumbnails are created in same folder as original file'
        );
    }

    public function dataProvider()
    {
        return [
            'Single variant toplevel 3.0.0' => [
                'toplevel',
                ['ResizedImage' => [60,60]],
                self::CORE_VERSION_3_0_0
            ],
            'Single variant toplevel >=3.3.0' => [
                'toplevel',
                ['ResizedImage' => [60,60]],
                self::CORE_VERSION_3_3_0
            ],
            'Multi variant toplevel 3.0.0' => [
                'toplevel',
                ['ResizedImage' => [60,60], 'CropHeight' => [30]],
                self::CORE_VERSION_3_0_0
            ],
            'Multi variant toplevel >=3.3.0' => [
                'toplevel',
                ['ResizedImage' => [60,60], 'CropHeight' => [30]],
                self::CORE_VERSION_3_3_0
            ],
            'Multi variant nested 3.0.0' => [
                'nested',
                ['ResizedImage' => [60,60], 'CropHeight' => [30]],
                self::CORE_VERSION_3_0_0
            ],
            'Multi variant nested >=3.3.0' => [
                'nested',
                ['ResizedImage' => [60,60], 'CropHeight' => [30]],
                self::CORE_VERSION_3_3_0
            ]
        ];
    }

    public function coreVersionsProvider()
    {
        return [
            '3.0.0' => [self::CORE_VERSION_3_0_0],
            '3.3.0' => [self::CORE_VERSION_3_3_0]
        ];
    }

    /**
     * @param AssetStore $store
     * @param Image $baseImage
     * @param array $formats
     * @param string $coreVersion
     * @return string Path relative to the asset store root.
     */
    protected function createLegacyResampledImageFixture(AssetStore $store, Image $baseImage, $formats, $coreVersion)
    {
        if ($coreVersion == self::CORE_VERSION_3_0_0) {
            $resampledRelativePath = $this->legacyCacheFilenameVersion300($baseImage, $formats);
        } elseif ($coreVersion == self::CORE_VERSION_3_3_0) {
            $resampledRelativePath = $this->legacyCacheFilenameVersion330($baseImage, $formats);
        } else {
            throw new \Exception('Invalid $coreVersion');
        }

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
        Filesystem::makeFolder(dirname($resampledPath ?? ''));
        copy($origPath ?? '', $resampledPath ?? '');

        return $resampledRelativePath;
    }

    /**
     * Replicates the logic of creating >=3.3 style formatted images,
     * based on Image->cacheFilename() and Image->generateFormattedImage().
     * Will create folder structures required for this.
     * This naming placed files with their original name
     * into a subfolder named after the manipulation.
     *
     * Format: <base-folder>/_resampled/<format1>/<format2>/<filename>
     * Example: my-folder/_resampled/ResizedImageWzYwMCwyMDld/ScaleHeightWyIxMDAiXQ/image.jpg
     *
     * @return string Path relative to the asset store root.
     */
    protected function legacyCacheFilenameVersion330($image, $formats)
    {
        $cacheFilename = '';

        if ($image->Parent()->exists()) {
            $cacheFilename = $image->Parent()->Filename;
        }

        $cacheFilename = $this->joinPaths($cacheFilename, '_resampled');

        foreach ($formats as $format => $args) {
            $cacheFilename = $this->joinPaths(
                $cacheFilename,
                $format . Convert::base64url_encode($args)
            );
        }

        $cacheFilename = $this->joinPaths(
            $cacheFilename,
            basename($image->generateFilename() ?? '')
        );

        return $cacheFilename;
    }

    /**
     * Replicates the logic of creating <3.3 style formatted images,
     * based on Image->cacheFilename() and Image->generateFormattedImage().
     * Will create folder structures required for this.
     * This naming used composite filenames with their formats.
     *
     * Format: <base-folder>/_resampled/<format1>-<format2>-<filename>
     * Example: my-folder/_resampled/ResizedImageWzYwMCwyMDld-ScaleHeightWyIxMDAiXQ-image.jpg
     *
     * @return string Path relative to the asset store root.
     */
    protected function legacyCacheFilenameVersion300($image, $formats)
    {
        $cacheFilename = '';

        if ($image->Parent()->exists()) {
            $cacheFilename = $image->Parent()->Filename;
        }

        $cacheFilename = $this->joinPaths($cacheFilename, '_resampled');

        $formatPrefix = '';
        foreach ($formats as $format => $args) {
            $formatPrefix .= $format . Convert::base64url_encode($args) . '-';
        }

        $cacheFilename = $this->joinPaths(
            $cacheFilename,
            $formatPrefix . basename($image->generateFilename() ?? '')
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
        foreach ($formats as $format => $args) {
            $resampled = call_user_func_array([$resampled, $format], $args ?? []);
            $resampleds [] = $resampled;
        }

        $path = TestAssetStore::getLocalPath($resampled, false, true); // relative to store
        $path = preg_replace('#^/#', '', $path ?? ''); // normalise with other store relative paths

        // Not using File->delete() since that actually deletes the original file, not only variant.
        if (!$keep) {
            foreach ($resampleds as $resampled) {
                unlink(TestAssetStore::getLocalPath($resampled) ?? '');
            }
        }

        return $path;
    }

    /**
     * @return string
     */
    protected function joinPaths()
    {
        $paths = [];

        foreach (func_get_args() as $arg) {
            if ($arg !== '') {
                $paths[] = $arg;
            }
        }

        return preg_replace('#/+#', '/', join('/', $paths));
    }
}
