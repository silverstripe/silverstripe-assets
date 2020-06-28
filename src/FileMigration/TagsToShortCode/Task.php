<?php

namespace App\FileMigration\TagsToShortCode;

use App\Queue\Factory;
use ReflectionException;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Environment;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\Queries\SQLSelect;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Versioned\Versioned;

class Task extends Factory\Task
{

    private const CHUNK_SIZE = 10;

    /**
     * @var string
     */
    private static $segment = 'tags-to-short-code-migration-task';

    public function getDescription(): string
    {
        return 'Generate tags to short code migration job';
    }

    /**
     * @param HTTPRequest $request
     * @throws ValidationException
     * @throws ReflectionException
     */
    public function run($request): void // phpcs:ignore SlevomatCodingStandard.TypeHints
    {
        $fields = $this->getTableFields();
        $items = [];

        foreach ($fields as $data) {
            $table = array_shift($data);
            $field = array_shift($data);

            $query = SQLSelect::create('"ID"', sprintf('"%s"', $table), [], ['ID' => 'ASC']);
            $results = $query->execute();

            while ($result = $results->next()) {
                $id = (int) $result['ID'];
                $items[] = [
                    $table,
                    $field,
                    $id,
                ];
            }
        }

        $this->queueJobsFromData($request, $items, Job::class, self::CHUNK_SIZE);
    }

    /**
     * Code taken from @see TagsToShortcodeHelper::run()
     *
     * @return array
     * @throws ReflectionException
     */
    private function getTableFields(): array
    {
        $helper = Helper::create();
        Environment::increaseTimeLimitTo();

        $classes = $helper->getFieldMap(DataObject::class, false, [
            'HTMLText',
            'HTMLVarchar',
        ]);

        $versioned = singleton(Versioned::class);
        $tableList = DB::table_list();
        $items = [];

        foreach ($classes as $class => $tables) {
            /** @var DataObject|Versioned $singleton */
            $singleton = singleton($class);
            $hasVersions =
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

                    $items[] = [
                        $table,
                        $field,
                    ];

                    if (!$hasVersions) {
                        continue;
                    }

                    $items[] = [
                        $versioned->stageTable($table, Versioned::LIVE),
                        $field,
                    ];
                }
            }
        }

        return $items;
    }
}
