<?php

namespace SilverStripe\Assets\Dev\Tasks;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use SilverStripe\Assets\FilenameParsing\FileIDHelperResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\HashFileIDHelper;
use SilverStripe\Assets\FilenameParsing\LegacyFileIDHelper;
use SilverStripe\Assets\FilenameParsing\NaturalFileIDHelper;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Assets\File;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\Connect\DatabaseException;
use SilverStripe\ORM\Connect\Query;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\ORM\Queries\SQLUpdate;
use SilverStripe\Versioned\Versioned;

/**
 * SS4 and its File Migration Task changes the way in which files are stored in the assets folder, with files placed
 * in subfolders named with partial hashmap values of the file version. This helper class goes through the HTML content
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

    /** @var FlysystemAssetStore */
    private $flysystemAssetStore;

    /** @var string */
    private $baseClass;

    /** @var bool */
    private $includeBaseClass;

    private static $dependencies = [
        'logger' => '%$' . LoggerInterface::class,
    ];

    /** @var LoggerInterface|null */
    private $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * TagsToShortcodeHelper constructor.
     * @param string $baseClass The base class that will be used to look up HTMLText fields
     * @param bool $includeBaseClass Whether to include the base class' HTMLText fields or not
     */
    public function __construct($baseClass = null, $includeBaseClass = false)
    {
        $flysystemAssetStore = singleton(AssetStore::class);
        if (!($flysystemAssetStore instanceof FlysystemAssetStore)) {
            throw new InvalidArgumentException("FlysystemAssetStore missing");
        }
        $this->flysystemAssetStore = $flysystemAssetStore;

        $this->baseClass = $baseClass ?: DataObject::class;
        $this->includeBaseClass = $includeBaseClass;

        $this->validTagsPattern = implode('|', array_keys(static::VALID_TAGS));
        $this->validAttributesPattern = implode('|', static::VALID_ATTRIBUTES);
    }

    /**
     * @throws \ReflectionException
     */
    public function run()
    {
        Environment::increaseTimeLimitTo();

        $classes = DataObjectSchema::getFieldMap($this->baseClass, $this->includeBaseClass, ['HTMLText', 'HTMLVarchar']);
        foreach ($classes as $class => $tables) {
            foreach ($tables as $table => $fields) {
                foreach ($fields as $field) {
                    try {
                        $sqlSelect = SQLSelect::create(
                            ['ID', $field],
                            $table
                        );
                        $whereAnys = [];
                        foreach (array_keys(static::VALID_TAGS) as $tag) {
                            $whereAnys[]= "\"$table\".\"$field\" LIKE '%<$tag%'";
                            $whereAnys[]= "\"$table\".\"$field\" LIKE '%[$tag%'";
                        }
                        $sqlSelect->addWhereAny($whereAnys);
                        $records = $sqlSelect->execute();
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
    private function rewriteFieldForRecords(Query $records, $updateTable, $field)
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
            if ($this->logger) {
                $this->logger->info("Updated page with ID {$row['ID']}");
            }
        }
    }

    /**
     * @param string $content
     * @return string
     */
    public function getNewContent($content)
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
     * Get all tags within some page content and return them as an array.
     * @param string $content The page content
     * @return array An array of tags found
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
    private function getTagTuple($tag)
    {
        $pattern = sprintf(
            '/.*(?:<|\[)(?<tagType>%s).*(?<attribute>%s)="(?<src>[^"]*)"/i',
            $this->validTagsPattern,
            $this->validAttributesPattern
        );
        preg_match($pattern, $tag, $matches);
        return $matches;
    }

    /**
     * @param string $src
     * @return null|ParsedFileID
     * @throws \League\Flysystem\Exception
     */
    private function getParsedFileIDFromSrc($src)
    {
        $fileIDHelperResolutionStrategy = new FileIDHelperResolutionStrategy();
        $fileIDHelperResolutionStrategy->setResolutionFileIDHelpers([
            new HashFileIDHelper(),
            new LegacyFileIDHelper(),
            $defaultFileIDHelper = new NaturalFileIDHelper(),
        ]);
        $fileIDHelperResolutionStrategy->setDefaultFileIDHelper($defaultFileIDHelper);

        // set fileID to the filepath relative to assets dir
//        $pattern = '/^\\/?' . ASSETS_DIR . '?\\/?/';
        $pattern = '/^\\' . DIRECTORY_SEPARATOR . '?' . ASSETS_DIR . '?\\' . DIRECTORY_SEPARATOR . '?/';
        $fileID = preg_replace($pattern, '', $src);

        // Try resolving with public filesystem first
        $filesystem = $this->flysystemAssetStore->getPublicFilesystem();
        $parsedFileId = $fileIDHelperResolutionStrategy->softResolveFileID($fileID, $filesystem);
        if (!$parsedFileId) {
            // Try resolving with protected filesystem
            $filesystem = $this->flysystemAssetStore->getProtectedFilesystem();
            $parsedFileId = $fileIDHelperResolutionStrategy->softResolveFileID($fileID, $filesystem);
        }
        return $parsedFileId;
    }

    /**
     * @param string $tag
     * @return string|null Returns the new tag or null if the tag does not need to be rewritten
     */
    private function getNewTag($tag)
    {
        $tuple = $this->getTagTuple($tag);
        if (!isset($tuple['tagType']) || !isset($tuple['src'])) {
            return null;
        }
        $tagType = $tuple['tagType'];
        $src = $tuple['src'] ?: $tuple['href'];

        // Search for a File object containing this filename
        $parsedFileID = $this->getParsedFileIDFromSrc($src);
        if (!$parsedFileID) {
            return null;
        }

        /** @var File $file */
        $file = File::get()->filter('FileFilename', $parsedFileID->getFilename())->first();
        if (!$file) {
            $file = Versioned::withVersionedMode(function () use ($parsedFileID) {
                Versioned::set_stage(Versioned::LIVE);
                return File::get()->filter('FileFilename', $parsedFileID->getFilename())->first();
            });
        }

        if ($parsedFileID && $file) {
            if ($tagType == 'img') {
                $find = [
                    '/(<|\[)img/',
                    '/src\s*=\s*".*?"/',
                    '/href\s*=\s*".*?"/',
                    '/(>|\])/',
                ];
                $replace = [
                    '[image',
                    "src=\"/".ASSETS_DIR."/{$parsedFileID->getFileID()}\"",
                    "href=\"/".ASSETS_DIR."/{$parsedFileID->getFileID()}\"",
                    " id={$file->ID}]",
                ];
                $shortcode = preg_replace($find, $replace, $tag);
            } elseif ($tagType == 'a') {
                $attribute = 'href';
                $find= "/$attribute\s*=\s*\".*?\"/";
                $replace = "$attribute=\"[file_link id={$file->ID}]\"";
                $shortcode = preg_replace($find, $replace, $tag);
            } else {
                return null;
            }
            return $shortcode;


        }
        return null;
    }
}
