<?php

namespace SilverStripe\Assets\Tests;

use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\ProtectedFileController;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Assets\File;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Assets\Dev\TestAssetStore;

/**
 * @skipUpgrade
 */
class RedirectFileControllerTest extends FunctionalTest
{
    protected static $fixture_file = 'FileTest.yml';

    protected $autoFollowRedirection = false;

    public function setUp()
    {
        parent::setUp();

        // Set backend root to /ImageTest
        TestAssetStore::activate('RedirectFileControllerTest');

        // Create a test folders for each of the fixture references
        foreach (Folder::get() as $folder) {
            /** @var Folder $folder */
            $filePath = TestAssetStore::getLocalPath($folder);
            Filesystem::makeFolder($filePath);
        }

        // Create a test files for each of the fixture references
        foreach (File::get()->exclude('ClassName', Folder::class) as $file) {
            /** @var File $file */
            $path = TestAssetStore::getLocalPath($file);
            Filesystem::makeFolder(dirname($path));
            $fh = fopen($path, "w+");
            fwrite($fh, 'version 1');
            fclose($fh);

            // Create variant for each file
            $this->getAssetStore()->setFromString(
                str_repeat('y', 100),
                $file->Filename,
                $file->Hash,
                'variant'
            );
        }
    }

    public function tearDown()
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function fileList()
    {
        return [
            ['asdf'],
            ['subfolderfile'],
            ['double-extension'],
            ['double-underscore'],
        ];
    }

