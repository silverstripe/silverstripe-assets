<?php

namespace SilverStripe\Assets\FilenameParsing;

use SilverStripe\Core\Convert;

trait AlternativeFileExtensionTrait
{

    public function rewriteVariantExtension(string $filename, string $variant): string
    {
        return $this->swapExtension($filename, $variant, 1);
    }

    public function restoreOriginalExtension(string $filename, string $variant): string
    {
        return $this->swapExtension($filename, $variant, 0);
    }

    private function swapExtension(
        string $filename,
        string $variant,
        int $extIndex
    ): string
    {
        if (empty($variant)) {
            return $filename;
        }

        $subVariants = explode('_', $variant);

        if (!preg_match('/(.+)\.([a-z\d]+)$/i', $filename, $matches)) {
            return $filename;
        }

        [$_, $filenameWitoutExtension, $extension] = $matches;

        foreach ($subVariants as $subVariant) {
            if (preg_match("/^extRewrite(.+)$/", $subVariant, $matches)) {
                [$_, $base64] = $matches;
                $extensionData = Convert::base64url_decode($base64);
                $extension = $extensionData[$extIndex];
            }
        }

        return $filenameWitoutExtension . '.' . $extension;

    }
}
