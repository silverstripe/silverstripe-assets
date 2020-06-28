<?php

namespace App\Report;

use SilverStripe\CMS\Model\RedirectorPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\SS_List;
use SilverStripe\Reports\Report;

class LegacyAssetsReport extends Report
{

    private const HTML_FIELDS = [
        [
            'Page',
            'Abstract',
        ],
        [
            'VacancyLandingPage',
            'VancancyFooter',
        ],
        [
            'PageSection',
            'Content',
        ],
        [
            'SiteTree',
            'Content',
        ],
    ];

    private const QUERIES = [
        // image tags which failed to migrate
        'SELECT "ID" FROM "%1$s" WHERE "%2$s" REGEXP \'<img[^>]* src="assets/\'',
        // resampled assets references (debug only as this is a subset of the image tags)
//        'SELECT "ID" FROM "%1$s" WHERE "%2$s" LIKE \'%%_resampled/ResizedImage%%\''
//        . ' AND "%2$s" REGEXP \'(_resampled\/ResizedImage)+[a-zA-Z0-9]+[-]{1}\''
//        . ' AND "%2$s" NOT REGEXP \'(_resampled\/ResizedImage)+[a-zA-Z0-9]+[\/]{1}\'',
        // image shortcodes with empty attribute (debug only as this is a subset of the image tags)
//        'SELECT "ID" FROM "%1$s" WHERE "%2$s" REGEXP \'[[]image[^]]*=""[^]]*[]]\'',
    ];

    public function title(): string
    {
        return 'Legacy asset references';
    }

    public function sourceRecords( // phpcs:ignore SlevomatCodingStandard.TypeHints
        array $params = [],
        $sort = null,
        $limit = null
    ): SS_List {
        $ids = [];

        foreach (self::HTML_FIELDS as $fieldData) {
            $table = array_shift($fieldData);
            $field = array_shift($fieldData);

            foreach (self::QUERIES as $sql) {
                $query = sprintf($sql, $table, $field);
                $results = DB::query($query);

                while ($result = $results->next()) {
                    $id = (int) $result['ID'];

                    if (!$id) {
                        continue;
                    }

                    $ids[$id] = $id;
                }
            }
        }

        $list = DataObject::get(SiteTree::class, null, $sort, null, $limit);

        if (count($ids) === 0) {
            return $list->byIDs([0]);
        }

        if ($sort === null) {
            $list = $list->sort('ID', 'ASC');
        }

        return $list
            ->exclude([
                'ClassName' => RedirectorPage::class,
            ])
            ->filter([
                'ID' => array_values($ids),
            ]);
    }

    public function columns(): array
    {
        return [
            'ID' => [
                'title' => 'ID',
                'formatting' => '$ID',
            ],
            'Type' => [
                'title' => 'Type',
                'link' => static function ($value, $item) {
                    return ClassInfo::shortName($item);
                },
            ],
            'State' => [
                'title' => 'State',
                'link' => static function ($value, $item) {
                    /** @var $item SiteTree */
                    if (!$item instanceof SiteTree) {
                        return 'Not published';
                    }

                    return $item->isPublished()
                        ? 'Published'
                        : 'Not published';
                },
            ],
            'Title' => [
                'title' => 'Page title',
                'formatting' =>
                    '<a href=\"admin/pages/edit/show/$ID\" title=\"Edit page\" target=\"_blank\">$value</a>',
            ],
        ];
    }
}
