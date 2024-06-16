<?php

namespace SilverStripe\Assets\FilenameParsing;

use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Path;

abstract class AbstractFileIDHelper implements FileIDHelper
{
    use Injectable;

    /**
     * A variant type for encoding a variant filename with a different extension than the original.
     */
    public const EXTENSION_REWRITE_VARIANT = 'ExtRewrite';

    /**
     * Use the original file's extension
     */
    protected const EXTENSION_ORIGINAL = 0;

    /**
     * Use the variant file's extension
     */
    protected const EXTENSION_VARIANT = 1;

    public function buildFileID($filename, $hash = null, $variant = null, $cleanfilename = true)
    {
        if ($filename instanceof ParsedFileID) {
            $hash =  $filename->getHash();
            $variant =  $filename->getVariant();
            $filename =  $filename->getFilename();
        }

        $this->validateFileParts($filename, $hash, $variant);

        // Since we use double underscore to delimit variants, eradicate them from filename
        if ($cleanfilename) {
            $filename = $this->cleanFilename($filename);
        }

        if ($variant) {
            $filename = $this->swapExtension($filename, $variant, AbstractFileIDHelper::EXTENSION_VARIANT);
        }

        $name = basename($filename ?? '');

        // Split extension
        $extension = null;
        if (($pos = strpos($name ?? '', '.')) !== false) {
            $extension = substr($name ?? '', $pos ?? 0);
            $name = substr($name ?? '', 0, $pos);
        }

        $fileID = $this->getFileIDBase($name, $filename, $hash, $variant);

        // Add directory
        $dirname = ltrim(dirname($filename ?? ''), '.');
        if ($dirname) {
            $fileID = $dirname . '/' . $fileID;
        }

        // Add variant
        if ($variant) {
            $fileID .= '__' . $variant;
        }

        // Add extension
        if ($extension) {
            $fileID .= $extension;
        }

        return $fileID;
    }

    /**
     * Get the original file's filename with the extension rewritten to be the same as either the original
     * or the variant extension.
     *
     * @param string $filename Original filename without variant
     * @param int $extIndex One of AbstractFileIDHelper::EXTENSION_ORIGINAL or AbstractFileIDHelper::EXTENSION_VARIANT
     */
    protected function swapExtension(string $filename, string $variant, int $extIndex): string
    {
        // If there's no variant at all, we can rewrite the filenmane
        if (empty($variant)) {
            return $filename;
        }

        // Split variant string in variant list
        $subVariants = explode('_', $variant);

        // Split our filename into a filename and extension part
        $fileParts = pathinfo($filename);
        if (!isset($fileParts['filename']) || !isset($fileParts['extension'])) {
            return $filename;
        }
        $dirname = $fileParts['dirname'] !== '.' ? $fileParts['dirname'] : '';
        $filenameWithoutExtension = Path::join($dirname, $fileParts['filename']);
        $extension = $fileParts['extension'];

        // Loop our variant list until we find our special file extension swap variant
        // Reverse the list first so the variant extension we find is the last extension rewrite variant in a chain
        $extSwapVariant = preg_quote(AbstractFileIDHelper::EXTENSION_REWRITE_VARIANT, '/');
        foreach (array_reverse($subVariants) as $subVariant) {
            if (preg_match("/^$extSwapVariant(?<base64>.+)$/", $subVariant, $matches)) {
                // This array always contain 2 values: The original extension at index 0 and the variant extension at index 1
                /** @var array $extensionData */
                $extensionData = Convert::base64url_decode($matches['base64']);
                $extension = $extensionData[$extIndex];
                break;
            }
        }

        return $filenameWithoutExtension . '.' . $extension;
    }

    public function cleanFilename($filename)
    {
        // Swap backslash for forward slash
        $filename = str_replace('\\', '/', $filename ?? '');

        // Since we use double underscore to delimit variants, eradicate them from filename
        return preg_replace('/_{2,}/', '_', $filename ?? '');
    }

    public function lookForVariantRecursive(): bool
    {
        return false;
    }

    abstract protected function getFileIDBase($shortFilename, $fullFilename, $hash, $variant): string;

    abstract protected function validateFileParts($filename, $hash, $variant): void;
}
