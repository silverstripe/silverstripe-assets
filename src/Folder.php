<?php

namespace SilverStripe\Assets;

use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Versioned\Versioned;

/**
 * Represents a logical folder, which may be used to organise assets
 * stored in the configured backend.
 *
 * Unlike {@see File} dataobjects, there is not necessarily a physical filesystem entite which
 * represents a Folder, and it may be purely logical. However, a physical folder may exist
 * if the backend creates one.
 *
 * Additionally, folders do not have URLs (relative or absolute), nor do they have paths.
 *
 * When a folder is moved or renamed, records within it will automatically be copied to the updated
 * location.
 *
 * Deleting a folder will remove all child records, but not any physical files.
 *
 * See {@link File} documentation for more details about the
 * relationship between the database and filesystem in the SilverStripe file APIs.
 */
class Folder extends File
{

    private static $singular_name = "Folder";

    private static $plural_name = "Folders";

    private static $table_name = 'Folder';

    public function exists()
    {
        return $this->isInDB();
    }

    /**
     * Find the given folder or create it as a database record
     *
     * @param string $folderPath Directory path relative to assets root
     * @return Folder|null
     */
    public static function find_or_make($folderPath)
    {
        // Safely split all parts
        $parts = array_filter(preg_split("#[/\\\\]+#", $folderPath ?? '') ?? []);

        $parentID = 0;
        $item = null;
        $filter = FolderNameFilter::create();
        foreach ($parts as $part) {
            if (!$part) {
                continue; // happens for paths with a trailing slash
            }

            // Ensure search includes folders with illegal characters removed, but
            // err in favour of matching existing folders if $folderPath
            // includes illegal characters itself.
            $partSafe = $filter->filter($part);
            $item = Folder::get()->filter([
                'ParentID' => $parentID,
                'Name' => [$partSafe, $part]
            ])->first();

            // When in archived mode, find or make should not find folders that don't exist
            // We check explicitly for Versioned and if it exists then we'll confirm the reading mode isn't archived
            if (class_exists(Versioned::class)) {
                $versioned = Injector::inst()->get('SilverStripe\Versioned\Versioned');
                if ($versioned
                    && strpos($versioned::get_reading_mode() ?? '', 'Archive.') !== false) {
                    // We return the searched for folder, it will either be null if it doesn't exist
                    // or the folder if it does exist (at the archived date and time)
                    return $item;
                }
            }

            if (!$item) {
                $item = new Folder();
                $item->ParentID = $parentID;
                $item->Name = $partSafe;
                $item->Title = $part;
                $item->write();
            }
            $parentID = $item->ID;
        }

        return $item;
    }

    public function onBeforeDelete()
    {
        foreach ($this->AllChildren() as $child) {
            $child->delete();
        }

        parent::onBeforeDelete();
    }

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        $this->Title = $this->getField('Name');
    }

    /**
     * Return the relative URL of an icon for this file type
     *
     * @return string
     */
    public function getIcon()
    {
        return ModuleResourceLoader::resourceURL(
            'silverstripe/framework:client/images/app_icons/folder_icon_large.png'
        );
    }

    /**
     * Override setting the Title of Folders to that Name and Title are always in sync.
     * Note that this is not appropriate for files, because someone might want to create a human-readable name
     * of a file that is different from its name on disk. But folders should always match their name on disk.
     *
     * @param string $title
     * @return $this
     */
    public function setTitle($title)
    {
        $this->setField('Title', $title);
        $this->setField('Name', $title);

        return $this;
    }

    /**
     * Get the folder title
     *
     * @return string
     */
    public function getTitle()
    {
        return $this->Name;
    }

    /**
     * A folder doesn't have a (meaningful) file size.
     *
     * @return null
     */
    public function getSize()
    {
        return null;
    }

    /**
     * Returns all children of this folder
     *
     * @return DataList<File>
     */
    public function myChildren()
    {
        return File::get()->filter("ParentID", $this->ID);
    }

    /**
     * Returns true if this folder has children
     *
     * @return bool
     */
    public function hasChildren()
    {
        return $this->myChildren()->exists();
    }

    /**
     * Returns true if this folder has children
     *
     * @return bool
     */
    public function hasChildFolders()
    {
        return $this->ChildFolders()->exists();
    }

    /**
     * Get the children of this folder that are also folders.
     *
     * @return DataList<Folder>
     */
    public function ChildFolders()
    {
        return Folder::get()->filter('ParentID', $this->ID);
    }

    /**
     * Get the number of children of this folder that are also folders.
     *
     * @return int
     */
    public function numChildFolders()
    {
        return $this->ChildFolders()->count();
    }

    /**
     * @return string
     */
    public function getTreeTitle()
    {
        return sprintf(
            "<span class=\"jstree-foldericon\"></span><span class=\"item\">%s</span>",
            Convert::raw2att(preg_replace('~\R~u', ' ', $this->Title ?? ''))
        );
    }

    public function getFilename()
    {
        return parent::generateFilename() . '/';
    }

    /**
     * Folders do not have public URLs
     *
     * @param bool $grant
     * @return null|string
     */
    public function getURL($grant = true)
    {
        return null;
    }

    /**
     * Folders do not have public URLs
     *
     * @return string
     */
    public function getAbsoluteURL()
    {
        return null;
    }

    public function onAfterWrite()
    {
        parent::onAfterWrite();

        // No publishing UX for folders, so just cascade changes live
        if (class_exists(Versioned::class) && $this->hasExtension(Versioned::class) && Versioned::get_stage() === Versioned::DRAFT) {
            $this->copyVersionToStage(Versioned::DRAFT, Versioned::LIVE);
        }

        // Update draft version of all child records
        $this->updateChildFilesystem();
    }

    public function onAfterDelete()
    {
        parent::onAfterDelete();

        // Cascade deletions to live
        if (class_exists(Versioned::class) && $this->hasExtension(Versioned::class) && Versioned::get_stage() === Versioned::DRAFT) {
            $this->deleteFromStage(Versioned::LIVE);
        }
    }

    public function updateFilesystem()
    {
        // No filesystem changes to update
    }

    /**
     * If a write is skipped due to no changes, ensure that nested records still get asked to update
     */
    public function onAfterSkippedWrite()
    {
        $this->updateChildFilesystem();
    }

    /**
     * Update filesystem of all children
     */
    public function updateChildFilesystem()
    {
        // Don't synchronise on live (rely on publishing instead)
        if (class_exists(Versioned::class) && $this->hasExtension(Versioned::class) && Versioned::get_stage() === Versioned::LIVE) {
            return;
        }

        $this->flushCache();
        // Writing this record should trigger a write (and potential updateFilesystem) on each child
        foreach ($this->AllChildren() as $child) {
            $child->write();
        }
    }

    public function StripThumbnail()
    {
        return null;
    }

    public function validate()
    {
        $result = ValidationResult::create();
        $this->extend('validate', $result);
        return $result;
    }

    /**
     * @return FolderNameFilter
     */
    protected function getFilter()
    {
        return FolderNameFilter::create();
    }
}
