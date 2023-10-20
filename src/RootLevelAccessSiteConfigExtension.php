<?php

namespace SilverStripe\Assets;

use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ListboxField;
use SilverStripe\Forms\OptionsetField;
use SilverStripe\ORM\DataExtension;
use SilverStripe\Security\Group;
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

class RootLevelAccessSiteConfigExtension extends DataExtension
{
    private static $db = [
        "RootAssetsCanViewType" => "Enum('Anyone, LoggedInUsers, OnlyTheseUsers, OnlyTheseMembers', 'Anyone')",
        "RootAssetsCanEditType" => "Enum('LoggedInUsers, OnlyTheseUsers, OnlyTheseMembers', 'OnlyTheseUsers')",
    ];

    private static $many_many = [
        "RootAssetsViewerGroups" => Group::class,
        "RootAssetsEditorGroups" => Group::class,
        "RootAssetsViewerMembers" => Member::class,
        "RootAssetsEditorMembers" => Member::class,
    ];

    private static $defaults = [
        "RootAssetsCanViewType" => "Anyone",
        "RootAssetsCanEditType" => "OnlyTheseUsers",
    ];

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();
        if ($this->getOwner()->RootAssetsEditorGroups()->count() < 1) {
            $groups = Permission::get_groups_by_permission(File::EDIT_ALL);
            foreach ($groups as $group) {
                $this->getOwner()->RootAssetsEditorGroups()->add($group);
            }
        }
    }

    public function updateCMSFields(FieldList $fields)
    {
        // Lots of logic borrowed from SiteConfig::getCMSFields()
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
        $membersMap = Member::get()->map('ID', 'Name');
        $editAllGroupsMap = $mapFn(Permission::get_groups_by_permission([File::EDIT_ALL, 'ADMIN']));

        $fields->addFieldsToTab(
            'Root.Access',
            [
                $viewersOptionsField = OptionsetField::create(
                    "RootAssetsCanViewType",
                    _t(self::class . '.ROOTASSETSVIEWHEADER', "Who can view files on this site?")
                ),
                $viewerGroupsField = ListboxField::create(
                    "RootAssetsViewerGroups",
                    _t('SilverStripe\\CMS\\Model\\SiteTree.ROOTASSETSVIEWERGROUPS', "File Viewer Groups")
                )
                ->setSource($groupsMap)
                ->setAttribute(
                    'data-placeholder',
                    _t('SilverStripe\\CMS\\Model\\SiteTree.GroupPlaceholder', 'Click to select group')
                ),
                $viewerMembersField = ListboxField::create(
                    "RootAssetsViewerMembers",
                    _t(__CLASS__.'.ROOTASSETSVIEWERMEMBERS', "Viewer Users"),
                    $membersMap,
                ),
                $editorsOptionsField = OptionsetField::create(
                    "RootAssetsCanEditType",
                    _t(self::class . '.ROOTASSETSEDITHEADER', "Who can edit files on this site?")
                ),
                $editorGroupsField = ListboxField::create(
                    "RootAssetsEditorGroups",
                    _t('SilverStripe\\CMS\\Model\\SiteTree.EDITORGROUPS', "File Editor Groups")
                )
                ->setSource($groupsMap)
                ->setAttribute(
                    'data-placeholder',
                    _t('SilverStripe\\CMS\\Model\\SiteTree.GroupPlaceholder', 'Click to select group')
                ),
                $editorMembersField = ListboxField::create(
                    "RootAssetsEditorMembers",
                    _t(__CLASS__.'.ROOTASSETSEDITORMEMBERS', "Editor Users"),
                    $membersMap
                )
            ]
        );

        $viewersOptionsSource = [];
        $viewersOptionsSource[InheritedPermissions::ANYONE] = _t('SilverStripe\\CMS\\Model\\SiteTree.ACCESSANYONE', "Anyone");
        $viewersOptionsSource[InheritedPermissions::LOGGED_IN_USERS] = _t(
            'SilverStripe\\CMS\\Model\\SiteTree.ACCESSLOGGEDIN',
            "Logged-in users"
        );
        $viewersOptionsSource[InheritedPermissions::ONLY_THESE_USERS] = _t(
            'SilverStripe\\CMS\\Model\\SiteTree.ACCESSONLYTHESE',
            "Only these groups (choose from list)"
        );
        $viewersOptionsSource[InheritedPermissions::ONLY_THESE_MEMBERS] = _t(
            'SilverStripe\\CMS\\Model\\SiteTree.ACCESSONLYMEMBERS',
            "Only these users (choose from list)"
        );
        $viewersOptionsField->setSource($viewersOptionsSource);
        $editorsOptionsSource = $viewersOptionsSource;
        unset($editorsOptionsSource[InheritedPermissions::ANYONE]);
        $editorsOptionsField->setSource($editorsOptionsSource);


        if ($editAllGroupsMap) {
            $viewerGroupsField->setDescription(_t(
                'SilverStripe\\CMS\\Model\\SiteTree.ROOT_ASSETS_VIEWER_GROUPS_FIELD_DESC',
                'Groups with global view permissions: {groupList}',
                ['groupList' => implode(', ', array_values($editAllGroupsMap))]
            ));
            $editorGroupsField->setDescription(_t(
                'SilverStripe\\CMS\\Model\\SiteTree.ROOT_ASSETS_EDITOR_GROUPS_FIELD_DESC',
                'Groups with global edit permissions: {groupList}',
                ['groupList' => implode(', ', array_values($editAllGroupsMap))]
            ));
        }

        if (!Permission::check(File::GRANT_ACCESS)) {
            $fields->makeFieldReadonly($viewersOptionsField);
            if ($this->getOwner()->RootAssetsCanViewType === InheritedPermissions::ONLY_THESE_USERS) {
                $fields->makeFieldReadonly($viewerGroupsField);
                $fields->removeByName('RootAssetsViewerMembers');
            } elseif ($this->getOwner()->RootAssetsCanViewType === InheritedPermissions::ONLY_THESE_MEMBERS) {
                $fields->makeFieldReadonly($viewerMembersField);
                $fields->removeByName('RootAssetsViewerGroups');
            } else {
                $fields->removeByName('RootAssetsViewerGroups');
                $fields->removeByName('RootAssetsViewerMembers');
            }

            $fields->makeFieldReadonly($editorsOptionsField);
            if ($this->getOwner()->RootAssetsCanEditType === InheritedPermissions::ONLY_THESE_USERS) {
                $fields->makeFieldReadonly($editorGroupsField);
                $fields->removeByName('RootAssetsEditorMembers');
            } elseif ($this->getOwner()->RootAssetsCanEditType === InheritedPermissions::ONLY_THESE_MEMBERS) {
                $fields->makeFieldReadonly($editorMembersField);
                $fields->removeByName('RootAssetsEditorGroups');
            } else {
                $fields->removeByName('RootAssetsEditorGroups');
                $fields->removeByName('RootAssetsEditorMembers');
            }
        }
    }
}
