<?php

namespace SilverStripe\Assets\FilenameParsing;

use Exception;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Dev\Deprecation;

/**
 * Parsed SS3 style legacy asset URLs. e.g.: `Uploads/_resampled/ResizedImageWzYwLDgwXQ/sam.jpg`
 *
 * SS3 legacy paths are no longer used in SilverStripe 4, but a way to parse them is needed for redirecting old SS3
 * urls.
 *
 * @deprecated 1.12.0 Legacy file names will not be supported in Silverstripe CMS 5
 */
class LegacyFileIDHelper implements FileIDHelper
{
    use Injectable;
    use Configurable;

    /**
     * List of SilverStripe 3 image method names that can appear in variants. Prior to SilverStripe 3.3, variants were
     * encoded in the filename with dashes. e.g.: `_resampled/FitW10-sam.jpg` rather than `_resampled/FitW10/sam.jpg`.
     * @config
     */
    private static $ss3_image_variant_methods = [
        'fit',
        'fill',
        'pad',
        'scalewidth',
        'scaleheight',
        'setratiosize',
        'setwidth',
        'setheight',
        'setsize',
        'cmsthumbnail',
        'assetlibrarypreview',
        'assetlibrarythumbnail',
        'stripthumbnail',
        'paddedimage',
        'formattedimage',
        'resizedimage',
        'croppedimage',
        'cropheight',
    ];

    /** @var bool */
    private $failNewerVariant;

    /**
     * @param bool $failNewerVariant Whether FileID mapping to newer SS4 formats should be parsed.
     */
    public function __construct($failNewerVariant = true)
    {
        Deprecation::notice('1.12.0', 'Legacy file names will not be supported in Silverstripe CMS 5', Deprecation::SCOPE_CLASS);
        $this->failNewerVariant = $failNewerVariant;
    }


    public function buildFileID($filename, $hash = null, $variant = null, $cleanfilename = true)
    {
        if ($filename instanceof ParsedFileID) {
            $variant =  $filename->getVariant();
            $filename =  $filename->getFilename();
        }

        $name = basename($filename ?? '');

        // Split extension
        $extension = null;
        if (($pos = strpos($name ?? '', '.')) !== false) {
            $extension = substr($name ?? '', $pos ?? 0);
            $name = substr($name ?? '', 0, $pos);
        }

        $fileID = $name;

        // Add directory
        $dirname = ltrim(dirname($filename ?? ''), '.');

        // Add variant
        if ($variant) {
            $fileID = '_resampled/' . str_replace('_', '/', $variant ?? '') . '/' . $fileID;
        }

        if ($dirname) {
            $fileID = $dirname . '/' . $fileID;
        }

        // Add extension
        if ($extension) {
            $fileID .= $extension;
        }

        return $fileID;
    }

    public function cleanFilename($filename)
    {
        // Swap backslash for forward slash
        $filename = str_replace('\\', '/', $filename ?? '');

        // There's not really any relevant cleaning rule for legacy. It's not important any way because we won't be
        // generating legacy URLs, aside from maybe for testing.
        return $filename;
    }

    /**
     * @note LegacyFileIDHelper is meant to fail when parsing newer format fileIDs with a variant e.g.:
     * `subfolder/abcdef7890/sam__resizeXYZ.jpg`. When parsing fileIDs without a variant, it should return the same
     * results as natural paths. This behavior can be disabled by setting `failNewerVariant` to false on the
     * constructor.
     */
    public function parseFileID($fileID)
    {
        if ($this->failNewerVariant) {
            $pattern = '#^(?<folder>([^/]+/)*?)(_resampled/(?<variant>([^.]+))/)?((?<basename>((?<!__)[^/.])+))(?<extension>(\..+)*)$#i';
        } else {
            $pattern = '#^(?<folder>([^/]+/)*?)(_resampled/(?<variant>([^.]+))/)?((?<basename>([^/.])+))(?<extension>(\..+)*)$#i';
        }

        // not a valid file (or not a part of the filesystem)
        if (!preg_match($pattern ?? '', $fileID ?? '', $matches)) {
            return null;
        }

        // Can't have a resampled folder without a variant
        if (empty($matches['variant']) && strpos($fileID ?? '', '_resampled') !== false) {
            return $this->parseSilverStripe30VariantFileID($fileID);
        }

        $filename = $matches['folder'] . $matches['basename'] . $matches['extension'];
        return new ParsedFileID(
            $filename,
            '',
            isset($matches['variant']) ? str_replace('/', '_', $matches['variant']) : '',
            $fileID
        );
    }

