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
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Assets\File;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\Deprecation;
use SilverStripe\ORM\Connect\DatabaseException;
use SilverStripe\ORM\Connect\Query;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectSchema;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\ORM\Queries\SQLUpdate;
use SilverStripe\Versioned\Versioned;

/**
 * SS4 and its File Migration Task changes the way in which files are stored in the assets folder, with files placed
 * in subfolders named with partial hashmap values of the file version. This helper class goes through the HTML content
 * fields looking for instances of image links, and corrects the link path to what it should be, with an image shortcode.
 * @deprecated 1.12.0 Will be removed without equivalent functionality to replace it
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
        Deprecation::notice('1.12.0', 'Will be removed without equivalent functionality to replace it', Deprecation::SCOPE_CLASS);
        $flysystemAssetStore = singleton(AssetStore::class);
        if (!($flysystemAssetStore instanceof FlysystemAssetStore)) {
            throw new InvalidArgumentException("FlysystemAssetStore missing");
        }
        $this->flysystemAssetStore = $flysystemAssetStore;

        $this->baseClass = $baseClass ?: DataObject::class;
        $this->includeBaseClass = $includeBaseClass;

        $this->validTagsPattern = implode('|', array_keys(static::VALID_TAGS ?? []));
        $this->validAttributesPattern = implode('|', static::VALID_ATTRIBUTES);
    }

    /**
     * @throws \ReflectionException
     */
    public function run()
    {
        Environment::increaseTimeLimitTo();

        $classes = $this->getFieldMap($this->baseClass, $this->includeBaseClass, [
            'HTMLText',
            'HTMLVarchar'
        ]);

        $tableList = DB::table_list();

        foreach ($classes as $class => $tables) {
            /** @var DataObject $singleton */
            $singleton = singleton($class);
            $hasVersions =
                class_exists(Versioned::class) &&
                $singleton->hasExtension(Versioned::class) &&
                $singleton->hasStages();

            foreach ($tables as $table => $fields) {
                foreach ($fields as $field) {

                    /** @var DBField $dbField */
                    $dbField = DataObject::singleton($class)->dbObject($field);
                    if ($dbField &&
                        $dbField->hasMethod('getProcessShortcodes') &&
                        !$dbField->getProcessShortcodes()) {
                        continue;
                    }

                    if (!isset($tableList[strtolower($table)])) {
                        // When running unit test some tables won't be created. We'll just skip those.
                        continue;
                    }


                    // Update table
                    $this->updateTable($table, $field);

                    // Update live
                    if ($hasVersions) {
                        $this->updateTable($table.'_Live', $field);
                    }
                }
            }
        }
    }

    private function updateTable($table, $field)
    {
        $sqlSelect = SQLSelect::create(['"ID"', "\"$field\""], "\"$table\"");
        $whereAnys = [];
        foreach (array_keys(static::VALID_TAGS ?? []) as $tag) {
            $whereAnys[]= "\"$table\".\"$field\" LIKE '%<$tag%'";
            $whereAnys[]= "\"$table\".\"$field\" LIKE '%[$tag%'";
        }
        $sqlSelect->addWhereAny($whereAnys);
        $records = $sqlSelect->execute();
        $this->rewriteFieldForRecords($records, $table, $field);
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

            $updateSQL = SQLUpdate::create("\"$updateTable\"")->addWhere(['"ID"' => $row['ID']]);
            $updateSQL->addAssignments(["\"$field\"" => $newContent]);
            $updateSQL->execute();
            if ($this->logger) {
                $this->logger->info("Updated record ID {$row['ID']} on table $updateTable");
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
                $content = str_replace($tag ?? '', $newTag ?? '', $content ?? '');
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

        $regex = '/<('.$this->validTagsPattern.')\s[^>]*?('.$this->validAttributesPattern.')\s*=.*?>/i';
        preg_match_all($regex ?? '', $content ?? '', $matches, PREG_SET_ORDER);
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
            '/.*(?:<|\[)(?<tagType>%s).*(?<attribute>%s)=(?:"|\')(?<src>[^"]*)(?:"|\')/i',
            $this->validTagsPattern,
            $this->validAttributesPattern
        );
        preg_match($pattern ?? '', $tag ?? '', $matches);
        return $matches;
    }

    /**
     * @param string $src
     * @return null|ParsedFileID
     * @throws \League\Flysystem\Exception
     */
    private function getParsedFileIDFromSrc($src)
    {
        $fileIDHelperResolutionStrategy = FileIDHelperResolutionStrategy::create();
        $fileIDHelperResolutionStrategy->setResolutionFileIDHelpers([
            $hashFileIdHelper = new HashFileIDHelper(),
            new LegacyFileIDHelper(),
            $defaultFileIDHelper = new NaturalFileIDHelper(),
        ]);
        $fileIDHelperResolutionStrategy->setDefaultFileIDHelper($defaultFileIDHelper);

        // set fileID to the filepath relative to assets dir
        $pattern = sprintf('#^/?(%s/?)?#', ASSETS_DIR);
        $fileID = preg_replace($pattern ?? '', '', $src ?? '');

        // Our file reference might be using invalid file name that will have been cleaned up by the migration task.
        $fileID = $defaultFileIDHelper->cleanFilename($fileID);

        // Try resolving with public filesystem first
        $filesystem = $this->flysystemAssetStore->getPublicFilesystem();
        $parsedFileId = $fileIDHelperResolutionStrategy->resolveFileID($fileID, $filesystem);
        if (!$parsedFileId) {
            // Try resolving with protected filesystem
            $filesystem = $this->flysystemAssetStore->getProtectedFilesystem();
            $parsedFileId = $fileIDHelperResolutionStrategy->resolveFileID($fileID, $filesystem);
        }

        if (!$parsedFileId) {
            return null;
        }

        $parsedFileId = $parsedFileId->setVariant("");
        $newFileId = $hashFileIdHelper->buildFileID($parsedFileId->getFilename(), $parsedFileId->getHash());
        return $parsedFileId
            ->setVariant("")
            ->setFileID($newFileId);
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
        $tagType = strtolower($tuple['tagType'] ?? '');
        $src = $tuple['src'] ?: $tuple['href'];

        // Search for a File object containing this filename
        $parsedFileID = $this->getParsedFileIDFromSrc($src);
        if (!$parsedFileID) {
            return null;
        }

        /** @var File $file */
        $file = File::get()->filter('FileFilename', $parsedFileID->getFilename())->first();
        if (!$file && class_exists(Versioned::class)) {
            $file = Versioned::withVersionedMode(function () use ($parsedFileID) {
                Versioned::set_stage(Versioned::LIVE);
                return File::get()->filter('FileFilename', $parsedFileID->getFilename())->first();
            });
        }

        if ($parsedFileID && $file) {
            if ($tagType == 'img') {
                $find = [
                    '/(<|\[)img/i',
                    '/src\s*=\s*(?:"|\').*?(?:"|\')/i',
                    '/href\s*=\s*(?:"|\').*?(?:"|\')/i',
                    '/id\s*=\s*(?:"|\').*?(?:"|\')/i',
                    '/\s*(\/?>|\])/',
                ];
                $replace = [
                    '[image',
                    "src=\"/".ASSETS_DIR."/{$parsedFileID->getFileID()}\"",
                    "href=\"/".ASSETS_DIR."/{$parsedFileID->getFileID()}\"",
                    "",
                    " id=\"{$file->ID}\"]",
                ];
                $shortcode = preg_replace($find ?? '', $replace ?? '', $tag ?? '');
            } elseif ($tagType == 'a') {
                $attribute = 'href';
                $find = "/$attribute\s*=\s*(?:\"|').*?(?:\"|')/i";
                $replace = "$attribute=\"[file_link,id={$file->ID}]\"";
                $shortcode = preg_replace($find ?? '', $replace ?? '', $tag ?? '');
            } else {
                return null;
            }
            return $shortcode;
        }
        return null;
    }

    /**
     * Returns an array of the fields available for the provided class and its sub-classes as follows:
     * <code>
     * [
     *  'ClassName' => [
     *      'TableName' => [
     *          'FieldName',
     *          'FieldName2',
     *      ],
     *      'TableName2' => [
     *          'FieldName3',
     *      ],
     *    ],
     * ]
     * </code>
     *
     * @param string|object $baseClass
     * @param bool $includeBaseClass Whether to include fields in the base class or not
     * @param string|string[] $fieldNames The field to get mappings for, for example 'HTMLText'. Can also be an array.
     * Subclasses of DBFields must be defined explicitely.
     * @return array An array of fields that derivec from $baseClass.
     * @throws \ReflectionException
     */
    private function getFieldMap($baseClass, $includeBaseClass, $fieldNames)
    {
        $mapping = [];
        // Normalise $fieldNames to a string array
        if (is_string($fieldNames)) {
            $fieldNames = [$fieldNames];
        }
        // Add the FQNS classnames of the DBFields
        $extraFieldNames = [];
        foreach ($fieldNames as $fieldName) {
            $dbField = Injector::inst()->get($fieldName);
            if ($dbField && $dbField instanceof DBField) {
                $extraFieldNames[] = get_class($dbField);
            }
        }
        $fieldNames = array_merge($fieldNames, $extraFieldNames);
        foreach (ClassInfo::subclassesFor($baseClass, $includeBaseClass) as $class) {
            /** @var DataObjectSchema $schema */
            $schema = singleton($class)->getSchema();
            /** @var DataObject $fields */
            $fields = $schema->fieldSpecs($class, DataObjectSchema::DB_ONLY|DataObjectSchema::UNINHERITED);

            foreach ($fields as $field => $type) {
                $type = preg_replace('/\(.*\)$/', '', $type ?? '');
                if (in_array($type, $fieldNames ?? [])) {
                    $table = $schema->tableForField($class, $field);
                    if (!isset($mapping[$class])) {
                        $mapping[$class] = [];
                    }
                    if (!isset($mapping[$class][$table])) {
                        $mapping[$class][$table] = [];
                    }
                    if (!in_array($field, $mapping[$class][$table] ?? [])) {
                        $mapping[$class][$table][] = $field;
                    }
                }
            }
        }
        return $mapping;
    }
}
