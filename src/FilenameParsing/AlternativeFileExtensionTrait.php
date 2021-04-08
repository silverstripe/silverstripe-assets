<?php

namespace SilverStripe\Assets\FilenameParsing;

use SilverStripe\Core\Convert;

/**
 * This trait can be applied to a `FileIDHelper` imlementation to add the baility to encode alternative file extension.
 *
 * This allows the creation of file variant with different extension to the original file.
 */
trait AlternativeFileExtensionTrait
{

    /**
     * Rewrite a filename with a different extension
     * @param string $filename
     * @param string $variant
     * @return string
     */
    public function rewriteVariantExtension(string $filename, string $variant): string
    {
        return $this->swapExtension($filename, $variant, 1);
    }

    /**
     * Rewrite a filename to use the original extension for the provided variant.
     * @param string $filename
     * @param string $variant
     * @return string
     */
    public function restoreOriginalExtension(string $filename, string $variant): string
    {
        return $this->swapExtension($filename, $variant, 0);
    }

    /**
     * Construct the original or alternative filname extension for the given filename and variant string.
     * @param string $filename Original filename without variant
     * @param string $variant Full variant list
     * @param int $extIndex Wether we want the original extension (0) or the new extension (1)
     * @return string
     */
    private function swapExtension(
        string $filename,
        string $variant,
        int $extIndex
    ): string {
        // If there's no variant at all, we can rewrite the filenmane
        if (empty($variant)) {
            return $filename;
        }

        // Split variant string in variant list
        $subVariants = explode('_', $variant);

        // Split our filename into a filename and extension part
        if (!preg_match('/(.+)\.([a-z\d]+)$/i', $filename, $matches)) {
            return $filename;
        }
        [$_, $filenameWitoutExtension, $extension] = $matches;

        // Loop our variant list until we find our special file extension swap variant
        foreach ($subVariants as $subVariant) {
            $extSwapVariant = FileIDHelper::EXTENSION_REWRITE_VARIANT;
            if (preg_match("/^$extSwapVariant(.+)$/", $subVariant, $matches)) {
                [$_, $base64] = $matches;

                /**
                 * This array always contain 2 values: The orignial extension and the new extension
                 * @var array $extensionData
                 */
                $extensionData = Convert::base64url_decode($base64);
                $extension = $extensionData[$extIndex];
            }
        }

        return $filenameWitoutExtension . '.' . $extension;
    }
}
