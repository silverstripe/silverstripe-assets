<?php

namespace SilverStripe\Assets\Conversion;

use Imagick;
use Intervention\Image\Exception\ImageException;
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
        return $this->supportedByIntervention($fromExtension, $backend) && $this->supportedByIntervention($toExtension, $backend);
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
        if (!$this->supportedByIntervention($toExtension, $originalBackend)) {
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
        } catch (ImageException $e) {
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

    private function supportedByIntervention(string $format, InterventionBackend $backend): bool
    {
        $driver = $backend->getImageManager()->config['driver'] ?? null;

        // Return early for empty values - we obviously can't support that
        if ($format === '') {
            return false;
        }

        $format = strtolower($format);

        // If the driver is somehow not GD or Imagick, we have no way to know what it might support
        if ($driver !== 'gd' && $driver !== 'imagick') {
            $supported = false;
            $this->extend('updateSupportedByIntervention', $supported, $format, $driver);
            return $supported;
        }

        // GD and Imagick support different things.
        // This follows the logic in intervention's AbstractEncoder::process() method
        // and the various methods in the Encoder classes for GD and Imagick,
        // excluding checking for strings that were obviously mimetypes
        switch ($format) {
            case 'gif':
                // always supported
                return true;
            case 'png':
                // always supported
                return true;
            case 'jpg':
            case 'jpeg':
            case 'jfif':
                // always supported
                return true;
            case 'tif':
            case 'tiff':
                if ($driver === 'gd') {
                    false;
                }
                // always supported by imagick
                return true;
            case 'bmp':
            case 'ms-bmp':
            case 'x-bitmap':
            case 'x-bmp':
            case 'x-ms-bmp':
            case 'x-win-bitmap':
            case 'x-windows-bmp':
            case 'x-xbitmap':
                if ($driver === 'gd' && !function_exists('imagebmp')) {
                    return false;
                }
                // always supported by imagick
                return true;
            case 'ico':
                if ($driver === 'gd') {
                    return false;
                }
                // always supported by imagick
                return true;
            case 'psd':
                if ($driver === 'gd') {
                    return false;
                }
                // always supported by imagick
                return true;
            case 'webp':
                if ($driver === 'gd' && !function_exists('imagewebp')) {
                    return false;
                }
                if ($driver === 'imagick' && !Imagick::queryFormats('WEBP')) {
                    return false;
                }
                return true;
            case 'avif':
                if ($driver === 'gd' && !function_exists('imageavif')) {
                    return false;
                }
                if ($driver === 'imagick' && !Imagick::queryFormats('AVIF')) {
                    return false;
                }
                return true;
            case 'heic':
                if ($driver === 'gd') {
                    return false;
                }
                if ($driver === 'imagick' && !Imagick::queryFormats('HEIC')) {
                    return false;
                }
                return true;
            default:
                // Anything else is not supported
                return false;
        }
        // This should never be reached, but return false if it is
        return false;
    }
}
