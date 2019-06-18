<?php

namespace SilverStripe\Assets\Tests;

use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\FilenameParsing\HashFileIDHelper;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;

/**
 * @skipUpgrade
 */
class RedirectFileControllerTest extends FunctionalTest
{
    protected static $fixture_file = 'FileTest.yml';

    protected $autoFollowRedirection = false;

    protected function setUp()
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

            $file->File->Hash = sha1('version 1');
            $file->write();

            // Create variant for each file
            $file->setFromString(
                'version 1',
                $file->getFilename(),
                $file->getHash(),
                null,
                ['visibility' => AssetStore::VISIBILITY_PROTECTED]
            );
        }
    }

    protected function tearDown()
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function fileList()
    {
        return [
            'root file' => ['asdf'],
            'file in folder' => ['subfolderfile'],
            'file with double extension' => ['double-extension'],
            'path with double underscore' => ['double-underscore'],
        ];
    }

    /**
     * @dataProvider fileList
     */
    public function testPermanentFilenameRedirect($fixtureID)
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, $fixtureID);

        $hashHelper = new HashFileIDHelper();
        $hashUrl = '/assets/' . $hashHelper->buildFileID($file->getFilename(), $file->getHash());

        $response = $this->get($hashUrl);
        $this->assertResponse(
            403,
            '',
            false,
            $response,
            'Hash URL for unpublished file should return 403'
        );

        $file->publishSingle();

        $response = $this->get($hashUrl);
        $this->assertResponse(
            301,
            '',
            $file->getURL(false),
            $response,
            'Hash URL of public file should redirect to published file with 301'
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
     * This test a file publish under the hash path, will redirect natural path
     * @dataProvider fileList
     */
    public function testTemporaryFilenameRedirect($fixtureID)
    {
        /** @var File $file */
        $file = $this->objFromFixture(File::class, $fixtureID);

        $hashHelper = new HashFileIDHelper();
        $hashUrl = $hashHelper->buildFileID($file->getFilename(), $file->getHash());
        $naturalUrl = $file->getFilename();

        $response = $this->get('assets/' . $hashUrl);
        $this->assertResponse(
            403,
            '',
            false,
            $response,
            'Hash URL for unpublished file should return 403'
        );

        $file->publishSingle();

        // This replicates a scenario where a file was publish under a hash path in SilverStripe 4.3
        $store = $this->getAssetStore();
        $fs = $store->getPublicFilesystem();
        $fs->rename($naturalUrl, $hashUrl);

        $response = $this->get('assets/' . $naturalUrl);
        $this->assertResponse(
            302,
            '',
            $file->getURL(false),
            $response,
            'natural URL of published hash file should redirect with 302'
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
        $hashHelper = new HashFileIDHelper();

        /** @var File $file */
        $file = $this->objFromFixture(File::class, $fixtureID);
        $file->publishSingle();
        $v1HashUrl = '/assets/' . $hashHelper->buildFileID($file->getFilename(), $file->getHash());
        $v1Url = $file->getURL(false);

        $file->File->Hash = sha1('version 2');
        $file->setFromString('version 2', $file->getFilename(), null, null, ['visibility' => FlysystemAssetStore::VISIBILITY_PROTECTED]);
        $file->write();
        $v2Url = $file->getURL(false);

        // Before publishing second draft file
        $response = $this->get($v1HashUrl);
        $this->assertResponse(
            301,
            '',
            $v1Url,
            $response,
            'Legacy URL for published file should return 301 to live file'
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
        $hashHelper = new HashFileIDHelper();

        /** @var File $file */
        $file = $this->objFromFixture(File::class, $fixtureID);
        $file->publishSingle();
        $v1HashUrl = '/assets/' . $hashHelper->buildFileID($file->getFilename(), $file->getHash(), $file->getVariant());

        $file->setFromString('version 2', $file->getFilename());
        $file->write();

        $v2HashUrl = '/assets/' . $hashHelper->buildFileID($file->getFilename(), $file->getHash(), $file->getVariant());
        $file->publishSingle();
        $v2Url = $file->getURL(false);

        // After publishing second draft file
        $response = $this->get($v2Url);
        $this->assertResponse(
            200,
            'version 2',
            false,
            $response,
            'Publish version should resolve with 200'
        );

        $response = $this->get($v2HashUrl);
        $this->assertResponse(
            301,
            '',
            $v2Url,
            $response,
            'Latest hash URL should redirect to the latest natural path URL'
        );

        $response = $this->get($v1HashUrl);
        $this->assertResponse(
            301,
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
        $hash = substr($file->getHash(), 0, 10);
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

        $response = $this->get("/assets/{$foldername}{$hash}/{$filename}__$suffix.$ext");
        $this->assertResponse(
            301,
            '',
            $icoUrl,
            $response,
            'Hash path variant of public file should redirect to natural path.'
        );

        $response = $this->get("/assets/{$foldername}_resampled/$suffix/$filename.$ext");
        $this->assertResponse(
            301,
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

        $response = $this->get("/assets/{$foldername}{$hash}/{$filename}__$suffix.$ext");
        $this->assertResponse(
            301,
            '',
            $icoV2Url,
            $response,
            'Old Hash URL of public file should redirect to natural path of file.'
        );
    }

    /**
     * @dataProvider fileList
     */
    public function testDraftOnlyArchivedVersion($fixtureID)
    {
        /** @var File $file */
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
     * Fetch a file asset url and assert that the reponse meet some criteria
     * @param string $url URL to fetch. The URL is normalise to always start with `/assets/`
     * @param string $code Expected response HTTP code
     * @param string $body Expected body of the response. Only checked for non-error codes
     * @param string|false $location Expected location header or false for non-redirect response
     * @param string $message Failed assertion message
     */
    protected function assertGetResponse($url, $code, $body, $location, $message = '')
    {
        // Make sure the url is prefix with assets
        $url = '/assets/' . preg_replace('#^/?(assets)?\/?#', '', $url);
        $this->assertResponse(
            $code,
            $body,
            $location,
            $this->get($url),
            ($message ? "$message\n" : "") . "Fetching $url failed"
        );
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

    public function get(
        string $url,
        Session $session = null,
        array $headers = null,
        array $cookies = null
    ) : HTTPResponse {
        return parent::get($this->normaliseUrl($url), $session, $headers, $cookies);
    }
}
