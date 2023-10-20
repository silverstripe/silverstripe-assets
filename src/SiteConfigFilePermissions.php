<?php

namespace SilverStripe\Assets;

use SilverStripe\Assets\File;
use SilverStripe\Security\DefaultPermissionChecker;
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Permissions for root files with Can*Type = Inherit
 */
class SiteConfigFilePermissions implements DefaultPermissionChecker
{
    /**
     * Can root be edited?
     *
     * @param Member $member
     * @return bool
     */
    public function canEdit(Member $member = null)
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }

        if (Permission::checkMember($member, 'ADMIN') || Permission::check($member, File::EDIT_ALL)) {
            return true;
        }

        $siteConfig = SiteConfig::current_site_config();
        $canEditType = $siteConfig->RootAssetsCanEditType;

        // Any logged in user can edit root files and folders
        if ($canEditType === InheritedPermissions::LOGGED_IN_USERS) {
            return $member !== null;
        }

        // Specific user groups can edit root files and folders
        if ($canEditType === InheritedPermissions::ONLY_THESE_USERS) {
            if (!$member) {
                return false;
            }
            return $member->inGroups($siteConfig->RootAssetsEditorGroups());
        }

        // Specific users can edit root files and folders
        if ($canEditType === InheritedPermissions::ONLY_THESE_MEMBERS) {
            if (!$member) {
                return false;
            }
            return $siteConfig->RootAssetsEditorMembers()->filter('ID', $member->ID)->count() > 0;
        }

        // Secure by default
        return false;
    }

    /**
     * Can root be viewed?
     *
     * @param Member $member
     * @return bool
     */
    public function canView($member = null)
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }

        if (Permission::checkMember($member, 'ADMIN') || Permission::check($member, File::EDIT_ALL)) {
            return true;
        }

        $siteConfig = SiteConfig::current_site_config();
        $canViewType = $siteConfig->RootAssetsCanViewType;

        if ($canViewType === InheritedPermissions::ANYONE) {
            return true;
        }

        // Any logged in user can view root files and folders
        if ($canViewType === InheritedPermissions::LOGGED_IN_USERS) {
            return $member !== null;
        }

        // Specific user groups can view root files and folders
        if ($canViewType === InheritedPermissions::ONLY_THESE_USERS) {
            if (!$member) {
                return false;
            }
            return $member->inGroups($siteConfig->RootAssetsViewerGroups());
        }

        // Specific users can view root files and folders
        if ($canViewType === InheritedPermissions::ONLY_THESE_MEMBERS) {
            if (!$member) {
                return false;
            }
            return $siteConfig->RootAssetsViewerMembers()->filter('ID', $member->ID)->count() > 0;
        }

        // Secure by default
        return false;
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
