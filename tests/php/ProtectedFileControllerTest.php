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

/**
 * @skipUpgrade
 */
class ProtectedFileControllerTest extends FunctionalTest
{
    protected static $fixture_file = 'FileTest.yml';

    public function setUp()
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

    public function tearDown()
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    /**
     * @dataProvider getFilenames
     */
    public function testIsValidFilename($name, $isValid)
    {
        $controller = new ProtectedFileController();
        $this->assertEquals(
            $isValid,
            $controller->isValidFilename($name),
            "Assert filename \"$name\" is " . $isValid ? "valid" : "invalid"
        );
    }

    public function getFilenames()
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
            $this->assertEquals('text/plain', $response->getHeader('Content-Type'));
        } else {
            $this->assertTrue($response->isError());
        }
    }
}
