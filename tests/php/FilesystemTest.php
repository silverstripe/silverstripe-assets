<?php

namespace SilverStripe\Assets\Tests;

use SilverStripe\Assets\Filesystem;
use SilverStripe\Dev\SapphireTest;

class FilesystemTest extends SapphireTest
{
    public function testRemoveFolderNormalCase()
    {
        // Create a temporary folder
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'silverstripe-assets';
        Filesystem::removeFolder($path);
        mkdir($path);
        // Chuck a "normal" folder inside it
        $normalFolderPath = $path . DIRECTORY_SEPARATOR . 'normal';
        mkdir($normalFolderPath);
        // Ensure the folder inside exists
        $this->assertTrue(is_dir($normalFolderPath));
        // Remove the folder and ensure it's no longer there
        Filesystem::removeFolder($normalFolderPath);
        $this->assertFalse(is_dir($normalFolderPath));
        // Add that folder back in
        mkdir($normalFolderPath);
        // And put a file inside
        $filePath = $normalFolderPath . DIRECTORY_SEPARATOR . 'example-file';
        file_put_contents($filePath, 'example');
        // Ensure our path is still there and the file exists
        $this->assertTrue(is_dir($path));
        $this->assertTrue(is_file($filePath));
        // Remove the path
        Filesystem::removeFolder($path);
        // Ensure it was removed
        $this->assertFalse(is_dir($path));
        // Ensure the "normal" folder was removed as part of the recursive removal
        $this->assertFalse(is_dir($normalFolderPath));
        // Ensure our file was deleted too
        $this->assertFalse(is_file($filePath));
    }

    public function testRemoveFolderFalsieNames()
    {
        // Create a temporary folder
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'silverstripe-assets';
        mkdir($path);
        // Create a file with the name `0` which evaluates to false
        $filePath = $path . DIRECTORY_SEPARATOR . '0';
        file_put_contents($filePath, 'example');
        // Try to remove the folder and file recursively
        Filesystem::removeFolder($path);
        // Ensure they were both removed
        $this->assertFalse(is_dir($path));
        $this->assertFalse(is_file($filePath));
    }

    public function testRemovedFolderContentsOnly()
    {
        // Create a temporary folder
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'silverstripe-assets';
        mkdir($path);
        // Create a file with the name `0` which evaluates to false
        $filePath = $path . DIRECTORY_SEPARATOR . '0';
        file_put_contents($filePath, 'example');
        // Try to remove the folder and file recursively
        Filesystem::removeFolder($path, true);
        // Ensure the folder still exists
        $this->assertTrue(is_dir($path));
        // Ensure the asset doesn't exist
        $this->assertFalse(is_file($filePath));
        // Finally clean up after ourselves
        Filesystem::removeFolder($path);
    }
}
