<?php

namespace SilverStripe\Assets\Shortcodes;

use DOMElement;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\View\Parsers\HTMLValue;

/**
 * A helper object for extracting information about links.
 */
class FileLinkTrackingParser
{
    /**
     * Finds the links that are of interest for the link tracking automation. Checks for brokenness and attaches
     * extracted metadata so consumers can decide what to do with the DOM element (provided as DOMReference).
     *
     * @param HTMLValue $htmlValue Object to parse the links from.
     * @return array Associative array containing found links with the following field layout:
     *        Type: string, name of the link type
     *        Target: any, a reference to the target object, depends on the Type
     *        Anchor: string, anchor part of the link
     *        DOMReference: DOMElement, reference to the link to apply changes.
     *        Broken: boolean, a flag highlighting whether the link should be treated as broken.
     */
    public function process(HTMLValue $htmlValue)
    {
        $results = [];
        $links = $htmlValue->getElementsByTagName('a');
        if (!$links) {
            return $results;
        }

        /** @var DOMElement $link */
        foreach ($links as $link) {
            // Ensure href exists
            if (!$link->hasAttribute('href')) {
                continue;
            }
            $href = $link->getAttribute('href');
            if (empty($href)) {
                continue;
            }

            // Link to a file on this site.
            if (preg_match('/\[file_link([^\]]+)\bid=(["])?(?<id>\d+)\D/i', $href ?? '', $matches)) {
                $id = (int)$matches['id'];
                $results[] = [
                    'Type' => 'file',
                    'Target' => $id,
                    'DOMReference' => $link,
                    'Broken' => (int)File::get()->filter('ID', $id)->count() === 0
                ];
            }
        }

        // Find all [image ] shortcodes (will be inline, not inside attributes)
        if (preg_match_all('/\[image([^\]]+)\bid=(["])?(?<id>\d+)\D/i', $htmlValue->getContent() ?? '', $matches)) {
            foreach ($matches['id'] as $id) {
                $results[] = [
                    'Type' => 'image',
                    'Target' => (int)$id,
                    'DOMReference' => null,
                    'Broken' => (int)Image::get()->filter('ID', (int)$id)->count() === 0
                ];
            }
        }

        return $results;
    }
}
