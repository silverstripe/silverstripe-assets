<?php

namespace SilverStripe\Assets\Dev\Tasks;

use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Assets\File;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\Connect\DatabaseException;
use SilverStripe\ORM\Connect\Query;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLUpdate;

/**
 * SS4 and its File Migration Task changes the way in which files are stored in the assets folder, with files placed
 * in subfolders named with partial hashmap values of the file version. This build task goes through the HTML content
 * fields looking for instances of image links, and corrects the link path to what it should be, with an image shortcode.
 */
class TagsToShortcodeHelper
{
    use Injectable;
    use Configurable;

    const VALID_TAGS = [
        'img' => 'image',
        'a' => 'file_link'
    ];

    const VALID_ATTRIBUTES = [
        'src',
        'href'
    ];

    /** @var string */
    private $validTagsPattern;

    /** @var string */
    private $validAttributesPattern;

    /**
     * @throws \ReflectionException
     */
    public function run()
    {
        Environment::increaseTimeLimitTo();

        $this->validTagsPattern = implode('|', array_keys(static::VALID_TAGS));
        $this->validAttributesPattern = implode('|', static::VALID_ATTRIBUTES);

        $classes = ClassInfo::getFieldMap(DataObject::class, false, 'HTMLText');
        foreach ($classes as $class => $tables) {
            foreach ($tables as $table => $fields) {
                foreach ($fields as $field) {
                    try {
                        $records = DB::query("SELECT \"ID\", \"$field\" FROM \"{$table}\" WHERE \"$field\" RLIKE '<(".$this->validTagsPattern.")'");
                        $this->rewriteFieldForRecords($records, $table, $field);
                    } catch (DatabaseException $exception) {
                    }
                }
            }
        }
    }

    /**
     * Takes a set of query results and updates image urls within a page's content.
     * @param Query $records
     * @param string $updateTable
     * @param string $field
     */
    private function rewriteFieldForRecords(Query $records, string $updateTable, string $field)
    {
        foreach ($records as $row) {
            $content = $row[$field];
            $newContent = $this->getNewContent($content);
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
    private function getNewContent(string $content)
    {
        $tags = $this->getTagsInContent($content);
        foreach ($tags as $tag) {
            if ($newTag = $this->getNewTag($tag)) {
                $content = str_replace($tag, $newTag, $content);
            }
        }

        return $content;
    }

    /**
     * Get all tags within some page content and return as array.
     * @param $content
     * @return array
     */
    private function getTagsInContent($content)
    {
        $resultTags = [];

        preg_match_all('/<('.$this->validTagsPattern.').*?('.$this->validAttributesPattern.')\s*=.*?>/', $content, $matches, PREG_SET_ORDER);
        if ($matches) {
            foreach ($matches as $match) {
                $resultTags []= $match[0];
            }
        }

        return $resultTags;
    }

    /**
     * @param string $tag
     * @return array
     */
    private function getTupleFromTag(string $tag)
    {
        preg_match('/.*<('.$this->validTagsPattern.').*('.$this->validAttributesPattern.')="([^"]*)"/i', $tag, $matches);
        $tagType = $matches[1];
        $src = $matches[3];
        $fileName = basename($src);
        $filePath = dirname($src);

        return [
            $tagType,
            $src,
            $fileName,
            $filePath
        ];
    }

    /**
     * Gets the value of a given attribute from a tag.
     * @param $tag
     * @param $attribute
     * @return null
     */
    private static function getAttribute($tag, $attribute)
    {
        $propertyValue = null;
        $needle = $attribute . '="';
        if (strpos($tag, $needle)) {
            $propertyValue = explode('"', explode($needle, $tag)[1])[0];
        }

        return $propertyValue;
    }

    /**
     * Extracts an array of attributes from a tag.
     * @param string $tag
     * @param string $tagType
     * @param $id
     * @param $newSrc
     * @return array
     */
    private static function getAttributes(string $tag, string $tagType, $newSrc)
    {
        $attributes = [];

        if ($tagType == 'img') {
            $imgAttributes = [
                'src' => $newSrc,
            ];

            if ($title = static::getAttribute($tag, 'title')) {
                $imgAttributes['title'] = $title;
            }
            if ($width = static::getAttribute($tag, 'width')) {
                $imgAttributes['width'] = $width;
            }
            if ($height = static::getAttribute($tag, 'height')) {
                $imgAttributes['height'] = $height;
            }
            if ($class = static::getAttribute($tag, 'class')) {
                $imgAttributes['class'] = $class;
            }
            if ($alt = static::getAttribute($tag, 'alt')) {
                $imgAttributes['alt'] = $alt;
            }

            $attributes = array_merge($attributes, $imgAttributes);
        }

        return $attributes;
    }

    /**
     * @param string $tagType
     * @return string
     */
    private static function getShortCodeTypeFromTagType(string $tagType)
    {
        return static::VALID_TAGS[$tagType];
    }

    /**
     * @param string $tag
     * @return string|null Returns the new tag or null if the tag does not need to be rewritten
     */
    private function getNewTag(string $tag)
    {
        list($tagType, $src, $fileName, $filePath) = $this->getTupleFromTag($tag);

        // Search for a File object containing this filename
        /** @var File $file */
        if ($file = File::get()->filter('FileFilename:PartialMatch', $fileName)->first()) {
            // Create new source based on a file hashcode
            $newSrc = $filePath . DIRECTORY_SEPARATOR;

            // Only include the filehash subfolder in the path if not resampled
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

            // Build up the shortcode
            $properties = static::getAttributes($tag, $tagType, $newSrc);
            $shortCodeType = static::getShortCodeTypeFromTagType($tagType);

            $attributesString = "";
            foreach ($properties as $key => $value) {
                $attributesString .= "$key=\"$value\" ";
            }
            $attributesString .= "id={$file->ID}";
            $tagNew = "[$shortCodeType $attributesString]";

            // only replace the href, not the whole tag
            if ($tagType == 'a') {
                $tagNew = preg_replace('/(.*<.*(?:'.$this->validAttributesPattern.')=")([^"]*)(")/i', '$1'.$tagNew.'$3', $tag);
            }

            return $tagNew;
        }

        return null;
    }
}
