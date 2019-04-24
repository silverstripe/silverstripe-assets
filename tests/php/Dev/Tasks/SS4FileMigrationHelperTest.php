<?php

namespace SilverStripe\Assets\Tests\Dev\Tasks;

use DateTime;
use GuzzleHttp\Psr7\StreamWrapper;
use Intervention\Image\AbstractFont;
use Intervention\Image\AbstractShape;
use Intervention\Image\Gd\Font;
use Intervention\Image\ImageManager;
use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Dev\Tasks\FileMigrationHelper;
use SilverStripe\Assets\FilenameParsing\FileIDHelperResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\FileResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\HashFileIDHelper;
use SilverStripe\Assets\FilenameParsing\NaturalFileIDHelper;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Tests\Dev\Tasks\FileMigrationHelperTest\Extension;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

/**
 * Test a generic SS4.3 to SS4.4 file migration. Also serve as basis for more fancy file migration test
 */
class SS4FileMigrationHelperTest extends SapphireTest
{
    protected $usesTransactions = false;

    protected static $fixture_file = '../../FileTest.yml';

    protected static $required_extensions = array(
        File::class => array(
            Extension::class,
        )
    );

    public function setUp()
    {
        Config::nest(); // additional nesting here necessary
        Config::modify()->merge(File::class, 'migrate_legacy_file', false);

        // Set backend root to /FileMigrationHelperTest/assets
        TestAssetStore::activate('FileMigrationHelperTest');
        $this->defineOriginStrategy();
        parent::setUp();

        // Ensure that each file has a local record file in this new assets base
        /** @var File $file */
        foreach (File::get()->filter('ClassName', File::class) as $file) {
            $filename = $file->getFilename();

            // Create an archive version of the file
            DBDatetime::set_mock_now('2000-01-01 11:00:00');
            $file->setFromString('Archived content of ' . $filename, $filename);
            $file->write();
            $file->publishSingle();
            DBDatetime::clear_mock_now();

            // Publish a version of the file
            $file->setFromString('Published content of ' . $filename, $filename);
            $file->write();
            $file->publishSingle(Versioned::DRAFT, Versioned::LIVE);

            // Create a draft of the file
            $file->setFromString('Draft content of ' . $filename, $filename);
            $file->write();
        }

        // Let's create some variants for our images
        /** @var Image $image */
        foreach (Image::get() as $image) {
            $filename = $image->getFilename();

            // Create an archive version of our image with a thumbnail
            DBDatetime::set_mock_now('2000-01-01 11:00:00');
            $stream = $this->generateImage('Archived', $filename)->stream($image->getExtension());
            $image->setFromStream(StreamWrapper::getResource($stream), $filename);
            $image->write();
            $image->CMSThumbnail();
            $image->publishSingle();
            DBDatetime::clear_mock_now();

            // Publish a live version of our image with a thumbnail
            $stream = $this->generateImage('Published', $filename)->stream($image->getExtension());
            $image->setFromStream(StreamWrapper::getResource($stream), $filename);
            $image->write();
            $image->CMSThumbnail();
            $image->publishSingle();

            // Create a draft version of our images with a thumbnail
            $stream = $this->generateImage('Draft', $filename)->stream($image->getExtension());
            $image->setFromStream(StreamWrapper::getResource($stream), $filename);
            $image->CMSThumbnail();
            $image->write();
        }

        $this->defineDestinationStrategy();
    }

    /**
     * Generate a placeholder image
     * @param string $targetedStage
     * @param string $filename
     * @return \Intervention\Image\Image
     */
    private function generateImage($targetedStage, $filename)
    {
        /** @var ImageManager $imageManager */
        $imageManager = Injector::inst()->create(ImageManager::class);
        return $imageManager
            ->canvas(400, 300, '#142237')
            ->text($targetedStage, 20, 170, function (AbstractFont $font) {
                $font->color('#44C8F5');
            })->text($filename, 20, 185, function (AbstractFont $font) {
                $font->color('#ffffff');
            })->rectangle(20, 200, 100, 202, function (AbstractShape $shape) {
                $shape->background('#DA1052');
            });
    }