    /**
     * Try to parse a FileID as a pre-SS33 variant. From SS3.0 to SS3.2 the variants were prefixed in the file name,
     * rather than encoded into folders.
     * @param string $fileID Variant file ID. Variantless FileID should have been parsed by `parseFileID`.
     * @return ParsedFileID|null
     */
    private function parseSilverStripe30VariantFileID($fileID)
    {
        $ss3Methods = $this->getImageVariantMethods();
        $variantPartialRegex = implode('|', $ss3Methods);

        if ($this->failNewerVariant) {
            $pattern = '#^(?<folder>([^/]+/)*?)(_resampled/(?<variant>((((' . $variantPartialRegex . ')[^.-]+))-)+))?((?<basename>((?<!__)[^/.])+))(?<extension>(\..+)*)$#i';
        } else {
            $pattern = '#^(?<folder>([^/]+/)*?)(_resampled/(?<variant>((((' . $variantPartialRegex . ')[^.-]+))-)+))?((?<basename>([^/.])+))(?<extension>(\..+)*)$#i';
        }

        // not a valid file (or not a part of the filesystem)
        if (!preg_match($pattern ?? '', $fileID ?? '', $matches)) {
            return null;
        }

        // Our SS3 variant can be confused with regular filenames, let's minimise the risk of this by making
        // sure all our variants use a valid SS3 variant expression
        $variant = trim($matches['variant'] ?? '', '-');
        $possibleVariants = explode('-', $variant ?? '');
        $validVariants = [];
        $validVariantRegex = '#^(' . $variantPartialRegex . ')(?<base64>(.+))$#i';

        // Loop through the possible variants until we find an invalid one
        while ($possible = array_shift($possibleVariants)) {
            // Find the base64 encoded argument attached to the image method
            if (preg_match($validVariantRegex ?? '', $possible ?? '', $variantMatches)) {
                try {
                    // Our base 64 encoded string always decodes to a string representation of php array
                    // So we're assuming it always starts with a `[` and ends with a `]`
                    $base64Str = $variantMatches['base64'];
                    $argumentString = base64_decode($base64Str ?? '');
                    if ($argumentString && preg_match('/^\[.*\]$/', $argumentString ?? '')) {
                        $validVariants[] = $possible;
                        continue;
                    }
                } catch (Exception $ex) {
                    // If we get an error in the regex or in the base64 decode, assume our possible variant is invalid.
                }
            }
            array_unshift($possibleVariants, $possible);
            break;
        }


        // Can't have a resampled folder without a variant
        if (empty($validVariants)) {
            return null;
        }

        // Reconcatenate our variants
        $variant = implode('_', $validVariants);

        // Our invalid variants are part of the filename
        $invalidVariant = $possibleVariants ? implode('-', $possibleVariants) . '-' : '';
        $filename = $matches['folder'] . $invalidVariant . $matches['basename'] . $matches['extension'];

        return new ParsedFileID($filename, '', $variant, $fileID);
    }


    /**
     * Get a list of possible variant methods.
     * @return string[]
     */
    private function getImageVariantMethods()
    {
        $variantMethods = self::config()->get('ss3_image_variant_methods');
        // Sort the variant methods by descending order of string length.
        // This is important because the regex will match the string in order of appearance.
        // e.g. `paddedimageW10` could be confused for `pad` with a base64 string of `dedimageW10`
        usort($variantMethods, function ($a, $b) {
            return strlen($b ?? '') - strlen($a ?? '');
        });

        return $variantMethods;
    }

    public function isVariantOf($fileID, ParsedFileID $original)
    {
        $variant = $this->parseFileID($fileID);
        return $variant && $variant->getFilename() == $original->getFilename();
    }

    public function lookForVariantIn(ParsedFileID $parsedFileID)
    {
        $folder = dirname($parsedFileID->getFilename() ?? '');
        if ($folder == '.') {
            $folder = '';
        } else {
            $folder .= '/';
        }
        return $folder . '_resampled';
    }

    public function lookForVariantRecursive(): bool
    {
        return true;
    }
}
