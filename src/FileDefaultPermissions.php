<?php

namespace SilverStripe\Assets;

use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\DefaultPermissionChecker;

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
        return true;
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