    /**
     * @dataProvider fileList
     */
    public function testLegacyFilenameRedirect($fixtureID)
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, $fixtureID);

        $response = $this->get('/assets/' . $file->getFilename());
        $this->assertResponse(
            404,
            '',
            false,
            $response,
            'Legacy URL for unpublished file should return 404'
        );

        $file->publishSingle();
        $response = $this->get('/assets/' . $file->getFilename());
        $this->assertResponse(
            302,
            '',
            $file->getURL(false),
            $response,
            'Legacy URL for published file should return 302'
        );

        $response = $this->get($response->getHeader('location'));
        $this->assertResponse(
            200,
            'version 1',
            false,
            $response,
            'Redirected legacy url should return 200'
        );
    }


    /**
     * @dataProvider fileList
     */
    public function testRedirectWithDraftFile($fixtureID)
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, $fixtureID);
        $file->publishSingle();
        $v1Url = $file->getURL(false);

        $file->setFromString('version 2', $file->getFilename());
        $file->write();
        $v2Url = $file->getURL(false);

        // Before publishing second draft file
        $response = $this->get('/assets/' . $file->getFilename());
        $this->assertResponse(
            302,
            '',
            $v1Url,
            $response,
            'Legacy URL for published file should return 302 to live file'
        );

        $response = $this->get($response->getHeader('location'));
        $this->assertResponse(
            200,
            'version 1',
            false,
            $response,
            'Redirected legacy url should return 200 with content of live file'
        );

        $response = $this->get($v2Url);
        $this->assertResponse(
            403,
            '',
            false,
            $response,
            'Draft URL without grant should 403'
        );
    }

    /**
     * @dataProvider fileList
     */
    public function testRedirectAfterPublishSecondVersion($fixtureID)
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, $fixtureID);
        $file->publishSingle();
        $v1Url = $file->getURL(false);

        $file->setFromString('version 2', $file->getFilename());
        $file->write();
        $v2Url = $file->getURL(false);

        $file->publishSingle();

        // After publishing second draft file
        $response = $this->get($v2Url);
        $this->assertResponse(
            200,
            'version 2',
            false,
            $response,
            'Publish version should resolve with 200'
        );

        $response = $this->get('/assets/' . $file->getFilename());
        $this->assertResponse(
            302,
            '',
            $v2Url,
            $response,
            'Legacy URL should redirect to the latest live version'
        );

        $response = $this->get($v1Url);
        $this->assertResponse(
            302,
            '',
            $v2Url,
            $response,
            'Old Hash URL should redirect to the latest live version'
        );
    }

    /**
     * @dataProvider fileList
     */
    public function testRedirectAfterUnpublish($fixtureID)
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, $fixtureID);
        $file->publishSingle();
        $v1Url = $file->getURL(false);

        $file->setFromString('version 2', $file->getFilename());
        $file->write();
        $v2Url = $file->getURL(false);

        $file->publishSingle();
        $file->doUnpublish();

        // After unpublishing file
        $response = $this->get($v2Url);
        $this->assertResponse(
            403,
            '',
            false,
            $response,
            'Unpublish file should return 403'
        );

        $response = $this->get('/assets/' . $file->getFilename());
        $this->assertResponse(
            404,
            '',
            false,
            $response,
            'Legacy URL of unpublish files should return 404'
        );

        $response = $this->get($v1Url);


        $this->assertResponse(
            404,
            '',
            false,
            $response,
            'Old Hash URL of unpublsihed files should return 404'
        );
    }

    /**
     * @dataProvider fileList
     */
    public function testRedirectAfterDeleting($fixtureID)
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, $fixtureID);
        $file->publishSingle();
        $v1Url = $file->getURL(false);

        $file->setFromString('version 2', $file->getFilename());
        $file->write();
        $file->publishSingle();
        $v2Url = $file->getURL(false);

        $file->delete();
        $file->deleteFile();

        $response = $this->get($v2Url);
        $this->assertResponse(
            404,
            '',
            false,
            $response,
            'Deleted file file should return 404'
        );

        $response = $this->get('/assets/' . $file->getFilename());
        $this->assertResponse(
            404,
            '',
            false,
            $response,
            'Legacy URL of deleted files should return 404'
        );

        $response = $this->get($v1Url);
        $this->assertResponse(
            404,
            '',
            false,
            $response,
            'Old Hash URL of deleted files should return 404'
        );
    }

    public function imageList()
    {
        return [
            ['', 'test', 'jpg'],
            ['subfolder', 'test', 'jpg'],
            ['deep-folder', 'test', 'zzz.jpg'],
            ['deep-folder', 'test_zzz', 'jpg'],
        ];
    }

    /**
     * @dataProvider imageList
     */
    public function testVariantRedirect($folderFixture, $filename, $ext)
    {
        /** @var Image $file */
        $file = Image::create();
        $foldername = '';

        if ($folderFixture) {
            $folder = $this->objFromFixture(Folder::class, $folderFixture);
            $file->ParentID = $folder->ID;
            $foldername = $folder->getFileName() ;
        }
        $file->FileFilename = $foldername . $filename . '.' . $ext;

        $file->setFromLocalFile(__DIR__ . '/ImageTest/landscape-to-portrait.jpg', $file->FileFilename);
        $file->write();
        $file->publishSingle();
        $ico = $file->ScaleWidth(32);
        $icoUrl = $ico->getURL(false);
        $suffix = $ico->getVariant();

        $response = $this->get($icoUrl);
        $this->assertResponse(
            200,
            $ico->getString(),
            false,
            $response,
            'Publish variant sghould resolve with 200'
        );

        $response = $this->get("/assets/{$foldername}{$filename}__$suffix.$ext");
        $this->assertResponse(
            302,
            '',
            $icoUrl,
            $response,
            'Legacy path to variant should redirect.'
        );

        $response = $this->get("/assets/{$foldername}_resampled/$suffix/$filename.$ext");
        $this->assertResponse(
            302,
            '',
            $icoUrl,
            $response,
            'SS3 Legacy path to variant should redirect.'
        );

        $file->setFromLocalFile(__DIR__ . '/ImageTest/test-image-high-quality.jpg', $file->FileFilename);
        $file->write();
        $file->publishSingle();
        $ico = $file->ScaleWidth(32);
        $icoV2Url = $ico->getURL(false);

        $response = $this->get($icoUrl);
        $this->assertResponse(
            302,
            '',
            $icoV2Url,
            $response,
            'Old URL to variant should redirect with 302'
        );
    }

    /**
     * @dataProvider fileList
     */
    public function testDraftOnlyArchivedVersion($fixtureID)
    {
        $file = $this->objFromFixture(File::class, $fixtureID);
        $v1Url = $file->getURL(false);
        $file->deleteFile();

        $file->setFromString('version 2', $file->getFilename());
        $file->write();
        $file->publishSingle();
        $v2Url = $file->getURL(false);

        $response = $this->get($v1Url);
        $this->assertResponse(
            404,
            '',
            false,
            $response,
            'Old Hash URL of version that never got published should return 404'
        );
    }

    /**
     * @return AssetStore
     */
    protected function getAssetStore()
    {
        return Injector::inst()->get(AssetStore::class);
    }

    /**
     * Assert that a response matches the given parameters
     *
     * @param int          $code        HTTP code
     * @param string       $body        Body expected for 200 responses
     * @param string|false $location    Location to redirect to or false if no redirect
     * @param HTTPResponse $response
     * @param string       $message
     */
    protected function assertResponse($code, $body, $location, HTTPResponse $response, $message = '')
    {
        $this->assertEquals($code, $response->getStatusCode(), $message);
        if ($code < 400) {
            $this->assertFalse($response->isError(), $message);
            $this->assertEquals($body, $response->getBody(), $message);
        } else {
            $this->assertTrue($response->isError(), $message);
        }

        if ($location) {
            $this->assertEquals(
                $this->normaliseUrl($location),
                $this->normaliseUrl($response->getHeader('location')),
                $message
            );
        } else {
            $this->assertNull($response->getHeader('location'), $message);
        }
    }

    /**
     * When the CMS builds a URL it wants to include our test folder in it. We want to strip that out.
     * @param $path
     * @return mixed
     */
    protected function normaliseUrl($path)
    {
        return str_replace('RedirectFileControllerTest/', '', $path);
    }

    public function get($url, $session = null, $headers = null, $cookies = null)
    {
        return parent::get($this->normaliseUrl($url), $session, $headers, $cookies);
    }
}
