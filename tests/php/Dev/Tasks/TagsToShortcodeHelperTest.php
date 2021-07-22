<?php

namespace SilverStripe\Assets\Tests\Dev\Tasks;

use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Assets\Dev\Tasks\TagsToShortcodeHelper;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Tests\Dev\Tasks\Shortcode\HtmlObject;
use SilverStripe\Assets\Tests\Dev\Tasks\Shortcode\NoStage;
use SilverStripe\Assets\Tests\Dev\Tasks\Shortcode\PseudoPage;
use SilverStripe\Assets\Tests\Dev\Tasks\Shortcode\SubHtmlObject;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBHTMLVarchar;
use SilverStripe\Versioned\Versioned;

class TagsToShortcodeHelperTest extends SapphireTest
{
    protected static $fixture_file = 'TagsToShortcodeHelperTest.yml';

    protected $usesTransactions = false;

    protected static $extra_dataobjects = [
        HtmlObject::class,
        SubHtmlObject::class,
        NoStage::class,
        PseudoPage::class,
    ];

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

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupAssetStore();
    }

    private function setupAssetStore()
    {
        // Set backend root to /TagsToShortcodeHelperTest/assets
        TestAssetStore::activate('TagsToShortcodeHelperTest/assets');

        /** @var File $file */
        foreach (File::get()->filter('ClassName', File::class) as $file) {
            $file->setFromString($file->getFilename(), $file->getFilename(), $file->getHash());
        }

        $from = __DIR__ . '/../../ImageTest/test-image-low-quality.jpg';
        foreach (Image::get() as $file) {
            $file->setFromLocalFile($from, $file->getFilename(), $file->getHash());
            $file->setFromLocalFile($from, $file->getFilename(), $file->getHash(), 'ResizedImageWzY0LDY0XQ');
        }
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        Filesystem::removeFolder($this->getBasePath());
        parent::tearDown();
    }

    public function testRewrite()
    {
        $tagsToShortcodeHelper = new TagsToShortcodeHelper();
        $tagsToShortcodeHelper->run();

        /** @var PseudoPage $newPage */
        $newPage = $this->objFromFixture(PseudoPage::class, 'page1');
        $documentID = $this->idFromFixture(File::class, 'document');
        $imageID = $this->idFromFixture(Image::class, 'image1');

        $this->assertContains(
            sprintf('<p id="filelink">file link <a href="[file_link,id=%d]">link to file</a></p>', $documentID),
            $newPage->Content
        );

        $this->assertContains(
            sprintf('<p id="image">[image src="/assets/33be1b95cb/myimage.jpg" id="%d"]</p>', $imageID),
            $newPage->Content
        );

        $this->assertContains(
            sprintf(
                '<p id="variant">[image src="/assets/33be1b95cb/myimage.jpg" width="64" height="64" id="%d"]</p>',
                $imageID
            ),
            $newPage->Content
        );
    }

    public function testPublishedFileRewrite()
    {
        $document = $this->objFromFixture(File::class, 'document');
        $image = $this->objFromFixture(Image::class, 'image1');

        $document->publishSingle();
        $image->publishSingle();

        $this->testRewrite();
    }

    public function testLivePageRewrite()
    {
        /** @var PseudoPage $newPage */
        $newPage = $this->objFromFixture(PseudoPage::class, 'page1');
        $newPage->publishSingle();

        $newPage->Content = '<p>Draft content</p>';
        $newPage->write();

        $tagsToShortcodeHelper = new TagsToShortcodeHelper();
        $tagsToShortcodeHelper->run();

        /** @var PseudoPage $newPage */
        $newPageID = $newPage->ID;
        $newPage = Versioned::withVersionedMode(function () use ($newPageID) {
            Versioned::set_stage(Versioned::LIVE);
            return PseudoPage::get()->byID($newPageID);
        });


        $documentID = $this->idFromFixture(File::class, 'document');
        $imageID = $this->idFromFixture(Image::class, 'image1');

        $this->assertContains(
            sprintf('<p id="filelink">file link <a href="[file_link,id=%d]">link to file</a></p>', $documentID),
            $newPage->Content
        );

        $this->assertContains(
            sprintf('<p id="image">[image src="/assets/33be1b95cb/myimage.jpg" id="%d"]</p>', $imageID),
            $newPage->Content
        );

        $this->assertContains(
            sprintf(
                '<p id="variant">[image src="/assets/33be1b95cb/myimage.jpg" width="64" height="64" id="%d"]</p>',
                $imageID
            ),
            $newPage->Content
        );
    }

    public function testRewriteRegularObject()
    {
        $tagsToShortcodeHelper = new TagsToShortcodeHelper();
        $tagsToShortcodeHelper->run();

        $htmlObject = $this->objFromFixture(HtmlObject::class, 'htmlObject');

        $documentID = $this->idFromFixture(File::class, 'document');

        $this->assertEquals(
            sprintf('<a href="[file_link,id=%d]">Content Field</a>', $documentID),
            $htmlObject->Content
        );

        $this->assertEquals(
            sprintf('<a href="[file_link,id=%d]">link to file</a>', $documentID),
            $htmlObject->HtmlLine
        );

        $this->assertEquals(
            '<a href="/assets/document.pdf">This wont be converted</a>',
            $htmlObject->HtmlLineNoShortCode
        );
    }

    public function testRewriteSubclassObject()
    {
        $tagsToShortcodeHelper = new TagsToShortcodeHelper();
        $tagsToShortcodeHelper->run();

        $subHtmlObject = $this->objFromFixture(SubHtmlObject::class, 'subHtmlObject');

        $documentID = $this->idFromFixture(File::class, 'document');
        $image1ID = $this->idFromFixture(Image::class, 'image1');

        $this->assertEquals(
            sprintf('[image src="/assets/33be1b95cb/myimage.jpg" alt="SubHtmlObject Table" id="%d"]', $image1ID),
            $subHtmlObject->HtmlContent
        );

        $this->assertEquals(
            sprintf('<a href="[file_link,id=%d]">Content Field</a>', $documentID),
            $subHtmlObject->Content
        );

        $this->assertEquals(
            sprintf('<a href="[file_link,id=%d]">HtmlObject Table</a>', $documentID),
            $subHtmlObject->HtmlLine
        );
    }

    public function testStagelessVersionedObject()
    {
        // This is just here to make sure that the logic for converting live content doesn't fall on its face when
        // encountering a versioned object that does not support stages.

        $tagsToShortcodeHelper = new TagsToShortcodeHelper();
        $tagsToShortcodeHelper->run();

        $stageless = $this->objFromFixture(NoStage::class, 'stageless');

        $documentID = $this->idFromFixture(File::class, 'document');

        $this->assertEquals(
            sprintf('<a href="[file_link,id=%d]">Stageless Versioned Object</a>', $documentID),
            $stageless->Content
        );
    }
    /**
     * @dataProvider newContentConvertDataProvider
     * @dataProvider newContentNoChangeDataProvider
     * @dataProvider newContentNoDataDegradationDataProvider
     * @dataProvider newContentEasyFixDataProvider
     * @dataProvider newContentUnderscoreDataProvider
     * @param string $input
     * @param string|false $ouput If false, assume the input should be unchanged
     */
    public function testNewContent($input, $output = false)
    {
        $tagsToShortcodeHelper = new TagsToShortcodeHelper();
        $actual = $tagsToShortcodeHelper->getNewContent($input);
        $this->assertEquals($output ?: $input, $actual);
    }

    /**
     * List of HTML string that should be converted to short code
     */
    public function newContentConvertDataProvider()
    {
        $image1ID = 2;
        $documentID = 5;
        $underscoreFile = 4;

        return [
            'link to file with starting slash' => [
                '<a href="/assets/document.pdf">link to file</a>',
                sprintf('<a href="[file_link,id=%d]">link to file</a>', $documentID)
            ],
            'link to file in paragraph' => [
                '<p>file link <a href="/assets/document.pdf">link to file</a></p>',
                sprintf('<p>file link <a href="[file_link,id=%d]">link to file</a></p>', $documentID)
            ],
            'link to file without starting slash' => [
                '<a href="assets/document.pdf">link to file</a>',
                sprintf('<a href="[file_link,id=%d]">link to file</a>', $documentID)
            ],
            'link with common attributes' => [
                '<a title="a test" href="assets/document.pdf" target="_blank">link to file</a>',
                sprintf('<a title="a test" href="[file_link,id=%d]" target="_blank">link to file</a>', $documentID)
            ],
            'link with other attributes' => [
                '<a href="assets/document.pdf" lang="fr" xml:lang="fr">link to file</a>',
                sprintf('<a href="[file_link,id=%d]" lang="fr" xml:lang="fr">link to file</a>', $documentID)
            ],
            'link with other attributes before href' => [
                '<a lang="fr" xml:lang="fr" href="assets/document.pdf">link to file</a>',
                sprintf('<a lang="fr" xml:lang="fr" href="[file_link,id=%d]">link to file</a>', $documentID)
            ],
            'link with empty attributes before href' => [
                '<a href="assets/document.pdf" title="">link to file</a>',
                sprintf('<a href="[file_link,id=%d]" title="">link to file</a>', $documentID)
            ],
            'link to image' => [
                '<a href="assets/myimage.jpg">link to file</a>',
                sprintf('<a href="[file_link,id=%d]">link to file</a>', $image1ID)
            ],
            'link to image variant' => [
                '<a href="assets/_resampled/ResizedImageWzY0LDY0XQ/myimage.jpg">link to file</a>',
                sprintf('<a href="[file_link,id=%d]">link to file</a>', $image1ID)
            ],
             'link to image SS3.0 variant' => [
                '<a href="assets/_resampled/ResizedImageWzY0LDY0XQ-myimage.jpg">link to file</a>',
                sprintf('<a href="[file_link,id=%d]">link to file</a>', $image1ID)
             ],
            'link to hash url' => [
                '<a href="assets/0ba2141b89/document.pdf">link to file</a>',
                sprintf('<a href="[file_link,id=%d]">link to file</a>', $documentID)
            ],
            'link without closing tag' => [
                '<a href="assets/document.pdf">Link to file',
                sprintf('<a href="[file_link,id=%d]">Link to file', $documentID)
            ],


            'simple image' => [
                '<img src="assets/myimage.jpg">',
                sprintf('[image src="/assets/33be1b95cb/myimage.jpg" id="%d"]', $image1ID)
            ],
            'image with common attributes' => [
                '<img src="assets/myimage.jpg" alt="My Image" title="My image title">',
                sprintf('[image src="/assets/33be1b95cb/myimage.jpg" alt="My Image" title="My image title" id="%d"]', $image1ID)
            ],
            'image with uncommon attributes' => [
                '<img src="assets/myimage.jpg" xml:lang="fr" lang="fr">',
                sprintf('[image src="/assets/33be1b95cb/myimage.jpg" xml:lang="fr" lang="fr" id="%d"]', $image1ID)
            ],
            'image variant' => [
                '<img src="assets/_resampled/ResizedImageWzY0LDY0XQ/myimage.jpg">',
                sprintf('[image src="/assets/33be1b95cb/myimage.jpg" id="%d"]', $image1ID)],
            'image SS3.0 variant' => [
                '<img src="assets/_resampled/ResizedImageWzY0LDY0XQ-myimage.jpg">',
                sprintf('[image src="/assets/33be1b95cb/myimage.jpg" id="%d"]', $image1ID)],
            'image variant with size' => [
                '<img src="assets/_resampled/ResizedImageWzY0LDY0XQ/myimage.jpg" width="100" height="133">',
                sprintf('[image src="/assets/33be1b95cb/myimage.jpg" width="100" height="133" id="%d"]', $image1ID)],
            'image variant that has not been generated yet' => [
                '<img src="assets/_resampled/ResizedImageWzY0LDY0XQ/myimage.jpg" width="200" height="266">',
                sprintf('[image src="/assets/33be1b95cb/myimage.jpg" width="200" height="266" id="%d"]', $image1ID)],
            'xhtml image' => [
                '<img src="assets/myimage.jpg" />',
                sprintf('[image src="/assets/33be1b95cb/myimage.jpg" id="%d"]', $image1ID)
            ],
            'empty attribute image' => [
                '<img src="assets/myimage.jpg" title="">',
                sprintf('[image src="/assets/33be1b95cb/myimage.jpg" title="" id="%d"]', $image1ID)
            ],
            'image caption' => [
                '<div class="captionImage leftAlone" style="width: 100px;"><img class="leftAlone" src="assets/myimage.jpg" alt="sam" width="100" height="133"><p class="caption leftAlone">My caption</p></div>',
                sprintf('<div class="captionImage leftAlone" style="width: 100px;">[image class="leftAlone" src="/assets/33be1b95cb/myimage.jpg" alt="sam" width="100" height="133" id="%d"]<p class="caption leftAlone">My caption</p></div>', $image1ID)
            ],
            'same image twice' => [
                str_repeat('<img src="assets/myimage.jpg">', 2),
                str_repeat(sprintf('[image src="/assets/33be1b95cb/myimage.jpg" id="%d"]', $image1ID), 2)
            ],

            'image inside file link' => [
                '<a href="assets/document.pdf"><img src="assets/myimage.jpg"></a>',
                sprintf(
                    '<a href="[file_link,id=%d]">[image src="/assets/33be1b95cb/myimage.jpg" id="%d"]</a>',
                    $documentID,
                    $image1ID
                )
            ],

            'link to file with underscore' => [
                '<a href="assets/decade1980/under_score.jpg" title="">link to file</a>',
                sprintf('<a href="[file_link,id=%d]" title="">link to file</a>', $underscoreFile)
            ],
            'image with underscore' => [
                '<img src="assets/decade1980/under_score.jpg">',
                sprintf('[image src="/assets/decade1980/33be1b95cb/under_score.jpg" id="%d"]', $underscoreFile)
            ],
            'image inside a tag without href' => [
                '<p><a><img src="assets/_resampled/ResizedImageWzY0LDY0XQ/myimage.jpg" class="leftAlone" title="" alt="" width="600" height="400"></a></p>',
                sprintf(
                    '<p><a>[image src="/assets/33be1b95cb/myimage.jpg" class="leftAlone" title="" alt="" width="600" height="400" id="%d"]</a></p>',
                    $image1ID
                )
            ],
        ];
    }

    /**
     * List of HTML string that should be converted to short code
     */
    public function newContentUnderscoreDataProvider()
    {
        $image1ID = 2;
        $documentID = 5;
        $underscoreFile = 3;
        $trippleUnderscore = 4;

        return [
            'link to file with underscore' => [
                '<a href="assets/decade80/under_score.jpg" title="">link to file</a>',
                sprintf('<a href="[file_link,id=%d]" title="">link to file</a>', $underscoreFile)
            ],
            'image with underscore' => [
                '<img src="assets/decade80/under_score.jpg">',
                sprintf('[image src="/assets/decade80/33be1b95cb/under_score.jpg" id="%d"]', $underscoreFile)
            ],
            'link to file with double underscore' => [
                '<a href="assets/decade80/under__score.jpg" title="">link to file</a>',
                sprintf('<a href="[file_link,id=%d]" title="">link to file</a>', $underscoreFile)
            ],
            'image with double underscore' => [
                '<img src="assets/decade80/under__score.jpg">',
                sprintf('[image src="/assets/decade80/33be1b95cb/under_score.jpg" id="%d"]', $underscoreFile)
            ]
        ];
    }

    /**
     * List of HTML string that should remain unchanged
     */
    public function newContentNoChangeDataProvider()
    {
        $documentID = 5;

        return [
            'external anchor' => ['<a href="https://silverstripe.org/assets/document.pdf">External link</a>'],
            'link already using short code' => ['<a href="[file_link,id=2]">link to file</a>'],
            'link already using short code without comma' => ['<a href="[file_link id=2]">link to file</a>'],
            'link already using short code with id quote' => ['<a href="[file_link,id="2"]">link to file</a>'],
            'link to a site tree object' => [sprintf('<a href="[sitetree_link,id=%d]">Link to site tree</a>', $documentID)],
            'link with mailto href' => ['<a href="mailto:test@example.com">test@example.com</a>'],
            'link with comment in tag' => ['<a href="<!-- because why not -->">test@example.com</a>'],
            'link without closing >' => ['<a href="assets/document.pdf"'],
            'URL in content' => ['assets/document.pdf'],

            'external image' => ['<img src="https://silverstripe.com/assets/myimage.jpg">'],
            'image already using shortcode' => ['[image src="/assets/33be1b95cb/myimage.jpg" id="3"]']
        ];
    }

    /**
     * List of HTMl string that would be converted in an ideal world, but are too unusual to analyse with regex. We're
     * just testing that the content is not degraded.
     */
    public function newContentNoDataDegradationDataProvider()
    {
        $image1ID = 2;

        return [
            'link tag broken over several line' => ["<a \nhref=\"assets/document.pdf\" \n>\nlink to file \r\n \t</a>"],
            'link to valid file with hash anchor' => ['<a href="/assets/document.pdf#boom">link to file</a>'],
            'link to valid file with Get param' => ['<a href="/assets/document.pdf?boom=pow">link to file</a>'],
            'image with custom id' => [
                '<img src="assets/myimage.jpg" id="custom-id">',
                sprintf('[image src="/assets/33be1b95cb/myimage.jpg" id="%d"]', $image1ID)
            ],
        ];
    }

    /**
     * List of HTMl string that would be converted in an ideal world, but are too unusual to analyse with regex. We're
     * just testing that the content is not degraded.
     */
    public function newContentEasyFixDataProvider()
    {
        $image1ID = 2;
        $documentID = 5;

        return [
            // Just make the regex case insensitive
            'link with uppercase tag' => [
                '<A href="assets/document.pdf">link to file</A>',
                sprintf('<A href="[file_link,id=%d]">link to file</A>', $documentID)
            ],
            'link with uppercase href' => [
                '<a HREF="assets/document.pdf">link to file</a>',
                sprintf('<a href="[file_link,id=%d]">link to file</a>', $documentID)
            ],
            'image with weird case' => [
                '<ImG src="assets/myimage.jpg">',
                sprintf('[image src="/assets/33be1b95cb/myimage.jpg" id="%d"]', $image1ID)
            ],

            // Check for single quotes as well
            'link with single quotes' => [
                '<a href=\'assets/document.pdf\'>link to file</a>',
                sprintf('<a href="[file_link,id=%d]">link to file</a>', $documentID)
            ],
            'image with single quotes' => [
                "<img src='assets/myimage.jpg'>",
                sprintf('[image src="/assets/33be1b95cb/myimage.jpg" id="%d"]', $image1ID)
            ],
        ];
    }

    /**
     * Illustrate how newContent cannot resolve files that have been renamed because of conflictual name.
     * `decade80/under___score.jpg` should have been renamed to `decade80/under_score.jpg`, however that file already
     * exists, so it got renamed to `decade80/under_score-v2.jpg` instead. However `TagsToShortcodeHelper` can not
     * understand that.
     */
    public function testAmbigiousCleanedName()
    {
        $underscoreFile = 3;
        $trippleUnderscore = 4;

        $tagsToShortcodeHelper = new TagsToShortcodeHelper();

        $actual = $tagsToShortcodeHelper->getNewContent(
            '<a href="assets/decade80/under___score.jpg" title="">link to file</a>'
        );
        $this->assertEquals(
            sprintf('<a href="[file_link,id=%d]" title="">link to file</a>', $underscoreFile),
            $actual
        );
//        In perfect world, this assertion would be true
//        $this->assertEquals(
//            sprintf('<a href="[file_link,id=%d]" title="">link to file</a>', $trippleUnderscore),
//            $actual
//        );

        $actual = $tagsToShortcodeHelper->getNewContent(
            '<img src="assets/decade80/under___score.jpg">'
        );
        $this->assertEquals(
            sprintf('[image src="/assets/decade80/33be1b95cb/under_score.jpg" id="%d"]', $underscoreFile),
            $actual
        );
//        In perfect world, this assertion would be true
//        $this->assertEquals(
//            sprintf('[image src="/assets/decade80/33be1b95cb/under_score-v2.jpg" id="%d"]', $trippleUnderscore),
//            $actual
//        );
    }
}
