<?php

namespace SilverStripe\Assets\Tests;

use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Assets\Dev\Tasks\TagsToShortcodeHelper;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Environment;
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
        $destinations []= 'myimage__ResizedImageWzY0LDY0XQ.jpg';
        $destinations []= 'ce65335ee6/hash-path__ResizedImageWzY0LDY0XQ.jpg';

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
        $newPage = $this->objFromFixture(SiteTree::class, 'page1');

        $documentID = $this->idFromFixture(File::class, 'document');

        self::assertEquals(<<<HTML
<p>file link <a href="[file_link id={$documentID}]">link to file</a></p> <p>natural path links
  [image src="/assets/6ee53356ec/myimage.jpg" id=1][image src="/assets/6ee53356ec/myimage.jpg" id=1]</p> <p>variant path [image src="/assets/6ee53356ec/myimage__ResizedImageWzY0LDY0XQ.jpg" width="64" height="64" id=1]</p> <p>link to hash path [image src="/assets/6ee53356ec/myimage.jpg" id=1]</p> <p>link to external file <a href="https://www.google.com/images/branding/googlelogo/2x/googlelogo_color_272x92dp.png">link to external file</a></p>
<p>ignored links
  <a href="[file_link id={$documentID}]" class="ss-broken">link to file</a>
  <a href="/assets/invalid_document.pdf">link to file</a>
</p>
<p broken="" html="" src=""> </p><p>image tag with closing bracket [image src="/assets/6ee53356ec/myimage.jpg" id=1]</p> <p>image tag inside a link <a href="[file_link id={$documentID}]">link to file with image [image src="/assets/6ee53356ec/myimage.jpg" id=1]</a></p> <p>attributes with single quotes [image src="/assets/6ee53356ec/myimage.jpg" id=1]</p> <p>attributes without quotes [image src="/assets/6ee53356ec/myimage.jpg" id=1]</p> <p>bad casing for tags or attributes [image src="/assets/6ee53356ec/myimage.jpg" id=1]</p> <p>image that should not be updated <img src="non_existant.jpg"></p>

HTML
            , $newPage->Content, 'Content is not correct');
    }


    /**
     * @dataProvider newContentConvertDataProvider
     * @dataProvider newContentNoChangeDataProvider
     * @dataProvider newContentNoDataDegradationDataProvider
     * @dataProvider newContentEasyFixDataProvider
     * @param string $input
     * @param string|false $ouput If false, assume the input should be unchanged
     */
    public function testNewContent($input, $output=false)
    {
        $tagsToShortcodeHelper = new TagsToShortcodeHelper();
        $this->assertEquals($output ?: $input, $tagsToShortcodeHelper->getNewContent($input));
    }

    /**
     * List of HTML string that should be converted to short code
     */
    public function newContentConvertDataProvider()
    {
        $image1ID = 1;
        $documentID = 3;

        return [
            'link to file with starting slash' => [
                '<a href="/assets/document.pdf">link to file</a>',
                sprintf('<a href="[file_link,id=%d]">link to file</a>', $documentID)
            ],
            'link to file in paragraph' => [
                '<p>file link <a href="/assets/document.pdf">link to file</a></p>',
                '<p>file link <a href="[file_link,id=3]">link to file</a></p>'
            ],
            'link to file without starting slash' => [
                '<a href="assets/document.pdf">link to file</a>',
                '<a href="[file_link,id=3]">link to file</a>'
            ],
            'link with other attributes' => [
                '<a href="assets/document.pdf" lang="fr" xml:lang="fr">link to file</a>',
                '<a href="[file_link,id=3]" lang="fr" xml:lang="fr">link to file</a>'
            ],
            'link with other attributes before href' => [
                '<a lang="fr" xml:lang="fr" href="assets/document.pdf">link to file</a>',
                '<a lang="fr" xml:lang="fr" href="[file_link,id=3]">link to file</a>'
            ],

            'simple image' => [
                '<img src="assets/myimage.jpg">',
                sprintf('[image src="/assets/6ee53356ec/myimage.jpg" id="%d"]', $image1ID)
            ],
            'image variant' => [
                '<img src="assets/_resampled/ResizedImageWzY0LDY0XQ/myimage.jpg">',
                '[image src="/assets/6ee53356ec/myimage.jpg" id="1"]'],
            'image variant with size' => [
                '<img src="assets/_resampled/ResizedImageWzY0LDY0XQ/myimage.jpg" width="100" height="133">',
                '[image src="/assets/6ee53356ec/myimage.jpg" width="100" height="133" id="1"]'],
            'xhtml image' => [
                '<img src="assets/myimage.jpg" />',
                sprintf('[image src="/assets/6ee53356ec/myimage.jpg" id="%d"]', $image1ID)
            ],
        ];
    }

    /**
     * List of HTML string that should remain unchanged
     */
    public function newContentNoChangeDataProvider()
    {
        return [
            'external anchor' => ['<a href="https://silverstripe.org/assets/document.pdf">External link</a>'],
            'link already using short code' => ['<a href="[file_link,id=2]">link to file</a>'],
            'link already using short code without comma' => ['<a href="[file_link id=2]">link to file</a>'],
            'link already using short code with id quote' => ['<a href="[file_link,id="2"]">link to file</a>'],
        ];
    }

    /**
     * List of HTMl string that would be converted in an ideal world, but are too unusual to analyse with regex. We're
     * just testing that the content is not degraded.
     */
    public function newContentNoDataDegradationDataProvider()
    {
        return [
            'absolute link to file' => [
                sprintf('<a href="%s/assets/document.pdf">link to file</a>', Environment::getEnv('SS_BASE_URL'))
            ],
            'link tag broken over several line' => ["<a \nhref=\"assets/document.pdf\" \n>\nlink to file \r\n \t</a>"],
            
        ];
    }

    /**
     * List of HTMl string that would be converted in an ideal world, but are too unusual to analyse with regex. We're
     * just testing that the content is not degraded.
     */
    public function newContentEasyFixDataProvider()
    {
        return [

            // Just make the regex case insensitive
            'link with uppercase tag' => [
                '<A href="assets/document.pdf">link to file</A>',
                '<A href="[file_link,id=3]">link to file</A>'
            ],
            'link with uppercase href' => [
                '<a HREF="assets/document.pdf">link to file</a>',
                '<a href="[file_link,id=3]">link to file</a>'
            ],

            // Check for single quotes as well 
            'link with single quotes' => [
                '<a href=\'assets/document.pdf\'>link to file</a>',
                '<a href="[file_link,id=3]">link to file</a>'
            ],
        ];
    }



}
