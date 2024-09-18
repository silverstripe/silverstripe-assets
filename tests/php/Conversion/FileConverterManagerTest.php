<?php

namespace SilverStripe\Assets\Tests\Conversion;

use SilverStripe\Assets\Conversion\FileConverterException;
use SilverStripe\Assets\Conversion\FileConverterManager;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Assets\Tests\Conversion\FileConverterManagerTest\TestImageConverter;
use SilverStripe\Assets\Tests\Conversion\FileConverterManagerTest\TestTxtToImageConverter;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use PHPUnit\Framework\Attributes\DataProvider;

class FileConverterManagerTest extends SapphireTest
{
    protected $usesDatabase = false;

    private array $originalFiles = [
        'txt' => null,
        'jpg' => null,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Make sure we have a known set of converters for testing
        FileConverterManager::config()->set('converters', [
            'some-service-name',
            TestImageConverter::class,
        ]);
        Injector::inst()->registerService(new TestTxtToImageConverter(), 'some-service-name');

        // Set backend root to /InterventionImageFileConverterTest
        TestAssetStore::activate('InterventionImageFileConverterTest');
        foreach (array_keys($this->originalFiles) as $ext) {
            $file = new DBFile('original-file.' . $ext);
            $fileToUse = $ext === 'txt' ? 'not-image.txt' : 'test-image.jpg';
            $sourcePath = __DIR__ . '/InterventionImageFileConverterTest/' . $fileToUse;
            $file->setFromLocalFile($sourcePath, $file->Filename);
            $this->originalFiles[$ext] = $file;
        }
    }

    public static function provideConvert(): array
    {
        return [
            'supported by image converter' => [
                'fromExtension' => 'jpg',
                'toExtension' => 'png',
                'expectSuccess' => true,
            ],
            'supported by txt converter' => [
                'fromExtension' => 'txt',
                'toExtension' => 'png',
                'expectSuccess' => true,
            ],
            'unsupported 1' => [
                'fromExtension' => 'jpg',
                'toExtension' => 'txt',
                'expectSuccess' => false,
            ],
            'unsupported 2' => [
                'fromExtension' => 'txt',
                'toExtension' => 'doc',
                'expectSuccess' => false,
            ],
        ];
    }

    #[DataProvider('provideConvert')]
    public function testConvert(string $fromExtension, string $toExtension, bool $expectSuccess): void
    {
        $manager = new FileConverterManager();

        if (!$expectSuccess) {
            $this->expectException(FileConverterException::class);
            $this->expectExceptionMessage("No file converter available to convert '$fromExtension' to '$toExtension'.");
        }

        $origFile = $this->originalFiles[$fromExtension];
        $origName = $origFile->Filename;
        $result = $manager->convert($origFile, $toExtension);

        $this->assertSame('converted.' . $toExtension, $result->Filename);
        $this->assertSame($origName, $origFile->Filename);
    }
}
