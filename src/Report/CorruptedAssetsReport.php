<?php

namespace App\Report;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Convert;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\SS_List;
use SilverStripe\Reports\Report;
use SilverStripe\Versioned\Versioned;

class CorruptedAssetsReport extends Report
{

    /**
     * @var string
     */
    protected $title = 'Corrupted assets';

    /**
     * @var string
     */
    protected $description = 'Find all assets which failed migration';

    public function sourceRecords(// phpcs:ignore SlevomatCodingStandard.TypeHints
        array $params = [],
        $sort = null,
        $limit = null
    ): SS_List {
        return Versioned::withVersionedMode(static function () use ($sort, $limit): SS_List {
            Versioned::set_stage(Versioned::DRAFT);

            $list = DataObject::get(File::class, null, $sort, null, $limit);

            return $list
                ->filter('FileHash', null)
                ->exclude('ClassName', Folder::class);
        });
    }

    public function columns(): array
    {
        return [
            'ID' => [
                'title' => 'ID',
                'formatting' => '$ID',
            ],
            'Title' => [
                'title' => 'Asset name',
                'link' => static function ($value, $item) {
                    /** @var File $item */
                    return sprintf(
                        '<a class="grid-field__link-block" href="%s" title="%s" target="_blank">%s</a>',
                        Convert::raw2att($item->CMSEditLink()),
                        Convert::raw2att($value),
                        Convert::raw2xml($value)
                    );
                },
            ],
        ];
    }
}