    /**
     * Called by set up before creating all the fixture entries. Defines the original startegies for the assets store.
     */
    protected function defineOriginStrategy()
    {
        /** @var FlysystemAssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);

        $hashHelper = new HashFileIDHelper();

        $protected = new FileIDHelperResolutionStrategy();
        $protected->setVersionedStage(Versioned::DRAFT);
        $protected->setDefaultFileIDHelper($hashHelper);
        $protected->setResolutionFileIDHelpers([$hashHelper]);

        $store->setProtectedResolutionStrategy($protected);

        $public = new FileIDHelperResolutionStrategy();
        $public->setVersionedStage(Versioned::LIVE);
        $public->setDefaultFileIDHelper($hashHelper);
        $public->setResolutionFileIDHelpers([$hashHelper]);

        $store->setPublicResolutionStrategy($public);
    }

    /**
     * Called by set up after creating all the fixture entries. Defines the targeted strategies that the
     * FileMigrationHelper should move the files to.
     */
    protected function defineDestinationStrategy()
    {
        /** @var FlysystemAssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);

        $hashHelper = new HashFileIDHelper();
        $naturalHelper = new NaturalFileIDHelper();

        $protected = new FileIDHelperResolutionStrategy();
        $protected->setVersionedStage(Versioned::DRAFT);
        $protected->setDefaultFileIDHelper($hashHelper);
        $protected->setResolutionFileIDHelpers([$hashHelper, $naturalHelper]);

        $store->setProtectedResolutionStrategy($protected);

        $public = new FileIDHelperResolutionStrategy();
        $public->setVersionedStage(Versioned::LIVE);
        $public->setDefaultFileIDHelper($naturalHelper);
        $public->setResolutionFileIDHelpers([$hashHelper, $naturalHelper]);

        $store->setPublicResolutionStrategy($public);
    }

    public function tearDown()
    {
        TestAssetStore::reset();
        parent::tearDown();
        Config::unnest();
    }

    public function testMigration()
    {
        $helper = new FileMigrationHelper();
        $result = $helper->run(TestAssetStore::base_path());

        // Let's look at our draft files
        Versioned::withVersionedMode(function () {
            Versioned::set_stage(Versioned::DRAFT);
            foreach (File::get()->filter('ClassName', File::class) as $file) {
                $this->assertFileAt($file, AssetStore::VISIBILITY_PROTECTED, 'Draft');
            }

            foreach (Image::get() as $image) {
                $this->assertImageAt($image, AssetStore::VISIBILITY_PROTECTED, 'Draft');
            }
        });

        // Let's look at our live files
        Versioned::withVersionedMode(function () {
            Versioned::set_stage(Versioned::LIVE);

            // There's one file with restricted views, the published version of this file will be protected
            $restrictedFileID = $this->idFromFixture(File::class, 'restrictedViewFolder-file4');
            $this->lookAtRestrictedFile($restrictedFileID);

            /** @var File $file */
            foreach (File::get()->filter('ClassName', File::class)->exclude('ID', $restrictedFileID) as $file) {
                $this->assertFileAt($file, AssetStore::VISIBILITY_PUBLIC, 'Published');
            }

            foreach (Image::get() as $image) {
                $this->assertImageAt($image, AssetStore::VISIBILITY_PUBLIC, 'Published');
            }
        });
    }

    /**
     * Test that this restricted file is protected. This test is in its own method so that transition where this
     * scenario can not exist can override it.
     * @param $restrictedFileID
     */
    protected function lookAtRestrictedFile($restrictedFileID)
    {
        $restrictedFile = File::get()->byID($restrictedFileID);
        $this->assertFileAt($restrictedFile, AssetStore::VISIBILITY_PROTECTED, 'Published');
    }

    /**
     * Convenience method to group a bunch of assertions about a regular files
     * @param File $file
     * @param string $visibility Expected visibility of the file
     * @param string $stage Stage that we are testing, will appear in some error messages and in the expected content
     */
    protected function assertFileAt(File $file, $visibility, $stage)
    {
        $ucVisibility = ucfirst($visibility);
        $filename = $file->getFilename();
        $hash = $file->getHash();
        /** @var FlysystemAssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);
        /** @var Filesystem $fs */
        $fs = call_user_func([$store, "get{$ucVisibility}Filesystem"]);
        /** @var FileResolutionStrategy $strategy */
        $strategy = call_user_func([$store, "get{$ucVisibility}ResolutionStrategy"]);

        $this->assertEquals(
            $visibility,
            $store->getVisibility($filename, $hash),
            sprintf('%s version of %s should be %s', $stage, $filename, $visibility)
        );
        $expectedURL = $strategy->buildFileID(new ParsedFileID($filename, $hash));
        $this->assertTrue(
            $fs->has($expectedURL),
            sprintf('%s version of %s should be on %s store under %s', $stage, $filename, $visibility, $expectedURL)
        );
        $this->assertEquals(
            sprintf('%s content of %s', $stage, $filename),
            $fs->read($expectedURL),
            sprintf('%s version of %s on %s store has wrong content', $stage, $filename, $visibility)
        );
    }

    /**
     * Convenience method to group a bunch of assertions about an image
     * @param File $file
     * @param string $visibility Expected visibility of the file
     * @param string $stage Stage that we are testing, will appear in some error messages
     */
    protected function assertImageAt(Image $file, $visibility, $stage)
    {
        $ucVisibility = ucfirst($visibility);
        $filename = $file->getFilename();
        $hash = $file->getHash();
        $pfid = new ParsedFileID($filename, $hash);

        /** @var FlysystemAssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);

        /** @var Filesystem $fs */
        $fs = call_user_func([$store, "get{$ucVisibility}Filesystem"]);
        /** @var FileResolutionStrategy $strategy */
        $strategy = call_user_func([$store, "get{$ucVisibility}ResolutionStrategy"]);

        $this->assertEquals(
            $visibility,
            $store->getVisibility($filename, $hash),
            sprintf('%s version of %s should be %s', $stage, $filename, $visibility)
        );

        $expectedURL = $strategy->buildFileID($pfid);
        $this->assertTrue(
            $fs->has($expectedURL),
            sprintf('%s version of %s should be on %s store under %s', $stage, $filename, $visibility, $expectedURL)
        );
        $expectedURL = $strategy->buildFileID($pfid->setVariant('FillWzEwMCwxMDBd'));
        $this->assertTrue(
            $fs->has($expectedURL),
            sprintf('%s thumbnail of %s should be on %s store under %s', $stage, $filename, $visibility, $expectedURL)
        );
    }
}
