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
<p>file link <a href="[file_link id=2]">link to file</a></p> <p>natural path links
  [image src="/assets/6ee53356ec/myimage.jpg" id=1][image src="/assets/6ee53356ec/myimage.jpg" id=1]</p> <p>variant path [image src="/assets/6ee53356ec/myimage__ResizedImageWzY0LDY0XQ.jpg" width="64" height="64" id=1]</p> <p>link to hash path [image src="/assets/6ee53356ec/myimage.jpg" id=1]</p> <p>link to external file <a href="https://www.google.com/images/branding/googlelogo/2x/googlelogo_color_272x92dp.png">link to external file</a></p>
<p>ignored links
  <a href="[file_link id=2]" class="ss-broken">link to file</a>
  <a href="/assets/invalid_document.pdf">link to file</a>
</p>
<p broken="" html="" src=""> </p><p>image tag with closing bracket [image src="/assets/6ee53356ec/myimage.jpg" id=1]</p> <p>image tag inside a link <a href="[file_link id=2]">link to file with image [image src="/assets/6ee53356ec/myimage.jpg" id=1]</a></p> <p>attributes with single quotes [image src="/assets/6ee53356ec/myimage.jpg" id=1]</p> <p>attributes without quotes [image src="/assets/6ee53356ec/myimage.jpg" id=1]</p> <p>bad casing for tags or attributes [image src="/assets/6ee53356ec/myimage.jpg" id=1]</p> <p>image that should not be updated <img src="non_existant.jpg"></p>

HTML
            , $newPage->Content, 'Content is not correct');
    }
}
