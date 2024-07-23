<?php

namespace SilverStripe\Assets\Conversion;

use Intervention\Image\Exceptions\RuntimeException;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image_Backend;
use SilverStripe\Assets\InterventionBackend;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injector;

/**
 * File converter powered by the Intervention Image library.
 * Supports any file conversions that Intervention Image can perform.
 */
class InterventionImageFileConverter implements FileConverter
{
    use Extensible;

    public function supportsConversion(string $fromExtension, string $toExtension, array $options = []): bool
    {
        $unsupportedOptions = $this->validateOptions($options);
        if (!empty($unsupportedOptions)) {
            return false;
        }
        // This converter requires intervention image as the image backend
        $backend = Injector::inst()->get(Image_Backend::class);
        if (!is_a($backend, InterventionBackend::class)) {
            return false;
        }
        /** @var InterventionBackend $backend */
        $driver = $backend->getImageManager()->driver();
        return $driver->supports($fromExtension) && $driver->supports($toExtension);
    }

    public function convert(DBFile|File $from, string $toExtension, array $options = []): DBFile
    {
        // Do some basic validation up front for things we know aren't supported
        $problems = $this->validateOptions($options);
        if (!empty($problems)) {
            throw new FileConverterException('Invalid options provided: ' . implode(', ', $problems));
        }
        $originalBackend = $from->getImageBackend();
        if (!is_a($originalBackend, InterventionBackend::class)) {
            $actualClass = $originalBackend ? get_class($originalBackend) : 'null';
            throw new FileConverterException("ImageBackend must be an instance of InterventionBackend. Got $actualClass");
        }
        /** @var InterventionBackend $originalBackend */
        $driver = $originalBackend->getImageManager()->driver();
        if (!$driver->supports($toExtension)) {
            throw new FileConverterException("Convertion to format '$toExtension' is not suported.");
        }

        $quality = $options['quality'] ?? null;
        // Clone the backend if we're changing quality to avoid affecting other manipulations to that original image
        $backend = $quality === null ? $originalBackend : clone $originalBackend;
        // Pass through to invervention image to do the conversion for us.
        try {
            $result = $from->manipulateExtension(
                $toExtension,
                function (AssetStore $store, string $filename, string $hash, string $variant) use ($backend, $quality) {
                    if ($quality !== null) {
                        $backend->setQuality($quality);
                    }
                    $config = ['conflict' => AssetStore::CONFLICT_USE_EXISTING];
                    $tuple = $backend->writeToStore($store, $filename, $hash, $variant, $config);
                    return [$tuple, $backend];
                }
            );
        } catch (RuntimeException $e) {
            throw new FileConverterException('Failed to convert: ' . $e->getMessage(), $e->getCode(), $e);
        }
        // This is very unlikely but the API for `manipulateExtension()` allows for it
        if ($result === null) {
            throw new FileConverterException('File conversion resulted in null. Check whether original file actually exists.');
        }
        return $result;
    }

    private function validateOptions(array $options): array
    {
        $problems = [];
        foreach ($options as $key => $value) {
            if ($key !== 'quality') {
                $problems[] = "unexpected option '$key'";
                continue;
            }
            if (!is_int($value)) {
                $problems[] = "quality value must be an integer";
            }
        }
        return $problems;
    }
}
