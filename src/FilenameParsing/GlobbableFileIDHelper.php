<?php

namespace SilverStripe\Assets\FilenameParsing;

/**
 * @deprecated 3.1.0 Will be part of the SilverStripe\Assets\FilenameParsing\FileIDHelper interface in a future major release.
 */
interface GlobbableFileIDHelper
{
    /**
     * Get the glob for this file for use in GlobContentLister::listContentsByGlob()
     */
    public function getVariantGlob(string $folder, ParsedFileID $parsedFileID): string;
}
