<?php

namespace SilverStripe\Assets;

use SilverStripe\Assets\File;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\Debug;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

/**
 * Class ContentFileMigrationTask
 * @package SilverStripe\Assets
 */
class ContentFileMigrationTask extends BuildTask
{
    /**
     * @var string
     */
    protected $title = 'Content File Migration Task';

    /**
     * @var string
     */
    private static $segment = 'content-file-migration-task';

    /**
     * Mapping property for field updates
     *
     * @var array
     */
    private $mapping = [];

    /**
     * @param \SilverStripe\Control\HTTPRequest $request
     */
    public function run($request)
    {
        // Set max time and memory limit
        Environment::increaseTimeLimitTo();
        Environment::increaseMemoryLimitTo();

        $this->setMapping();
        $this->migrateContentFiles();
    }

    /**
     * Migrate file image references to the new SS4 shortcode
     */
    protected function migrateContentFiles()
    {
        foreach ($this->getMapping() as $class => $tables) {
            foreach ($this->yieldMulti($tables) as $table => $fields) {
                $selectString = 'ID';

                foreach ($fields as $field) {
                    $selectString = "$selectString, \"{$field}\"";
                }

                $queries[] = [
                    'records' => DB::query("SELECT {$selectString} FROM \"{$table}\""),
                    'table' => $table,
                ];

                if ($class::singleton()->hasExtension(Versioned::class)) {
                    $queries[] = [
                        'records' => DB::query("SELECT {$selectString} FROM \"{$table}_Versions\""),
                        'table' => "{$table}_Versions",
                    ];
                    $queries[] = [
                        'records' => DB::query("SELECT {$selectString} FROM \"{$table}_Live\""),
                        'table' => "{$table}_Live",
                    ];
                }

                foreach ($this->yieldSingle($queries) as $records) {
                    foreach ($this->yieldSingle($records['records']) as $record) {
                        foreach ($fields as $field) {
                            $this->updateFileReference($class, $records['table'], $record, $field);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param $items
     * @return \Generator
     */
    protected function yieldMulti($items)
    {
        foreach ($items as $key => $val) {
            yield $key => $val;
        }
    }

    /**
     * @param $items
     * @return \Generator
     */
    protected function yieldSingle($items)
    {
        foreach ($items as $item) {
            yield $item;
        }
    }

    /**
     * Yield all subclasses of DataObject
     *
     * @return \Generator
     */
    protected function getClasses()
    {
        $classes = ClassInfo::subclassesFor(DataObject::class);
        unset($classes[strtolower(DataObject::class)]);

        foreach ($classes as $class) {
            yield $class;
        }
    }

    /**
     * Set mapping array
     *
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
     *
     * @param $class
     */
    protected function setMapping()
    {
        foreach ($this->getClasses() as $class) {
            foreach ($this->getDBFields($class) as $field => $type) {
                if ($type == 'HTMLText') {
                    $table = $this->getFieldTable($class, $field);
                    if (!isset($this->mapping[$class])) {
                        $this->mapping[$class] = [];
                    }
                    if (!isset($This->mapping[$class][$table])) {
                        $this->mapping[$class][$table] = [];
                    }
                    if (!in_array($field, $this->mapping[$class][$table])) {
                        $this->mapping[$class][$table][] = $field;
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Get the array of Class, Table and Field mapping for updating
     *
     * @return array
     */
    protected function getMapping()
    {
        if (empty($this->mapping)) {
            $this->setMapping();
        }

        return $this->mapping;
    }

    /**
     * Yield database fields for a given class
     *
     * @param $class
     * @return \Generator
     */
    protected function getDBFields($class)
    {
        foreach ($class::singleton()->getSchema()->fieldSpecs($class) as $field => $type) {
            yield $field => $type;
        }
    }

    /**
     * Get the appropriate table to update via SQL as to not publish draft content from the SS3 site
     *
     * @param $class
     * @param $field
     * @return mixed
     */
    protected function getFieldTable($class, $field)
    {
        return $class::singleton()->getSchema()->tableForField($class, $field);
    }

    /**
     * Update records of based on ContentFileMigrationTask::mapping
     *
     * @param $class
     * @param $table
     * @param $record
     * @param $field
     */
    protected function updateFileReference($class, $table, $record, $field)
    {
        if (isset($record[$field])) {
            if (preg_match('/<img\s*(?:src\s*\=\s*[\'\"](.*?)[\'\"].*?\s*|\s*|\s*)+.*?>/sm', $record[$field], $matches)) {
                foreach ($matches as $match) {
                    preg_match('/src=".*"/', $match, $source);
                    if (!empty($source)) {
                        $path = substr($source[0], 5, strlen($source[0]) - 6);
                        $parts = explode('/', $path);
                        $file = File::get()->filter('FileFilename:PartialMatch', $parts[count($parts) - 1])->first();

                        $newValue = $this->getNewFieldValue($record, $field, $match, $file);
                        if ($newValue) {
                            try {
                                DB::prepared_query("UPDATE \"{$table}\" SET \"{$field}\" = ? WHERE ID = ?", [$newValue, $record['ID']]);
                                echo "{$table}: Updated image reference for: {$class} - {$record['ID']}\n";
                            } catch (\Exception $e) {
                                echo "{$table}: Failed updating {$class} - {$record['ID']}\n";
                            }
                        } else {
                            echo "{$table}: Couldn't update {$class} = {$record['ID']} - {$match}\n";
                        }
                    }
                }
            }
        }
    }

    /**
     * Replace image instances with new shortcode
     *
     * @param $record
     * @param $field
     * @param $match
     * @param $file
     * @return mixed
     */
    protected function getNewFieldValue($record, $field, $match, $file)
    {
        if ($file instanceof File) {
            $newPath = $file->getURL();

            $find = [
                '/<img/',
                '/src=".*"/',
                '/>/',
            ];

            $replace = [
                '[image',
                "src=\"{$newPath}\" id=\"$file->ID\"",
                ']',
            ];

            $newReference = preg_replace($find, $replace, $match);

            echo "New image referance {$newReference}\n";

            $newValue = str_replace($match, $newReference, $record[$field]);
            return $newValue;
        }

        return false;
    }
}
