<?php
namespace SilverStripe\Assets\Tests\FilenameParsing;

use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Dev\SapphireTest;

class ParsedFileIDTest extends SapphireTest
{

    public function testHashlessVariantlessFileID()
    {
        $pFileId = new ParsedFileID('rando/original/filename.jpg', 'sam.jpg');
        $this->assertEquals('rando/original/filename.jpg', $pFileId->getOriginalFileID());
        $this->assertEquals('sam.jpg', $pFileId->getFilename());
        $this->assertEmpty($pFileId->getVariant());
        $this->assertEmpty($pFileId->getHash());

        $tuple = $pFileId->getTuple();
        $this->assertEquals('sam.jpg', $tuple['Filename']);
        $this->assertNull($tuple['Variant']);
        $this->assertNull($tuple['Hash']);
    }

    public function testHashVariantFileID()
    {
        $pFileId = new ParsedFileID('rando/original/filename.jpg', 'sam.jpg', 'resizeXYZ', 'abcdef7890');
        $this->assertEquals('rando/original/filename.jpg', $pFileId->getOriginalFileID());
        $this->assertEquals('sam.jpg', $pFileId->getFilename());
        $this->assertEquals('resizeXYZ', $pFileId->getVariant());
        $this->assertEquals('abcdef7890', $pFileId->getHash());

        $tuple = $pFileId->getTuple();
        $this->assertEquals('sam.jpg', $tuple['Filename']);
        $this->assertEquals('resizeXYZ', $tuple['Variant']);
        $this->assertEquals('abcdef7890', $tuple['Hash']);
    }
}
