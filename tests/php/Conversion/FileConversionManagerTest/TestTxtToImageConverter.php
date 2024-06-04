<?php

namespace SilverStripe\Assets\Tests\Conversion\FileConverterManagerTest;

use SilverStripe\Assets\Conversion\FileConverter;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Dev\TestOnly;

class TestTxtToImageConverter implements FileConverter, TestOnly
{
    public function supportsConversion(string $fromExtension, string $toExtension, array $options = []): bool
    {
        $formats = File::get_category_extensions(['image/supported']);
        return strtolower($fromExtension) === 'txt' && in_array(strtolower($toExtension), $formats);
    }

    public function convert(DBFile|File $from, string $toExtension, array $options = []): DBFile
    {
        $result = ($from instanceof File) ? clone $from->File : clone $from;
        $result->Filename = 'converted.' . $toExtension;
        return $result;
    }
}
