<?php

namespace SilverStripe\Assets\Dev;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Assets\File;
use SilverStripe\ORM\Connect\Query;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLUpdate;

/**
 * SS4 and its File Migration Task changes the way in which files are stored in the assets folder, with files placed
 * in subfolders named with partial hashmap values of the file version. This build task goes through the HTML content
 * fields looking for instances of image links, and corrects the link path to what it should be, with an image shortcode.
 */
class ImageTagsToShortcodeTask extends BuildTask
{
    private static $segment = 'ImageTagsToShortcode';

    protected $title = 'Rewrite image tags to shortcodes';

    protected $description = "Rewrites image tags to shortcodes in any HTMLText field";

    /**
     * @param \SilverStripe\Control\HTTPRequest $request
     * @throws \ReflectionException
     */
    public function run($request)
    {
        set_time_limit(60000);

        foreach (ClassInfo::getFieldMap(DataObject::class, false,'HTMLText') as $class => $tables) {
            foreach ($tables as $table => $fields) {
                foreach ($fields as $field) {
                    $records = DB::query("SELECT \"ID\", \"$field\" FROM \"{$table}\" WHERE \"$field\" LIKE '%<img %'");
                    $this->rewriteFieldForRecords($records, $table, $field);
                }
            }
        }

        echo 'DONE';
    }

    /**
     * Takes a set of query results and updates image urls within a page's content.
     * @param Query $records
     * @param string $updateTable
     * @param string $field
     */
    public function rewriteFieldForRecords(Query $records, string $updateTable, string $field)
    {
        foreach ($records as $row) {
            $content = $row[$field];
            $newContent = static::getNewContent($content);
            if ($content == $newContent) {
                continue;
            }

            $updateSQL = SQLUpdate::create($updateTable)->addWhere(['"ID"' => $row['ID']]);
            $updateSQL->addAssignments(["\"$field\"" => $newContent]);
            $updateSQL->execute();
            DB::alteration_message('Updated page with ID ' . $row['ID'], 'changed');
        }
    }

    /**
     * @param string $content
     * @return string
     */
    private static function getNewContent(string $content)
    {
        $imgTags = static::getImgTagsInContent($content);
        foreach ($imgTags as $imgTag) {
            if ($newImgTag = static::getNewImgTag($imgTag)) {
                $content = str_replace($imgTag, $newImgTag, $content);
            }
        }

        return $content;
    }

    /**
     * Get all img tags within some page content and return as array.
     * @param $content
     * @return array
     */
    public static function getImgTagsInContent($content)
    {
        $imgTags = [];

        preg_match_all('/<img.*?src\s*=.*?>/', $content, $matches, PREG_SET_ORDER);
        if ($matches) {
            foreach($matches as $match) {
                $imgTags []= $match[0];
            }
        }

        return $imgTags;
    }

    /**
     * @param string $imgTag
     * @return array
     */
    private static function getTupleFromImgTag(string $imgTag)
    {
        preg_match( '/src="([^"]*)"/i', $imgTag, $matches);
        $src = $matches[1];
        $fileName = basename($src);
        $filePath = dirname($src);

        return [
            $src,
            $fileName,
            $filePath
        ];
    }

    /**
     * Gets the value of a given attribute from a tag.
     * @param $imgTag
     * @param $imageAttribute
     * @return null
     */
    public static function getImageAttribute($imgTag, $imageAttribute)
    {
        $imagePropertyValue = null;
        $needle = $imageAttribute . '="';
        if (strpos($imgTag, $needle)) {
            $imagePropertyValue = explode('"', explode($needle, $imgTag)[1])[0];
        }

        return $imagePropertyValue;
    }

    /**
     * Extracts an array of attributes from an image tag.
     * @param $imgTag
     * @return array
     */
    public static function getImageAttributes($imgTag)
    {
        $attributes = [
            'title' => static::getImageAttribute($imgTag, 'title'),
            'height' => static::getImageAttribute($imgTag, 'height'),
            'width' => static::getImageAttribute($imgTag,'width'),
            'class' => static::getImageAttribute($imgTag, 'class'),
            'alt' => static::getImageAttribute ($imgTag,'alt'),
        ];

        return $attributes;
    }

    /**
     * @param string $imgTag
     * @return string|null Returns the new img tag or null if the img tag does not need to be rewritten
     */
    private static function getNewImgTag(string $imgTag)
    {
        list($src, $fileName, $filePath) = static::getTupleFromImgTag($imgTag);

        // Search for a File object containing this filename
        /** @var File $file */
        if ($file = File::get()->filter('FileFilename:PartialMatch', $fileName)->first()) {
            // Create new image source based on a file hashcode
            $newSrc = $filePath . DIRECTORY_SEPARATOR;

            // Only include the filehash subfolder in the path if not a resampled image
            if (strpos($src, '_resampled') == false) {
                $hashShort = substr($file->FileHash, 0, 10);
                // Make sure we don't include any old hash
                if ($hashShort == basename($filePath)) {
                    $newSrc = dirname($filePath) . DIRECTORY_SEPARATOR . $hashShort . DIRECTORY_SEPARATOR;
                } else {
                    $newSrc .= $hashShort . DIRECTORY_SEPARATOR;
                }
            }

            $newSrc .= $fileName;

            /* Build up the image shortcode
               e.g. [image src="/assets/Uploads/f92c6af6c8/Screen-Shot-2019-03-18-at-10.04.18-AM.png"
                id="134" width="3342" height="1808" class="leftAlone ss-htmleditorfield-file image"
                title="Screen Shot 2019 03 18 at 10.04.18 AM"] */
            $imageProperties = static::getImageAttributes($imgTag);
            $imgTagNew = '[image src="'.$newSrc.'"'
                . (isset($imageProperties['width']) ? ' width="' . $imageProperties['width'] . '"' : '')
                . (isset($imageProperties['height']) ? ' height="' . $imageProperties['height'] . '"' : '')
                . (isset($imageProperties['class']) ? ' class="' . $imageProperties['class'] . '"' : '')
                . (isset($imageProperties['alt']) ? ' alt="' . $imageProperties['alt'] . '"' : '')
                . (isset($imageProperties['title']) ? ' title="' . $imageProperties['title'] . '"' : '')
                . ' id="'. $file->ID . '"]';

            return $imgTagNew;
        } else {
            echo 'Link with no file found:' . $src .'<br>';
        }

        return null;
    }
}
