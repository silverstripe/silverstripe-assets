<?php

namespace SilverStripe\Assets\Tests;

use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Assets\Dev\Tasks\TagsToShortcodeHelper;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;

class TagsToShortcodeHelperTest extends SapphireTest
{
    protected static $fixture_file = 'TagsToShortcodeHelperTest.yml';

    /**
     * get the BASE_PATH for this test
     *
     * @return string
     */
    protected function getBasePath()
    {
        // Note that the actual filesystem base is the 'assets' subdirectory within this
        return ASSETS_PATH . '/TagsToShortcodeHelperTest';
    }

    public function setUp()
    {
        parent::setUp();
        $this->setupAssetStore();
    }

    private function setupAssetStore()
    {
        // Set backend root to /TagsToShortcodeHelperTest/assets
        TestAssetStore::activate('TagsToShortcodeHelperTest/assets');

        // Ensure that each file has a local record file in this new assets base
        $from = __DIR__ . '/ImageTest/test-image-low-quality.jpg';
        $destinations = [];

        foreach (File::get()->exclude('ClassName', Folder::class) as $file) {
            $destinations []= $file->Hash . DIRECTORY_SEPARATOR . $file->generateFilename();
        }

        // Create resampled file manually
        $destinations []= '6ee53356ec/myimage__ResizedImageWzY0LDY0XQ.jpg';

        // Copy the files
        foreach ($destinations as $destination) {
            $destination = TestAssetStore::base_path() . DIRECTORY_SEPARATOR . $destination;
            Filesystem::makeFolder(dirname($destination));
            copy($from, $destination);
        }
    }

    public function tearDown()
    {
        TestAssetStore::reset();
        Filesystem::removeFolder($this->getBasePath());
        parent::tearDown();
    }

    public function testRewrite()
    {
        $tagsToShortcodeHelper = new TagsToShortcodeHelper();
        $tagsToShortcodeHelper->run();

        /** @var SiteTree $newPage */
        $newPage = SiteTree::get()->first();

        self::assertEquals(<<<HTML
<p>this needs to be rewritten: <a href="[file_link id=2]">link to file</a></p> <p>and so does this: [image src="/assets/6ee53356ec/myimage.jpg" id=1]</p> <p>also this: [image src="/assets/6ee53356ec/myimage.jpg" id=1]</p> <p>and this: [image src="/assets/6ee53356ec/myimage__ResizedImageWzY0LDY0XQ.jpg" width="64" height="64" id=1]</p>
<p>but not this: <a href="[file_link id=2]" class="ss-broken">link to file</a></p> <p>and neither this: <a href="/assets/invalid_document.pdf">link to file</a></p>

HTML
            , $newPage->Content, 'Content is not correct');
    }
}
