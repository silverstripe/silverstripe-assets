<?php

namespace SilverStripe\Assets;

/**
 * Filter certain characters from file name, for nicer (more SEO-friendly) URLs
 * as well as better filesystem compatibility.
 *
 * Caution: Does not take care of full filename sanitization in regards to directory traversal etc.,
 * please use PHP's built-in basename() for this purpose.
 *
 * For file name filtering see {@link FileNameFilter}.
 */
class FolderNameFilter extends FileNameFilter
{
    private static $default_replacements = [
        '/\./' => '-', // replace dots with dashes
    ];
}
