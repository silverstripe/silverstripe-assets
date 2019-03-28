<?php
namespace SilverStripe\Assets\Tests\FilenameParsing;

use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Dev\SapphireTest;

class ParsedFileIDTest extends SapphireTest
{

    public function testHashlessVariantlessFileID()
    {
        $pFileId = new ParsedFileID('sam.jpg');
        $this->assertEquals('sam.jpg', $pFileId->getFilename());
        $this->assertEmpty($pFileId->getVariant());
        $this->assertEmpty($pFileId->getHash());
        $this->assertEmpty($pFileId->getFileID());

        $tuple = $pFileId->getTuple();
        $this->assertEquals('sam.jpg', $tuple['Filename']);
        $this->assertNull($tuple['Variant']);
        $this->assertNull($tuple['Hash']);
    }

    public function testHashVariantFileID()
    {
        $pFileId = new ParsedFileID(
            'sam.jpg',
            'abcdef7890',
            'resizeXYZ',
            'rando/original/filename.jpg'
        );
        $this->assertEquals('rando/original/filename.jpg', $pFileId->getFileID());
        $this->assertEquals('sam.jpg', $pFileId->getFilename());
        $this->assertEquals('resizeXYZ', $pFileId->getVariant());
        $this->assertEquals('abcdef7890', $pFileId->getHash());

        $tuple = $pFileId->getTuple();
        $this->assertEquals('sam.jpg', $tuple['Filename']);
        $this->assertEquals('resizeXYZ', $tuple['Variant']);
        $this->assertEquals('abcdef7890', $tuple['Hash']);
    }

    public function testImmutableSetters()
    {
        $origin = new ParsedFileID(
            'sam.jpg',
            'abcdef7890',
            'resizeXYZ',
            'rando/original/filename.jpg'
        );

        $next = $origin->setFileID('rando/next/filename.jpg');
        $this->assertNotEquals($origin, $next);
        $this->assertEquals('rando/next/filename.jpg', $next->getFileID());
        $this->assertEquals($origin->getFilename(), $next->getFilename());
        $this->assertEquals($origin->getHash(), $next->getHash());
        $this->assertEquals($origin->getVariant(), $next->getVariant());

        $next = $origin->setFilename('sam.gif');
        $this->assertNotEquals($origin, $next);
        $this->assertEquals('sam.gif', $next->getFilename());
        $this->assertEquals($origin->getFileID(), $next->getFileID());
        $this->assertEquals($origin->getHash(), $next->getHash());
        $this->assertEquals($origin->getVariant(), $next->getVariant());

        $next = $origin->setHash('0987fedcba');
        $this->assertNotEquals($origin, $next);
        $this->assertEquals('0987fedcba', $next->getHash());
        $this->assertEquals($origin->getFileID(), $next->getFileID());
        $this->assertEquals($origin->getFilename(), $next->getFilename());
        $this->assertEquals($origin->getVariant(), $next->getVariant());

        $next = $origin->setVariant('scaleXYZ');
        $this->assertNotEquals($origin, $next);
        $this->assertEquals('scaleXYZ', $next->getVariant());
        $this->assertEquals($origin->getFileID(), $next->getFileID());
        $this->assertEquals($origin->getFilename(), $next->getFilename());
        $this->assertEquals($origin->getHash(), $next->getHash());
    }
}
