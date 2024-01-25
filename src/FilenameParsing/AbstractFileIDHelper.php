<?php

namespace SilverStripe\Assets\FilenameParsing;

use SilverStripe\Core\Injector\Injectable;

abstract class AbstractFileIDHelper implements FileIDHelper
{
    use Injectable;

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
