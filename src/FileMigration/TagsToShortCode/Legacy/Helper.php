<?php

namespace App\FileMigration\TagsToShortCode\Legacy;

use SilverStripe\Assets\FilenameParsing\LegacyFileIDHelper;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;

/**
 * Class Helper
 *
 * Custom functionality which implements SS 3.0 assets legacy format migration which is not covered out of the box
 *
 * @package App\FileMigration\TagsToShortCode\Legacy
 */
class Helper extends LegacyFileIDHelper
{

    /**
     * Override for @see LegacyFileIDHelper::parseLegacyFormat()
     *
     * @param string $fileID
     * @param string $variant
     * @param string $folder
     * @param string $filename
     * @param string $extension
     * @return ParsedFileID|null
     */
    protected function parseLegacyFormat(// phpcs:ignore SlevomatCodingStandard.TypeHints
        $fileID,
        $variant,
        $folder,
        $filename,
        $extension
    ) {
        $variant = trim($variant);

        if (!$variant || !$folder || !$filename || !$extension) {
            return null;
        }

        // extract first variant, the rest will be treated as a part of the filename
        $segments = explode('-', $variant);
        $segments = array_diff($segments, ['']);
        $realVariant = array_shift($segments) . '-';
        $prefix = str_replace($realVariant, '', $variant);
        $filename = $folder . $prefix . $filename . $extension;

        return new ParsedFileID($filename, '', '', $fileID);
    }
}
