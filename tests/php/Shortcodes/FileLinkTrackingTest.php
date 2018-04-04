<?php

namespace SilverStripe\Assets\Tests\Shortcodes;

use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Tests\Shortcodes\FileBrokenLinksTest\EditableObject;
use SilverStripe\Core\Convert;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;

class FileLinkTrackingTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        EditableObject::class,
    ];

    protected static $fixture_file = "FileLinkTrackingTest.yml";

    public function setUp()
    {
        parent::setUp();

        Versioned::set_stage(Versioned::DRAFT);

        TestAssetStore::activate('FileLinkTrackingTest');
        $this->logInWithPermission('ADMIN');

        // Write file contents
        /** @var File[] $files */
        $files = File::get()->exclude('ClassName', Folder::class);
        foreach ($files as $file) {
            // Mock content for files
            $content = $file->Filename . ' ' . str_repeat('x', 1000000);
            $file->setFromString($content, $file->Filename);
            $file->write();
            $file->publishRecursive();
        }

        // Since we can't hard-code IDs, manually inject image tracking shortcode
        /** @var EditableObject $page */
        $page = $this->objFromFixture(EditableObject::class, 'page1');
        /** @var Image $image1 */
        $image1 = $this->objFromFixture(Image::class, 'image1');
        $page->Content = sprintf(
            '<p>[image src="%s" id="%d"]</p>',
            Convert::raw2xml($image1->getURL()),
            $image1->ID
        );

        /** @var File $file1 */
        $file1 = $this->objFromFixture(File::class, 'file1');
        $page->Another = sprintf(
            '<p><a href="[file_link,id=%d]">Working Link</a></p>',
            $file1->ID
        );
        $page->write();
    }

    public function tearDown()
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    /**
     * Test uses global state through Versioned::set_stage() since
     * the shortcode parser doesn't pass along the underlying DataObject
     * context, hence we can't call getSourceQueryParams().
     */
    public function testFileRenameUpdatesDraftAndPublishedPages()
    {
        /** @var EditableObject $page */
        $page = $this->objFromFixture(EditableObject::class, 'page1');
        $page->publishRecursive();

        // Live and stage pages both have link to public file
        $this->assertContains(
            '<img src="/assets/FileLinkTrackingTest/5a5ee24e44/testscript-test-file.jpg"',
            $page->dbObject('Content')->forTemplate()
        );
        $this->assertContains(
            '<p><a href="/assets/FileLinkTrackingTest/44781db6b1/testscript-test-file.txt">Working Link</a></p>',
            $page->dbObject('Another')->forTemplate()
        );

        Versioned::withVersionedMode(function () use ($page) {
            Versioned::set_stage(Versioned::LIVE);
            /** @var EditableObject $pageLive */
            $pageLive = EditableObject::get()->byID($page->ID);
            $this->assertContains(
                '<img src="/assets/FileLinkTrackingTest/5a5ee24e44/testscript-test-file.jpg"',
                $pageLive->dbObject('Content')->forTemplate()
            );
            $this->assertContains(
                '<p><a href="/assets/FileLinkTrackingTest/44781db6b1/testscript-test-file.txt">Working Link</a></p>',
                $pageLive->dbObject('Another')->forTemplate()
            );
        });

        // Ensure two links for this page
        $this->assertListEquals(
            [
                ['Name' => 'testscript-test-file.jpg'],
                ['Name' => 'testscript-test-file.txt'],
            ],
            $page->FileTracking()
        );

        // Rename image (note: only [image ] shortcodes are affected by rename so ignore [file_link ])
        /** @var Image $image1 */
        $image1 = $this->objFromFixture(Image::class, 'image1');
        $image1->Name = 'renamed-test-file.jpg';
        $image1->write();

        // Staged record now points to secure URL of renamed file, live record remains unchanged
        // Note that the "secure" url doesn't have the "FileLinkTrackingTest" component because
        // the mocked test location disappears for secure files.
        $page = EditableObject::get()->byID($page->ID);
        $this->assertContains(
            '<img src="/assets/5a5ee24e44/renamed-test-file.jpg"',
            $page->dbObject('Content')->forTemplate()
        );
        Versioned::withVersionedMode(function () use ($page) {
            Versioned::set_stage(Versioned::LIVE);
            $pageLive = EditableObject::get()->byID($page->ID);
            $this->assertContains(
                '<img src="/assets/FileLinkTrackingTest/5a5ee24e44/testscript-test-file.jpg"',
                $pageLive->dbObject('Content')->forTemplate()
            );
        });

        // Publishing the file should result in a direct public link (indicated by "FileLinkTrackingTest")
        // Although the old live page will still point to the old record.
        $image1->publishRecursive();
        $page = EditableObject::get()->byID($page->ID);
        $this->assertContains(
            '<img src="/assets/FileLinkTrackingTest/5a5ee24e44/renamed-test-file.jpg"',
            $page->dbObject('Content')->forTemplate()
        );
        Versioned::withVersionedMode(function () use ($page) {
            Versioned::set_stage(Versioned::LIVE);
            $pageLive = EditableObject::get()->byID($page->ID);
            $this->assertContains(
                '<img src="/assets/FileLinkTrackingTest/5a5ee24e44/renamed-test-file.jpg"',
                $pageLive->dbObject('Content')->forTemplate()
            );
        });

        // Publishing the page after publishing the asset should retain linking
        $page->publishRecursive();
        $page = EditableObject::get()->byID($page->ID);
        $this->assertContains(
            '<img src="/assets/FileLinkTrackingTest/5a5ee24e44/renamed-test-file.jpg"',
            $page->dbObject('Content')->forTemplate()
        );
        Versioned::withVersionedMode(function () use ($page) {
            Versioned::set_stage(Versioned::LIVE);
            $pageLive = EditableObject::get()->byID($page->ID);
            $this->assertContains(
                '<img src="/assets/FileLinkTrackingTest/5a5ee24e44/renamed-test-file.jpg"',
                $pageLive->dbObject('Content')->forTemplate()
            );
        });
    }

    public function testLinkRewritingOnAPublishedPageDoesntMakeItEditedOnDraft()
    {
        // Publish the source page
        /** @var EditableObject $page */
        $page = $this->objFromFixture(EditableObject::class, 'page1');
        $this->assertTrue($page->publishRecursive());
        $this->assertFalse($page->isModifiedOnDraft());

        // Rename the file
        /** @var Image $image */
        $image = $this->objFromFixture(Image::class, 'image1');
        $image->Name = 'renamed-test-file.jpg';
        $image->write();

        // Confirm that the page hasn't gone green.
        $this->assertFalse($page->isModifiedOnDraft());
    }

    public function testTwoFileRenamesInARowWork()
    {
        /** @var EditableObject $page */
        $page = $this->objFromFixture(EditableObject::class, 'page1');
        $this->assertTrue($page->publishRecursive());

        Versioned::withVersionedMode(function () use ($page) {
            Versioned::set_stage(Versioned::LIVE);
            $livePage = EditableObject::get()->byID($page->ID);
            $this->assertContains(
                '<img src="/assets/FileLinkTrackingTest/5a5ee24e44/testscript-test-file.jpg"',
                $livePage->dbObject('Content')->forTemplate()
            );
        });

        // Rename the file twice
        /** @var Image $image */
        $image = $this->objFromFixture(Image::class, 'image1');
        $image->Name = 'renamed-test-file.jpg';
        $image->write();

        // TODO Workaround for bug in DataObject->getChangedFields(), which returns stale data,
        // and influences File->updateFilesystem()
        $image = Image::get()->byID($image->ID);
        $image->Name = 'renamed-test-file-second-time.jpg';
        $image->write();
        $image->publishRecursive();

        // Confirm that the correct image is shown in both the draft and live site
        $page = EditableObject::get()->byID($page->ID);
        $this->assertContains(
            '<img src="/assets/FileLinkTrackingTest/5a5ee24e44/renamed-test-file-second-time.jpg"',
            $page->dbObject('Content')->forTemplate()
        );

        // Publishing this record also updates live record
        $page->publishRecursive();
        Versioned::withVersionedMode(function () use ($page) {
            Versioned::set_stage(Versioned::LIVE);
            $pageLive = EditableObject::get()->byID($page->ID);
            $this->assertContains(
                '<img src="/assets/FileLinkTrackingTest/5a5ee24e44/renamed-test-file-second-time.jpg"',
                $pageLive->dbObject('Content')->forTemplate()
            );
        });
    }

    /**
     * Ensure broken CSS classes are assigned on <a> tags which contain broken links.
     * Doesn't cover [image ] shortcodes as these are not contained by parent <a>
     */
    public function testBrokenCSSClasses()
    {
        /** @var EditableObject $page */
        $page = $this->objFromFixture(EditableObject::class, 'page1');
        /** @var File $file */
        $file = $this->objFromFixture(File::class, 'file1');
        $fileID = $file->ID;

        $this->assertContains(
            '<p><a href="/assets/FileLinkTrackingTest/44781db6b1/testscript-test-file.txt">Working Link</a></p>',
            $page->dbObject('Another')->forTemplate()
        );
        $this->assertContains(
            sprintf('<p><a href="[file_link,id=%d]">Working Link</a></p>', $fileID),
            $page->Another
        );

        // Deleting file should trigger css class
        $file->delete();
        $page = EditableObject::get()->byID($page->ID);
        $this->assertContains(
            '<p><a href="" class="ss-broken">Working Link</a></p>',
            $page->dbObject('Another')->forTemplate()
        );
        $this->assertContains(
            sprintf('<p><a href="[file_link,id=%d]" class="ss-broken">Working Link</a></p>', $fileID),
            $page->Another
        );

        // Restoring the file should fix it
        /** @var File $fileLive */
        $fileLive = Versioned::get_by_stage(File::class, Versioned::LIVE)->byID($fileID);
        $fileLive->doRevertToLive();
        $page = EditableObject::get()->byID($page->ID);
        $this->assertContains(
            sprintf('<p><a href="[file_link,id=%d]">Working Link</a></p>', $fileID),
            $page->Another
        );
        $this->assertContains(
            '<p><a href="/assets/FileLinkTrackingTest/44781db6b1/testscript-test-file.txt">Working Link</a></p>',
            $page->dbObject('Another')->forTemplate()
        );
    }
}
