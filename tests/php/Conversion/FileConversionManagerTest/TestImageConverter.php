<?php

namespace SilverStripe\Assets\Tests\Conversion\FileConverterManagerTest;

use SilverStripe\Assets\Conversion\FileConverter;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Dev\TestOnly;

class TestImageConverter implements FileConverter, TestOnly
{
    public function supportsConversion(string $fromExtension, string $toExtension, array $options = []): bool
    {
        $formats = File::get_category_extensions(['image/supported']);
        return in_array($fromExtension, $formats) && in_array($toExtension, $formats);
    }

    public function convert(DBFile $from, string $toExtension, array $options = []): DBFile
    {
        $result = clone $from;
        $result->Filename = 'converted.' . $toExtension;
        return $result;
    }
}
