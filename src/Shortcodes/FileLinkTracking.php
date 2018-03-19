<?php

namespace SilverStripe\Assets\Shortcodes;

use DOMElement;
use SilverStripe\Assets\File;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\Parsers\HTMLValue;

/**
 * Adds tracking of links in any HTMLText fields which reference SiteTree or File items.
 *
 * Attaching this to any DataObject will a relation which links to File items
 * referenced in any HTMLText fields, and a boolean to indicate if there are any broken file links. Call
 * augmentSyncFileLinkTracking to update those fields with any changes to those fields.
 *
 * Note that since both SiteTree and File are versioned, LinkTracking and ImageTracking will
 * only be enabled for the Stage record.
 *
 * @property DataObject|FileLinkTracking $owner
 * @property bool $HasBrokenFile True if any file or image is broken
 * @method ManyManyList|File[] ImageTracking() List of files linked on this dataobject
 */
class FileLinkTracking extends DataExtension
{
    /**
     * @var FileLinkTrackingParser
     */
    protected $fileParser;

    /**
     * Inject parser for each page
     *
     * @var array
     * @config
     */
    private static $dependencies = [
        'FileParser' => '%$' . FileLinkTrackingParser::class,
    ];

    private static $db = [
        'HasBrokenFile' => 'Boolean',
    ];

    private static $owns = [
        'ImageTracking',
    ];

    private static $many_many = [
        'ImageTracking' => [
            'through' => FileLink::class,
            'from' => 'Parent',
            'to' => 'Linked',
        ],
    ];

    /**
     * FileParser for link tracking
     *
     * @return FileLinkTrackingParser
     */
    public function getFileParser()
    {
        return $this->fileParser;
    }

    /**
     * @param FileLinkTrackingParser $parser
     * @return $this
     */
    public function setFileParser(FileLinkTrackingParser $parser = null)
    {
        $this->fileParser = $parser;
        return $this;
    }

    public function onBeforeWrite()
    {
        // Trigger link tracking
        // Note: SiteTreeLinkTracking::onBeforeWrite() has a check to
        // prevent this being triggered multiple times on a single write.
        $this->owner->syncLinkTracking();
    }

    /**
     * Public method to call when triggering symlink extension. Can be called externally,
     * or overridden by class implementations.
     *
     * {@see FileLinkTracking::augmentSyncLinkTracking}
     */
    public function syncLinkTracking()
    {
        $this->owner->extend('augmentSyncLinkTracking');
    }

    /**
     * Find HTMLText fields on {@link owner} to scrape for links that need tracking
     */
    public function augmentSyncLinkTracking()
    {
        // Skip live tracking
        if (Versioned::get_stage() == Versioned::LIVE) {
            return;
        }

        // Reset boolean broken flag. This will be flagged back by trackLinksInField().
        $this->owner->HasBrokenFile = false;

        // Build a list of HTMLText fields, merging all linked pages together.
        $allFields = DataObject::getSchema()->fieldSpecs($this->owner);
        $linkedPages = [];
        foreach ($allFields as $field => $fieldSpec) {
            $fieldObj = $this->owner->dbObject($field);
            if ($fieldObj instanceof DBHTMLText) {
                // Merge links in this field with global list.
                $linksInField = $this->trackLinksInField($field);
                $linkedPages = array_merge($linkedPages, $linksInField);
            }
        }

        // Update the "ImageTracking" many_many.
        $this->owner->ImageTracking()->setByIDList($linkedPages);
    }

    /**
     * Scrape the content of a field to detect anly links to local SiteTree pages or files
     *
     * @param string $fieldName The name of the field on {@link @owner} to scrape
     * @return int[] Array of page IDs found (associative array)
     */
    public function trackLinksInField($fieldName)
    {
        // Pull down current field content
        $record = $this->owner;
        $htmlValue = HTMLValue::create($record->$fieldName);

        // Process all links
        $linkedFiles = [];
        $links = $this->fileParser->process($htmlValue);
        foreach ($links as $link) {
            // Toggle highlight class to element
            if ($link['DOMReference']) {
                $this->toggleElementClass($link['DOMReference'], 'ss-broken', $link['Broken']);
            }

            // Flag broken
            if ($link['Broken']) {
                $record->HasBrokenFile = true;
            }

            // Collect page ids
            if ($link['Target'] && in_array($link['Type'], ['file', 'image'])) {
                $fileID = (int)$link['Target'];
                $linkedFiles[$fileID] = $fileID;
            }
        }

        // Update any changed content
        $record->$fieldName = $htmlValue->getContent();
        return $linkedFiles;
    }

    /**
     * Add the given css class to the DOM element.
     *
     * @param DOMElement $domReference Element to modify.
     * @param string $class Class name to toggle.
     * @param bool $toggle On or off.
     */
    protected function toggleElementClass(DOMElement $domReference, $class, $toggle)
    {
        // Get all existing classes.
        $classes = array_filter(explode(' ', trim($domReference->getAttribute('class'))));

        // Add or remove the broken class from the link, depending on the link status.
        if ($toggle) {
            $classes = array_unique(array_merge($classes, [$class]));
        } else {
            $classes = array_diff($classes, [$class]);
        }

        if (!empty($classes)) {
            $domReference->setAttribute('class', implode(' ', $classes));
        } else {
            $domReference->removeAttribute('class');
        }
    }
}
