<?php

namespace SilverStripe\Assets\Conversion;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injector;

/**
 * This class holds a list of available file converters which it uses to convert files from one format to another.
 */
class FileConverterManager
{
    use Configurable;

    /**
     * An array of classes or injector service names for
     * classes that implement the FileConverter interface.
     */
    private static array $converters = [];

    /**
     * Convert the file to the given format using the first available converter that can perform the conversion.
     *
     * @param string $toExtension The file extension you want to convert to - e.g. "webp".
     * @param array $options Any options defined for this converter which should apply to the conversion.
     * Note that if a converter supports the conversion generally but doesn't support these options, that converter will not be used.
     * @throws FileConverterException if the conversion failed or there were no converters available.
     */
    public function convert(DBFile|File $from, string $toExtension, array $options = []): DBFile
    {
        $fromExtension = $from->getExtension();
        foreach (static::config()->get('converters') as $converterClass) {
            /** @var FileConverter $converter */
            $converter = Injector::inst()->get($converterClass);
            if ($converter->supportsConversion($fromExtension, $toExtension, $options)) {
                return $converter->convert($from, $toExtension, $options);
            }
        }
        throw new FileConverterException("No file converter available to convert '$fromExtension' to '$toExtension'.");
    }
}
