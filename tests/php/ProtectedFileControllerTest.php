<?php

namespace SilverStripe\Assets\Tests;

use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\ProtectedFileController;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Versioned\Versioned;
use PHPUnit\Framework\Attributes\DataProvider;

class ProtectedFileControllerTest extends FunctionalTest
{
    protected static $fixture_file = 'FileTest.yml';

    protected function setUp(): void
    {
        parent::setUp();

        // Set backend root to /ImageTest
        TestAssetStore::activate('ProtectedFileControllerTest');

        // Create a test folders for each of the fixture references
        foreach (Folder::get() as $folder) {
            /** @var Folder $folder */
            $filePath = TestAssetStore::getLocalPath($folder);
            Filesystem::makeFolder($filePath);
        }

        // Create a test files for each of the fixture references
        foreach (File::get()->exclude('ClassName', Folder::class) as $file) {
            $file->setFromString(str_repeat('x', 1000000), $file->Filename);
            $file->setFromString(str_repeat('y', 100), $file->Filename, $file->Hash, 'variant');
            $file->write();
            $file->publishRecursive();
        }

        /** @var File $protectedFile */
        $protectedFile = $this->objFromFixture(File::class, 'restrictedViewFolder-file4');
        $protectedFile->protectFile();
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    #[DataProvider('getFilenames')]
    public function testIsValidFilename($name, $isValid)
    {
        $controller = new ProtectedFileController();
        $this->assertEquals(
            $isValid,
            $controller->isValidFilename($name),
            "Assert filename \"$name\" is " . $isValid ? "valid" : "invalid"
        );
    }

    public static function getFilenames()
    {
        return [
            // Valid names
            ['name.jpg', true],
            ['parent/name.jpg', true],
            ['parent/name', true],
            ['parent\name.jpg', true],
            ['parent\name', true],
            ['name', true],

            // Invalid names
            ['.invalid/name.jpg', false],
            ['.invalid\name.jpg', false],
            ['.htaccess', false],
            ['test/.htaccess.jpg', false],
            ['name/.jpg', false],
            ['test\.htaccess.jpg', false],
            ['name\.jpg', false]
        ];
    }

    /**
     * Test that certain requests are denied
     */
    public function testInvalidRequest()
    {
        $result = $this->get('assets/.protected/file.jpg');
        $this->assertResponseEquals(400, null, $result);
    }

    /**
     * Test that invalid files generate 404 response
     */
    public function testFileNotFound()
    {
        $result = $this->get('assets/missing.jpg');
        $this->assertResponseEquals(404, null, $result);
    }

    /**
     * Check public access to assets is available at the appropriate time
     *
     * Links to incorrect base (assets/ rather than assets/ProtectedFileControllerTest)
     * because ProtectedAdapter doesn't know about custom base dirs in TestAssetStore
     */
    public function testAccessControl()
    {
        $expectedContent = str_repeat('x', 1000000);
        $variantContent = str_repeat('y', 100);

        $result = $this->get('assets/FileTest.txt');
        $this->assertResponseEquals(200, $expectedContent, $result);
        $result = $this->get('assets/FileTest__variant.txt');
        $this->assertResponseEquals(200, $variantContent, $result);

        // Make this file protected
        $this->getAssetStore()->protect(
            'FileTest.txt',
            '55b443b60176235ef09801153cca4e6da7494a0c'
        );

        // Should now return explicitly denied errors
        $result = $this->get('assets/55b443b601/FileTest.txt');
        $this->assertResponseEquals(403, null, $result);
        $result = $this->get('assets/55b443b601/FileTest__variant.txt');
        $this->assertResponseEquals(403, null, $result);

        // Other assets remain available
        $result = $this->get('assets/FileTest.pdf');
        $this->assertResponseEquals(200, $expectedContent, $result);
        $result = $this->get('assets/FileTest__variant.pdf');
        $this->assertResponseEquals(200, $variantContent, $result);

        // granting access will allow access
        $this->getAssetStore()->grant(
            'FileTest.txt',
            '55b443b60176235ef09801153cca4e6da7494a0c'
        );
        $result = $this->get('assets/55b443b601/FileTest.txt');
        $this->assertResponseEquals(200, $expectedContent, $result);
        $result = $this->get('assets/55b443b601/FileTest__variant.txt');
        $this->assertResponseEquals(200, $variantContent, $result);

        // Revoking access will remove access again
        $this->getAssetStore()->revoke(
            'FileTest.txt',
            '55b443b60176235ef09801153cca4e6da7494a0c'
        );
        $result = $this->get('assets/55b443b601/FileTest.txt');
        $this->assertResponseEquals(403, null, $result);
        $result = $this->get('assets/55b443b601/FileTest__variant.txt');
        $this->assertResponseEquals(403, null, $result);

        // Moving file back to public store restores access
        $this->getAssetStore()->publish(
            'FileTest.txt',
            '55b443b60176235ef09801153cca4e6da7494a0c'
        );
        $result = $this->get('assets/FileTest.txt');
        $this->assertResponseEquals(200, $expectedContent, $result);
        $result = $this->get('assets/FileTest__variant.txt');
        $this->assertResponseEquals(200, $variantContent, $result);

        // Deleting the file will make the response 404
        $this->getAssetStore()->delete(
            'FileTest.txt',
            '55b443b60176235ef09801153cca4e6da7494a0c'
        );
        $result = $this->get('assets/55b443b601/FileTest.txt');
        $this->assertResponseEquals(404, null, $result);
        $result = $this->get('assets/55b443b601/FileTest__variant.txt');
        $this->assertResponseEquals(404, null, $result);
        $result = $this->get('assets/FileTest.txt');
        $this->assertResponseEquals(404, null, $result);
        $result = $this->get('assets/FileTest__variant.txt');
        $this->assertResponseEquals(404, null, $result);
    }

    public function testAccessWithCanViewAccess()
    {
        $fileID = 'assets/restricted-view-folder/55b443b601/File4.txt';
        $fileVariantID = 'assets/restricted-view-folder/55b443b601/File4__variant.txt';
        $expectedContent = str_repeat('x', 1000000);
        $variantContent = str_repeat('y', 100);

        $this->logOut();

        $result = $this->get($fileID);
        $this->assertResponseEquals(403, null, $result);
        $result = $this->get($fileVariantID);
        $this->assertResponseEquals(403, null, $result);

        $this->logInAs('assetadmin');

        $result = $this->get($fileID);
        $this->assertResponseEquals(200, $expectedContent, $result);
        $result = $this->get($fileVariantID);
        $this->assertResponseEquals(200, $variantContent, $result);
    }

    public function testAccessDraftFiles()
    {
        $this->logOut();

        /* @var File $file */
        $file = $this->objFromFixture(File::class, 'asdf');
        $file->doUnpublish();

        $result = $this->get('assets/55b443b601/FileTest.txt');
        $this->assertEquals(403, $result->getStatusCode());

        $this->logInAs('assetadmin');
        $result = $this->get('assets/55b443b601/FileTest.txt');
        $this->assertEquals(200, $result->getStatusCode());

        $this->logOut();
        $fileID = 'assets/restricted-view-folder/55b443b601/File4.txt';
        $result = $this->get($fileID);
        $this->assertEquals(403, $result->getStatusCode());

        $this->logInAs('assetadmin');
        $result = $this->get($fileID);
        $this->assertEquals(200, $result->getStatusCode());

        $this->logOut();
        // Rename a file. Inaccessible on draft
        Versioned::withVersionedMode(function () use ($file) {
            Versioned::set_stage(Versioned::DRAFT);
            $file->renameFile('renamed.txt');
            $result = $this->get('assets/55b443b601/renamed.txt');
            $this->assertEquals(403, $result->getStatusCode());
        });
        // Public one is gone after renaming
        $result = $this->get('assets/55b443b601/FileTest.txt');
        $this->assertEquals(404, $result->getStatusCode());

        $restrictedFile = $this->objFromFixture(File::class, 'restrictedViewFolder-file4');

        // Restricted file keeps two copies in protected store
        Versioned::withVersionedMode(function () use ($restrictedFile) {
            Versioned::set_stage(Versioned::DRAFT);
            $restrictedFile->renameFile('restricted-view-folder/restricted-renamed.txt');
            $result = $this->get('assets/restricted-view-folder/55b443b601/restricted-renamed.txt');
            $this->assertEquals(403, $result->getStatusCode());
            // Old file name is also still there, but inaccessible
            $result = $this->get('assets/restricted-view-folder/55b443b601/File4.txt');
            $this->assertEquals(403, $result->getStatusCode());
        });

        // Original file is also still there, but inaceessible
        $result = $this->get('assets/restricted-view-folder/55b443b601/File4.txt');
        $this->assertEquals(403, $result->getStatusCode());

        $this->logInAs('assetadmin');
        Versioned::withVersionedMode(function () use ($file) {
            Versioned::set_stage(Versioned::DRAFT);
            $result = $this->get('assets/55b443b601/renamed.txt');
            $this->assertEquals(200, $result->getStatusCode());
        });

        // Public one is still gone, even when logged in
        $result = $this->get('assets/55b443b601/FileTest.txt');
        $this->assertEquals(404, $result->getStatusCode());

        // Restricted file keeps two copies in protected store
        Versioned::withVersionedMode(function () {
            Versioned::set_stage(Versioned::DRAFT);
            $result = $this->get('assets/restricted-view-folder/55b443b601/restricted-renamed.txt');
            $this->assertEquals(200, $result->getStatusCode());
            // Old file name is also still there, but inaccessible
            $result = $this->get('assets/restricted-view-folder/55b443b601/File4.txt');
            $this->assertEquals(403, $result->getStatusCode());
        });

        // Original file is also still there, but inaccessible
        $result = $this->get('assets/restricted-view-folder/55b443b601/File4.txt');
        $this->assertEquals(403, $result->getStatusCode());

        $file->publishRecursive();
        $restrictedFile->publishRecursive();

        $this->logOut();

        $result = $this->get('assets/renamed.txt');
        $this->assertEquals(200, $result->getStatusCode());

        $result = $this->get('assets/restricted-view-folder/55b443b601/restricted-renamed.txt');
        $this->assertEquals(403, $result->getStatusCode());
        // Old file name is gone
        $result = $this->get('assets/restricted-view-folder/55b443b601/File4.txt');
        $this->assertEquals(404, $result->getStatusCode());

        // New file only accessible to admin
        $this->logInAs('assetadmin');
        $result = $this->get('assets/restricted-view-folder/55b443b601/restricted-renamed.txt');
        $this->assertEquals(200, $result->getStatusCode());
    }

    /**
     * Test that access to folders is not permitted
     *
     * Links to incorrect base (assets/ rather than assets/ProtectedFileControllerTest)
     * because ProtectedAdapter doesn't know about custom base dirs in TestAssetStore
     */
    public function testFolders()
    {
        $result = $this->get('assets/does-not-exists');
        $this->assertResponseEquals(404, null, $result);

        $result = $this->get('assets/FileTest-subfolder');
        $this->assertResponseEquals(403, null, $result);

        // Flysystem reports root folder as not present
        $result = $this->get('assets');
        $this->assertResponseEquals(404, null, $result);
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
     * @param int          $code     HTTP code
     * @param string       $body     Body expected for 200 responses
     * @param HTTPResponse $response
     */
    protected function assertResponseEquals($code, $body, HTTPResponse $response)
    {
        $this->assertEquals($code, $response->getStatusCode());
        if ($code === 200) {
            $this->assertFalse($response->isError());
            $this->assertEquals($body, $response->getBody());
            // finfo::file() in league/flysystem Local::getMimeType() will return a mimetype of
            // 'text/plain' for test case pdfs in php7.4 + 8.0 , though in php8.1 it will
            // return 'application/octet-stream' which is then converted to 'application/pdf'
            // based on the file extension
            $this->assertTrue(in_array($response->getHeader('Content-Type'), ['text/plain', 'application/pdf', 'application/octet-stream']));
        } else {
            $this->assertTrue($response->isError());
        }
    }
}
