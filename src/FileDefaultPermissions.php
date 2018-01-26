<?php

namespace SilverStripe\Assets;

use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\DefaultPermissionChecker;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Permissions for root files with Can*Type = Inherit
 */
class FileDefaultPermissions implements DefaultPermissionChecker
{
    /**
     * Can root be edited?
     *
     * @param Member $member
     * @return bool
     */
    public function canEdit(Member $member = null)
    {
        $canEditGroups = SiteConfig::current_site_config()->FileEditorGroups;
        if ($member) {
            return $member->inGroups($canEditGroups);
        }
        return Permission::checkMember($member, File::EDIT_ALL);
    }

    /**
     * Can root be viewed?
     *
     * @param Member $member
     * @return bool
     */
    public function canView(Member $member = null)
    {
        $canViewGroups = SiteConfig::current_site_config()->FileViewerGroups;
        if ($member) {
            return $member->inGroups($canViewGroups);
        }
        return Permission::checkMember($member, File::VIEW_ALL);
    }

    /**
     * Can root be deleted?
     *
     * @param Member $member
     * @return bool
     */
    public function canDelete(Member $member = null)
    {
        return $this->canEdit($member);
    }

    /**
     * Can root objects be created?
     *
     * @param Member $member
     * @return bool
     */
    public function canCreate(Member $member = null)
    {
        return $this->canEdit($member);
    }
}
