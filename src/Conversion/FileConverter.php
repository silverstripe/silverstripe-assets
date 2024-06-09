<?php

namespace SilverStripe\Assets\Conversion;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\DBFile;

/**
 * Interface providing the public API for file converters, so that FileConverterManager
 * can find and use suitable converters.
 */
interface FileConverter
{
    /**
     * Checks whether this converter supports a conversion from one file type to another.
     *
     * @param string $fromExtension The file extension you want to convert from - e.g. "jpg".
     * @param string $toExtension The file extension you want to convert to - e.g. "webp".
     * @param array $options Any options defined for this converter which should apply to the conversion.
     * Note that if the converter supports this conversion generally but doesn't support these options, this method will return `false`.
     */
    public function supportsConversion(string $fromExtension, string $toExtension, array $options = []): bool;

    /**
     * Converts the given DBFile instance to another file type.
     *
     * @param string $toExtension The file extension you want to convert to - e.g. "webp".
     * @param array $options Any options defined for this converter which should apply to the conversion.
     * @throws FileConverterException if invalid options are passed, or the conversion is not supported or fails.
     */
    public function convert(DBFile|File $from, string $toExtension, array $options = []): DBFile;
}
