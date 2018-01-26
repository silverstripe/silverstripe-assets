<?php

namespace SilverStripe\Assets\Extension;

use SilverStripe\Assets\File;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Security\Group;
use SilverStripe\Security\Permission;

class SiteConfigPermissionsExtension extends DataExtension
{
    private static $db = [
        "CanViewFilesType" => "Enum('Anyone, LoggedInUsers, OnlyTheseUsers', 'Anyone')",
        "CanEditFilesType" => "Enum('LoggedInUsers, OnlyTheseUsers', 'LoggedInUsers')",
    ];

    private static $many_many = [
        "FileViewerGroups" => Group::class,
        "FileEditorGroups" => Group::class,
    ];

    private static $defaults = [
        "CanViewFilesType" => "Anyone",
        "CanEditFilesType" => "LoggedInUsers",
    ];

    public function updateCMSFields(FieldList $fields)
    {
        $mapFn = function ($groups = []) {
            $map = [];
            foreach ($groups as $group) {
                // Listboxfield values are escaped, use ASCII char instead of &raquo;
                $map[$group->ID] = $group->getBreadcrumbs(' > ');
            }
            asort($map);
            return $map;
        };
        $groupsMap = $mapFn(Group::get());
        $viewAllGroupsMap = $mapFn(Permission::get_groups_by_permission([File::VIEW_ALL, 'ADMIN']));
        $editAllGroupsMap = $mapFn(Permission::get_groups_by_permission([File::EDIT_ALL, 'ADMIN']));

        $fileViewersOptionsField = new OptionsetField(
            "CanViewFilesType",
            _t(self::class . '.VIEWHEADER', "Who can view files on this site?")
        );
        $fileViewerGroupsField = ListboxField::create(
            "FileViewerGroups",
            _t(File::class . '.VIEWERGROUPS', "File Viewer Groups")
        )
            ->setSource($groupsMap)
            ->setAttribute(
                'data-placeholder',
                _t(File::class . '.GroupPlaceholder', 'Click to select group')
            );
        $fileEditorsOptionsField = new OptionsetField(
            "CanEditFilesType",
            _t(self::class . '.EDITHEADER', "Who can edit files on this site?")
        );
        $fileEditorGroupsField = ListboxField::create(
            "FileEditorGroups",
            _t(File::class . '.EDITORGROUPS', "File Editor Groups")
        )
            ->setSource($groupsMap)
            ->setAttribute(
                'data-placeholder',
                _t(File::class . '.GroupPlaceholder', 'Click to select group')
            );

        $fileViewersOptionsSource = [];
        $fileViewersOptionsSource["Anyone"] = _t(File::class . '.ACCESSANYONE', "Anyone");
        $fileViewersOptionsSource["LoggedInUsers"] = _t(
            File::class . '.ACCESSLOGGEDIN',
            "Logged-in users"
        );
        $fileViewersOptionsSource["OnlyTheseUsers"] = _t(
            File::class . '.ACCESSONLYTHESE',
            "Only these groups (choose from list)"
        );
        $fileViewersOptionsField->setSource($fileViewersOptionsSource);

        if ($viewAllGroupsMap) {
            $fileViewerGroupsField->setDescription(_t(
                File::class . '.VIEWER_GROUPS_FIELD_DESC',
                'Groups with global view permissions: {groupList}',
                ['groupList' => implode(', ', array_values($viewAllGroupsMap))]
            ));
        }

        if ($editAllGroupsMap) {
            $fileEditorGroupsField->setDescription(_t(
                File::class . '.EDITOR_GROUPS_FIELD_DESC',
                'Groups with global edit permissions: {groupList}',
                ['groupList' => implode(', ', array_values($editAllGroupsMap))]
            ));
        }

        $editorsOptionsSource = [];
        $editorsOptionsSource["LoggedInUsers"] = _t(
            File::class . '.EDITANYONE',
            "Anyone who can log-in to the CMS"
        );
        $editorsOptionsSource["OnlyTheseUsers"] = _t(
            File::class . '.EDITONLYTHESE',
            "Only these groups (choose from list)"
        );
        $fileEditorsOptionsField->setSource($editorsOptionsSource);


        if (!Permission::check('EDIT_SITECONFIG')) {
            $fields->makeFieldReadonly($fileViewersOptionsField);
            $fields->makeFieldReadonly($fileViewerGroupsField);
            $fields->makeFieldReadonly($fileEditorsOptionsField);
            $fields->makeFieldReadonly($fileEditorGroupsField);
        }

        $fields->addFieldsToTab('Root.Access', [
            HeaderField::create('FileHeader', 'Global file access permissions'),
            $fileViewersOptionsField,
            $fileViewerGroupsField,
            $fileEditorsOptionsField,
            $fileEditorGroupsField
        ]);
    }
}
