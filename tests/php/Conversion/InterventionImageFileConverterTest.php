<?php

namespace SilverStripe\Assets\Tests\Conversion;

use SilverStripe\Assets\Conversion\FileConverterException;
use SilverStripe\Assets\Conversion\InterventionImageFileConverter;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Dev\SapphireTest;
use PHPUnit\Framework\Attributes\DataProvider;

class InterventionImageFileConverterTest extends SapphireTest
{
    protected static $fixture_file = 'InterventionImageFileConverterTest.yml';

    protected function setUp(): void
    {
        parent::setUp();

        // Set backend root to /InterventionImageFileConverterTest
        TestAssetStore::activate('InterventionImageFileConverterTest');

        // Copy test images for each of the fixture references
        /** @var File $image */
        $files = File::get()->exclude('ClassName', Folder::class);
        foreach ($files as $file) {
            if ($file->Name === 'test-missing-image.jpg') {
                continue;
            }
            $sourcePath = __DIR__ . '/InterventionImageFileConverterTest/' . $file->Name;
            $file->setFromLocalFile($sourcePath, $file->Filename);
            $file->publishSingle();
        }
    }

    public static function provideSupportsConversion(): array
    {
        // We don't need to check every possible file type here.
        // We're just validating that the logic overall holds true.
        return [
            'nothing to convert' => [
                'from' => '',
                'to' => '',
                'options' => [],
                'expected' => false,
            ],
            'nothing to convert from' => [
                'from' => '',
                'to' => 'png',
                'options' => [],
                'expected' => false,
            ],
            'nothing to convert to' => [
                'from' => 'png',
                'to' => '',
                'options' => [],
                'expected' => false,
            ],
            'jpg to jpg' => [
                'from' => 'jpg',
                'to' => 'jpg',
                'options' => [],
                'expected' => true,
            ],
            'jpg to png' => [
                'from' => 'jpg',
                'to' => 'png',
                'options' => [],
                'expected' => true,
            ],
            'jpg to png with quality option' => [
                'from' => 'jpg',
                'to' => 'png',
                'options' => ['quality' => 100],
                'expected' => true,
            ],
            'jpg to png with invalid quality option' => [
                'from' => 'jpg',
                'to' => 'png',
                'options' => ['quality' => 'invalid'],
                'expected' => false,
            ],
            'jpg to png with unexpected option' => [
                'from' => 'jpg',
                'to' => 'png',
                'options' => ['what is this' => 100],
                'expected' => false,
            ],
        ];
    }

    #[DataProvider('provideSupportsConversion')]
    public function testSupportsConversion(string $from, string $to, array $options, bool $expected): void
    {
        $converter = new InterventionImageFileConverter();
        $this->assertSame($expected, $converter->supportsConversion($from, $to, $options));
    }

    public static function provideConvert(): array
    {
        return [
            'no options' => [
                'options' => [],
            ],
            'change quality' => [
                'options' => ['quality' => 5],
            ],
        ];
    }

    #[DataProvider('provideConvert')]
    public function testConvert(array $options): void
    {
        $origFile = $this->objFromFixture(Image::class, 'jpg-image');
        $origQuality = $origFile->getImageBackend()->getQuality();
        $converter = new InterventionImageFileConverter();
        // Do a conversion we know is supported by both GD and Imagick
        $pngFile = $converter->convert($origFile->File, 'png', $options);

        // Validate new file has correct format, but original file is untouched
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $this->assertSame('image/png', $finfo->buffer($pngFile->getString()));
        $this->assertSame('image/jpeg', $finfo->buffer($origFile->getString()));

        if (array_key_exists('quality', $options)) {
            $this->assertSame($options['quality'], $pngFile->getImageBackend()->getQuality());
            $this->assertSame($origQuality, $origFile->getImageBackend()->getQuality());
        }
    }

    public static function provideConvertUnsupported(): array
    {
        return [
            'nothing to convert from' => [
                'fixtureClass' => Image::class,
                'fromFixture' => 'missing-image',
                'to' => 'png',
                'options' => [],
                'exceptionMessage' => 'ImageBackend must be an instance of InterventionBackend. Got null',
            ],
            'nothing to convert to' => [
                'fixtureClass' => Image::class,
                'fromFixture' => 'jpg-image',
                'to' => '',
                'options' => [],
                'exceptionMessage' => 'Convertion to format \'\' is not suported.',
            ],
            'jpg to txt' => [
                'fixtureClass' => Image::class,
                'fromFixture' => 'jpg-image',
                'to' => 'txt',
                'options' => [],
                'exceptionMessage' => 'Convertion to format \'txt\' is not suported.',
            ],
            'txt to jpg' => [
                'fixtureClass' => File::class,
                'fromFixture' => 'not-image',
                'to' => 'jpg',
                'options' => [],
                'exceptionMessage' => 'ImageBackend must be an instance of InterventionBackend. Got null',
            ],
            'jpg to png with invalid quality option' => [
                'fixtureClass' => Image::class,
                'fromFixture' => 'jpg-image',
                'to' => 'png',
                'options' => ['quality' => 'invalid'],
                'exceptionMessage' => 'Invalid options provided: quality value must be an integer',
            ],
            'jpg to png with unexpected option' => [
                'fixtureClass' => Image::class,
                'fromFixture' => 'jpg-image',
                'to' => 'png',
                'options' => ['what is this' => 100],
                'exceptionMessage' => 'Invalid options provided: unexpected option \'what is this\'',
            ],
        ];
    }

    #[DataProvider('provideConvertUnsupported')]
    public function testConvertUnsupported(string $fixtureClass, string $fromFixture, string $to, array $options, string $exceptionMessage): void
    {
        $file = $this->objFromFixture($fixtureClass, $fromFixture);
        $converter = new InterventionImageFileConverter();

        $this->expectException(FileConverterException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $converter->convert($file->File, $to, $options);
    }
}
