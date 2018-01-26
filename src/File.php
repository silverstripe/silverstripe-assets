<?php

namespace SilverStripe\Assets;

use InvalidArgumentException;
use SilverStripe\Assets\Shortcodes\FileShortcodeProvider;
use SilverStripe\Assets\Shortcodes\ImageShortcodeProvider;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Assets\Storage\AssetNameGenerator;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Control\Director;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Core\Resettable;
use SilverStripe\Dev\Deprecation;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HTMLReadonlyField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\CMSPreviewable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Hierarchy\Hierarchy;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\Security\InheritedPermissionsExtension;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionChecker;
use SilverStripe\Security\PermissionProvider;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\HTML;

/**
 * This class handles the representation of a file on the filesystem within the framework.
 * Most of the methods also handle the {@link Folder} subclass.
 *
 * Note: The files are stored in the assets/ directory, but SilverStripe
 * looks at the db object to gather information about a file such as URL
 * It then uses this for all processing functions (like image manipulation).
 *
 * <b>Security</b>
 *
 * Caution: It is recommended to disable any script execution in the "assets/"
 * directory in the webserver configuration, to reduce the risk of exploits.
 * See http://doc.silverstripe.org/secure-development#filesystem
 *
 * <b>Asset storage</b>
 *
 * As asset storage is configured separately to any File DataObject records, this class
 * does not make any assumptions about how these records are saved. They could be on
 * a local filesystem, remote filesystem, or a virtual record container (such as in local memory).
 *
 * The File dataobject simply represents an externally facing view of shared resources
 * within this asset store.
 *
 * Internally individual files are referenced by a"Filename" parameter, which represents a File, extension,
 * and is optionally prefixed by a list of custom directories. This path is root-agnostic, so it does not
 * automatically have a direct url mapping (even to the site's base directory).
 *
 * Additionally, individual files may have several versions distinguished by sha1 hash,
 * of which a File DataObject can point to a single one. Files can also be distinguished by
 * variants, which may be resized images or format-shifted documents.
 *
 * <b>Properties</b>
 *
 * - "Title": Optional title of the file (for display purposes only).
 *   Defaults to "Name". Note that the Title field of Folder (subclass of File)
 *   is linked to Name, so Name and Title will always be the same.
 * -"File": Physical asset backing this DB record. This is a composite DB field with
 *   its own list of properties. {@see DBFile} for more information
 * - "Content": Typically unused, but handy for a textual representation of
 *   files, e.g. for fulltext indexing of PDF documents.
 * - "ParentID": Points to a {@link Folder} record. Should be in sync with
 *   "Filename". A ParentID=0 value points to the "assets/" folder, not the webroot.
 * -"ShowInSearch": True if this file is searchable
 *
 * @property string $Name Basename of the file
 * @property string $Title Title of the file
 * @property string $Filename Full filename of this file
 * @property DBFile $File asset stored behind this File record
 * @property string $Content
 * @property string $ShowInSearch Boolean that indicates if file is shown in search. Doesn't apply to Folders
 * @property int $ParentID ID of parent File/Folder
 * @property int $OwnerID ID of Member who owns the file
 *
 * @method File Parent() Returns parent File
 * @method Member Owner() Returns Member object of file owner.
 *
 * @mixin Hierarchy
 * @mixin Versioned
 * @mixin InheritedPermissionsExtension
 */
class File extends DataObject implements AssetContainer, Thumbnail, CMSPreviewable, PermissionProvider, Resettable
{
    use ImageManipulation;

    /**
     * Permission for edit all files
     */
    const EDIT_ALL = 'FILE_EDIT_ALL';

    /**
     * Permission for view all files
     */
    const VIEW_ALL = 'FILE_VIEW_ALL';

    private static $default_sort = "\"Name\"";

    /**
     * @config
     * @var string
     */
    private static $singular_name = "File";

    private static $plural_name = "Files";

    /**
     * Permissions necessary to view files outside of the live stage (e.g. archive / draft stage).
     *
     * @config
     * @var array
     */
    private static $non_live_permissions = array('CMS_ACCESS_LeftAndMain', 'CMS_ACCESS_AssetAdmin', 'VIEW_DRAFT_CONTENT');

    private static $db = array(
        "Name" => "Varchar(255)",
        "Title" => "Varchar(255)",
        "File" => "DBFile",
        // Only applies to files, doesn't inherit for folder
        'ShowInSearch' => 'Boolean(1)',
    );

    private static $has_one = array(
        "Parent" => File::class,
        "Owner" => Member::class,
    );

    private static $defaults = array(
        "ShowInSearch" => 1,
    );

    private static $extensions = array(
        Hierarchy::class,
        InheritedPermissionsExtension::class,
    );

    private static $casting = array (
        'TreeTitle' => 'HTMLFragment'
    );

    private static $table_name = 'File';

    /**
     * @config
     * @var array List of allowed file extensions, enforced through {@link validate()}.
     *
     * Note: if you modify this, you should also change a configuration file in the assets directory.
     * Otherwise, the files will be able to be uploaded but they won't be able to be served by the
     * webserver.
     *
     *  - If you are running Apache you will need to change assets/.htaccess
     *  - If you are running IIS you will need to change assets/web.config
     *
     * Instructions for the change you need to make are included in a comment in the config file.
     */
    private static $allowed_extensions = array(
        '', 'ace', 'arc', 'arj', 'asf', 'au', 'avi', 'bmp', 'bz2', 'cab', 'cda', 'css', 'csv', 'dmg', 'doc',
        'docx', 'dotx', 'dotm', 'flv', 'gif', 'gpx', 'gz', 'hqx', 'ico', 'jar', 'jpeg', 'jpg', 'js', 'kml',
        'm4a', 'm4v', 'mid', 'midi', 'mkv', 'mov', 'mp3', 'mp4', 'mpa', 'mpeg', 'mpg', 'ogg', 'ogv', 'pages',
        'pcx', 'pdf', 'png', 'pps', 'ppt', 'pptx', 'potx', 'potm', 'ra', 'ram', 'rm', 'rtf', 'sit', 'sitx',
        'tar', 'tgz', 'tif', 'tiff', 'txt', 'wav', 'webm', 'wma', 'wmv', 'xls', 'xlsx', 'xltx', 'xltm', 'zip',
        'zipx',
    );

    /**
     * @config
     * @var array Category identifiers mapped to commonly used extensions.
     */
    private static $app_categories = array(
        'archive' => array(
            'ace', 'arc', 'arj', 'bz', 'bz2', 'cab', 'dmg', 'gz', 'hqx', 'jar', 'rar', 'sit', 'sitx', 'tar', 'tgz',
            'zip', 'zipx',
        ),
        'audio' => array(
            'aif', 'aifc', 'aiff', 'apl', 'au', 'avr', 'cda', 'm4a', 'mid', 'midi', 'mp3', 'ogg', 'ra',
            'ram', 'rm', 'snd', 'wav', 'wma',
        ),
        'document' => array(
            'css', 'csv', 'doc', 'docx', 'dotm', 'dotx', 'htm', 'html', 'gpx', 'js', 'kml', 'pages', 'pdf',
            'potm', 'potx', 'pps', 'ppt', 'pptx', 'rtf', 'txt', 'xhtml', 'xls', 'xlsx', 'xltm', 'xltx', 'xml',
        ),
        'image' => array(
            'alpha', 'als', 'bmp', 'cel', 'gif', 'ico', 'icon', 'jpeg', 'jpg', 'pcx', 'png', 'ps', 'psd', 'tif', 'tiff',
        ),
        'image/supported' => array(
            'gif', 'jpeg', 'jpg', 'png', 'bmp', 'ico',
        ),
        'flash' => array(
            'fla', 'swf'
        ),
        'video' => array(
            'asf', 'avi', 'flv', 'ifo', 'm1v', 'm2v', 'm4v', 'mkv', 'mov', 'mp2', 'mp4', 'mpa', 'mpe', 'mpeg',
            'mpg', 'ogv', 'qt', 'vob', 'webm', 'wmv',
        ),
    );

    /**
     * Map of file extensions to class type
     *
     * @config
     * @var
     */
    private static $class_for_file_extension = array(
        '*' => File::class,
        'jpg' => Image::class,
        'jpeg' => Image::class,
        'png' => Image::class,
        'gif' => Image::class,
        'bmp' => Image::class,
        'ico' => Image::class,
    );

    /**
     * @config
     * @var bool If this is true, then restrictions set in {@link $allowed_max_file_size} and
     * {@link $allowed_extensions} will be applied to users with admin privileges as
     * well.
     */
    private static $apply_restrictions_to_admin = true;

    /**
     * If enabled, legacy file dataobjects will be automatically imported into the APL
     *
     * @config
     * @var bool
     */
    private static $migrate_legacy_file = false;

    /**
     * @config
     * @var boolean
     */
    private static $update_filesystem = true;

    public static function get_shortcodes()
    {
        return 'file_link';
    }

    /**
     * A file only exists if the file_exists() and is in the DB as a record
     *
     * Use $file->isInDB() to only check for a DB record
     * Use $file->File->exists() to only check if the asset exists
     *
     * @return bool
     */
    public function exists()
    {
        return parent::exists() && $this->File->exists();
    }

    /**
     * Find a File object by the given filename.
     *
     * @param string $filename Filename to search for, including any custom parent directories.
     * @return File
     */
    public static function find($filename)
    {
        // Split to folders and the actual filename, and traverse the structure.
        $parts = array_filter(preg_split("#[/\\\\]+#", $filename));
        $parentID = 0;
        /** @var File $item */
        $item = null;
        foreach ($parts as $part) {
            $item = File::get()->filter(array(
                'Name' => $part,
                'ParentID' => $parentID
            ))->first();
            if (!$item) {
                break;
            }
            $parentID = $item->ID;
        }

        return $item;
    }

    /**
     * Just an alias function to keep a consistent API with SiteTree
     *
     * @return string The link to the file
     */
    public function Link()
    {
        return $this->getURL();
    }

    /**
     * @deprecated 4.0
     */
    public function RelativeLink()
    {
        Deprecation::notice('4.0', 'Use getURL instead, as not all files will be relative to the site root.');
        return Director::makeRelative($this->getURL());
    }

    /**
     * Just an alias function to keep a consistent API with SiteTree
     *
     * @return string The absolute link to the file
     */
    public function AbsoluteLink()
    {
        return $this->getAbsoluteURL();
    }

    /**
     * @return string
     */
    public function getTreeTitle()
    {
        return Convert::raw2xml($this->Title);
    }

    /**
     * @param Member $member
     * @return bool
     */
    public function canView($member = null)
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }

        $result = $this->extendedCan('canView', $member);
        if ($result !== null) {
            return $result;
        }

        if (Permission::checkMember($member, File::VIEW_ALL)) {
            return true;
        }

        // Check inherited permissions
        return static::getPermissionChecker()
            ->canView($this->ID, $member);
    }

    /**
     * Check if this file can be modified
     *
     * @param Member $member
     * @return boolean
     */
    public function canEdit($member = null)
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }

        $result = $this->extendedCan('canEdit', $member);
        if ($result !== null) {
            return $result;
        }

        if (Permission::checkMember($member, self::EDIT_ALL)) {
            return true;
        }

        // Check inherited permissions
        return static::getPermissionChecker()
            ->canEdit($this->ID, $member);
    }

    /**
     * Check if a file can be created
     *
     * @param Member $member
     * @param array $context
     * @return boolean
     */
    public function canCreate($member = null, $context = array())
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }

        $result = $this->extendedCan('canCreate', $member, $context);
        if ($result !== null) {
            return $result;
        }

        if (Permission::checkMember($member, self::EDIT_ALL)) {
            return true;
        }

        // If Parent is provided, file can be created if parent can be edited
        /** @var Folder $parent */
        $parent = isset($context['Parent']) ? $context['Parent'] : null;
        if ($parent) {
            return $parent->canEdit($member);
        }

        return false;
    }

    /**
     * Check if this file can be deleted
     *
     * @param Member $member
     * @return boolean
     */
    public function canDelete($member = null)
    {
        if (!$member) {
            $member = Security::getCurrentUser();
        }

        $result = $this->extendedCan('canDelete', $member);
        if ($result !== null) {
            return $result;
        }

        if (!$member) {
            return false;
        }

        // Default permission check
        if (Permission::checkMember($member, self::EDIT_ALL)) {
            return true;
        }

        // Check inherited permissions
        return static::getPermissionChecker()
            ->canDelete($this->ID, $member);
    }

    /**
     * List of basic content editable file fields.
     *
     * @return FieldList
     */
    public function getCMSFields()
    {
        $image = HTML::createTag('img', [
            'src' => $this->PreviewLink(),
            'alt' => $this->getTitle(),
            'class' => 'd-block mx-auto',
        ]);

        $fields = FieldList::create(
            HTMLReadonlyField::create('IconFull', _t(__CLASS__.'.PREVIEW', 'Preview'), $image),
            TextField::create("Title", $this->fieldLabel('Title')),
            TextField::create("Name", $this->fieldLabel('Filename')),
            TextField::create("Filename", _t(__CLASS__.'.PATH', 'Path'))
                ->setReadonly(true)
        );
        $this->extend('updateCMSFields', $fields);
        return $fields;
    }

    /**
     * Get title for current file status
     *
     * @return string
     */
    public function getStatusTitle()
    {
        $statusTitle = '';
        if ($this->isOnDraftOnly()) {
            $statusTitle = _t(__CLASS__.'.DRAFT', 'Draft');
        } elseif ($this->isModifiedOnDraft()) {
            $statusTitle = _t(__CLASS__.'.MODIFIED', 'Modified');
        }
        return $statusTitle;
    }

    /**
     * Returns a category based on the file extension.
     * This can be useful when grouping files by type,
     * showing icons on filelinks, etc.
     * Possible group values are: "audio", "mov", "zip", "image".
     *
     * @param string $ext Extension to check
     * @return string
     */
    public static function get_app_category($ext)
    {
        $ext = strtolower($ext);
        foreach (static::config()->get('app_categories') as $category => $exts) {
            if (in_array($ext, $exts)) {
                return $category;
            }
        }
        return false;
    }

    /**
     * For a category or list of categories, get the list of file extensions
     *
     * @param array|string $categories List of categories, or single category
     * @return array
     */
    public static function get_category_extensions($categories)
    {
        if (empty($categories)) {
            return array();
        }

        // Fix arguments into a single array
        if (!is_array($categories)) {
            $categories = array($categories);
        } elseif (count($categories) === 1 && is_array(reset($categories))) {
            $categories = reset($categories);
        }

        // Check configured categories
        $appCategories = static::config()->get('app_categories');

        // Merge all categories into list of extensions
        $extensions = array();
        foreach (array_filter($categories) as $category) {
            if (isset($appCategories[$category])) {
                $extensions = array_merge($extensions, $appCategories[$category]);
            } else {
                throw new InvalidArgumentException("Unknown file category: $category");
            }
        }
        $extensions = array_unique($extensions);
        sort($extensions);
        return $extensions;
    }

    /**
     * Returns a category based on the file extension.
     *
     * @return string
     */
    public function appCategory()
    {
        return self::get_app_category($this->getExtension());
    }

    /**
     * Should be called after the file was uploaded
     */
    public function onAfterUpload()
    {
        $this->extend('onAfterUpload');
    }

    /**
     * Make sure the file has a name
     */
    protected function onBeforeWrite()
    {
        // Set default owner
        if (!$this->isInDB() && !$this->OwnerID && Security::getCurrentUser()) {
            $this->OwnerID = Security::getCurrentUser()->ID;
        }

        $currentname = $name = $this->getField('Name');
        $title = $this->getField('Title');

        $changed = $this->isChanged('Name');

        // Name can't be blank, default to Title or singular name
        if (!$name) {
            $name = $title ?: $this->i18n_singular_name();
        }
        $name = $this->filterFilename($name);
        if ($name !== $currentname) {
            $changed = true;
        }

        // Check for duplicates when the name has changed (or is set for the first time)
        if ($changed) {
            $nameGenerator = $this->getNameGenerator($name);
            // Defaults to returning the original filename on first iteration
            foreach ($nameGenerator as $newName) {
                // This logic is also used in the Folder subclass, but we're querying
                // for duplicates on the File base class here (including the Folder subclass).

                // TODO Add read lock to avoid other processes creating files with the same name
                // before this process has a chance to persist in the database.
                $existingFile = File::get()->filter(array(
                    'Name' => $newName,
                    'ParentID' => (int) $this->ParentID
                ))->exclude(array(
                    'ID' => $this->ID
                ))->first();
                if (!$existingFile) {
                    $name = $newName;
                    break;
                }
            }
        }

        // Update actual field value
        $this->setField('Name', $name);

        // Update title
        if (!$title) {
            // Generate a readable title, dashes and underscores replaced by whitespace,
            // and any file extensions removed.
            $this->setField(
                'Title',
                str_replace(array('-','_'), ' ', preg_replace('/\.[^.]+$/', '', $name))
            );
        }

        // Propagate changes to the AssetStore and update the DBFile field
        $this->updateFilesystem();

        parent::onBeforeWrite();
    }

    /**
     * This will check if the parent record and/or name do not match the name on the underlying
     * DBFile record, and if so, copy this file to the new location, and update the record to
     * point to this new file.
     *
     * This method will update the File {@see DBFile} field value on success, so it must be called
     * before writing to the database
     *
     * @return bool True if changed
     */
    public function updateFilesystem()
    {
        if (!$this->config()->get('update_filesystem')) {
            return false;
        }

        // Check the file exists
        if (!$this->File->exists()) {
            return false;
        }

        // Avoid moving files on live; Rely on this being done on stage prior to publish.
        if (class_exists(Versioned::class) && Versioned::get_stage() !== Versioned::DRAFT) {
            return false;
        }

        // Check path updated record will point to
        // If no changes necessary, skip
        $pathBefore = $this->File->getFilename();
        $pathAfter = $this->generateFilename();
        if ($pathAfter === $pathBefore) {
            return false;
        }

        // Copy record to new location and point DB fields to updated Filename,
        // respecting back end conflict resolution
        $expectedPath = $pathAfter;
        $pathAfter = $this->File->copyFile($pathAfter);
        if (!$pathAfter) {
            return false;
        }
        if ($expectedPath !== $pathAfter) {
            $this->setFilename($pathAfter);
        }
        $this->File->Filename = $pathAfter;
        return true;
    }

    /**
     * Collate selected descendants of this page.
     * $condition will be evaluated on each descendant, and if it is succeeds, that item will be added
     * to the $collator array.
     *
     * @param string $condition The PHP condition to be evaluated.  The page will be called $item
     * @param array $collator An array, passed by reference, to collect all of the matching descendants.
     * @return true|null
     */
    public function collateDescendants($condition, &$collator)
    {
        if ($children = $this->Children()) {
            foreach ($children as $item) {
                /** @var File $item */
                if (!$condition || eval("return $condition;")) {
                    $collator[] = $item;
                }
                $item->collateDescendants($condition, $collator);
            }
            return true;
        }
        return null;
    }

    /**
     * Get an asset renamer for the given filename.
     *
     * @param string $filename Path name
     * @return AssetNameGenerator
     */
    protected function getNameGenerator($filename)
    {
        return Injector::inst()->createWithArgs(AssetNameGenerator::class, [$filename]);
    }

    /**
     * Gets the URL of this file
     *
     * @return string
     */
    public function getAbsoluteURL()
    {
        $url = $this->getURL();
        if ($url) {
            return Director::absoluteURL($url);
        }
        return null;
    }

    /**
     * Gets the URL of this file
     *
     * @uses Director::baseURL()
     * @param bool $grant Ensures that the url for any protected assets is granted for the current user.
     * @return string
     */
    public function getURL($grant = true)
    {
        if ($this->File->exists()) {
            return $this->File->getURL($grant);
        }
        return null;
    }

    /**
     * Get URL, but without resampling.
     *
     * @param bool $grant Ensures that the url for any protected assets is granted for the current user.
     * @return string
     */
    public function getSourceURL($grant = true)
    {
        if ($this->File->exists()) {
            return $this->File->getSourceURL($grant);
        }
        return null;
    }

    /**
     * Get expected value of Filename tuple value. Will be used to trigger
     * a file move on draft stage.
     *
     * @return string
     */
    public function generateFilename()
    {
        // Check if this file is nested within a folder
        $parent = $this->Parent();
        if ($parent && $parent->exists()) {
            return $this->join_paths($parent->getFilename(), $this->Name);
        }
        return $this->Name;
    }

    /**
     * Rename this file.
     * Note: This method will immediately save to the database to maintain
     * filesystem consistency with the database.
     *
     * @param string $newName
     * @return string
     */
    public function renameFile($newName)
    {
        $this->setFilename($newName);
        $this->write();
        return $this->getFilename();
    }

    public function copyFile($newName)
    {
        $newName = $this->filterFilename($newName);
        // Copy doesn't modify this record, so can be performed immediately
        return $this->File->copyFile($newName);
    }

    /**
     * Update the ParentID and Name for the given filename.
     *
     * On save, the underlying DBFile record will move the underlying file to this location.
     * Thus it will not update the underlying Filename value until this is done.
     *
     * @param string $filename
     * @return $this
     */
    public function setFilename($filename)
    {
        $filename = $this->filterFilename($filename);

        // Check existing folder path
        $folder = '';
        $parent = $this->Parent();
        if ($parent && $parent->exists()) {
            $folder = $parent->getFilename();
        }

        // Detect change in foldername
        $newFolder = ltrim(dirname(trim($filename, '/')), '.');
        if ($folder !== $newFolder) {
            if (!$newFolder) {
                $this->ParentID = 0;
            } else {
                $parent = Folder::find_or_make($newFolder);
                $this->ParentID = $parent->ID;
            }
        }

        // Update base name
        $this->Name = basename($filename);
        return $this;
    }

    /**
     * Returns the file extension
     *
     * @return string
     */
    public function getExtension()
    {
        return self::get_file_extension($this->Name);
    }

    /**
     * Gets the extension of a filepath or filename,
     * by stripping away everything before the last "dot".
     * Caution: Only returns the last extension in "double-barrelled"
     * extensions (e.g. "gz" for "tar.gz").
     *
     * Examples:
     * - "myfile" returns ""
     * - "myfile.txt" returns "txt"
     * - "myfile.tar.gz" returns "gz"
     *
     * @param string $filename
     * @return string
     */
    public static function get_file_extension($filename)
    {
        return pathinfo($filename, PATHINFO_EXTENSION);
    }

    /**
     * Given an extension, determine the icon that should be used
     *
     * @param string $extension
     * @return string Icon filename relative to base url
     */
    public static function get_icon_for_extension($extension)
    {
        $extension = strtolower($extension);
        $module = ModuleLoader::getModule('silverstripe/framework');

        $candidates = [
            $extension,
            static::get_app_category($extension),
            'generic'
        ];
        foreach ($candidates as $candidate) {
            $resource = $module->getResource("client/images/app_icons/{$candidate}_92.png");
            if ($resource->exists()) {
                return $resource->getURL();
            }
        }
        return null;
    }

    /**
     * Return the type of file for the given extension
     * on the current file name.
     *
     * @return string
     */
    public function getFileType()
    {
        return self::get_file_type($this->getFilename());
    }

    /**
     * Get descriptive type of file based on filename
     *
     * @param string $filename
     * @return string Description of file
     */
    public static function get_file_type($filename)
    {
        $types = array(
            'gif' => _t(__CLASS__.'.GifType', 'GIF image - good for diagrams'),
            'jpg' => _t(__CLASS__.'.JpgType', 'JPEG image - good for photos'),
            'jpeg' => _t(__CLASS__.'.JpgType', 'JPEG image - good for photos'),
            'png' => _t(__CLASS__.'.PngType', 'PNG image - good general-purpose format'),
            'ico' => _t(__CLASS__.'.IcoType', 'Icon image'),
            'tiff' => _t(__CLASS__.'.TiffType', 'Tagged image format'),
            'doc' => _t(__CLASS__.'.DocType', 'Word document'),
            'xls' => _t(__CLASS__.'.XlsType', 'Excel spreadsheet'),
            'zip' => _t(__CLASS__.'.ZipType', 'ZIP compressed file'),
            'gz' => _t(__CLASS__.'.GzType', 'GZIP compressed file'),
            'dmg' => _t(__CLASS__.'.DmgType', 'Apple disk image'),
            'pdf' => _t(__CLASS__.'.PdfType', 'Adobe Acrobat PDF file'),
            'mp3' => _t(__CLASS__.'.Mp3Type', 'MP3 audio file'),
            'wav' => _t(__CLASS__.'.WavType', 'WAV audo file'),
            'avi' => _t(__CLASS__.'.AviType', 'AVI video file'),
            'mpg' => _t(__CLASS__.'.MpgType', 'MPEG video file'),
            'mpeg' => _t(__CLASS__.'.MpgType', 'MPEG video file'),
            'js' => _t(__CLASS__.'.JsType', 'Javascript file'),
            'css' => _t(__CLASS__.'.CssType', 'CSS file'),
            'html' => _t(__CLASS__.'.HtmlType', 'HTML file'),
            'htm' => _t(__CLASS__.'.HtmlType', 'HTML file')
        );

        // Get extension
        $extension = strtolower(self::get_file_extension($filename));
        return isset($types[$extension]) ? $types[$extension] : 'unknown';
    }

    /**
     * Returns the size of the file type in an appropriate format.
     *
     * @return string|false String value, or false if doesn't exist
     */
    public function getSize()
    {
        $size = $this->getAbsoluteSize();
        if ($size) {
            return static::format_size($size);
        }
        return false;
    }

    /**
     * Formats a file size (eg: (int)42 becomes string '42 bytes')
     *
     * @param int $size
     * @return string
     */
    public static function format_size($size)
    {
        if ($size < 1024) {
            return $size . ' bytes';
        }
        if ($size < 1024*1024) {
            return round($size/1024) . ' KB';
        }
        if ($size < 1024*1024*10) {
            return round(($size/(1024*1024))*10)/10 . ' MB';
        }
        if ($size < 1024*1024*1024) {
            return round($size/(1024*1024)) . ' MB';
        }
        return round(($size/(1024*1024*1024))*10)/10 . ' GB';
    }

    /**
     * Convert a php.ini value (eg: 512M) to bytes
     *
     * @param  string $iniValue
     * @return float
     */
    public static function ini2bytes($iniValue)
    {
        Deprecation::notice('5.0', 'Use Convert::memstring2bytes instead');
        return Convert::memstring2bytes($iniValue);
    }

    /**
     * Return file size in bytes.
     *
     * @return int
     */
    public function getAbsoluteSize()
    {
        return $this->File->getAbsoluteSize();
    }

    /**
     * @return ValidationResult
     */
    public function validate()
    {
        $result = ValidationResult::create();
        $this->File->validate($result, $this->Name);
        $this->extend('validate', $result);
        return $result;
    }

    /**
     * Maps a {@link File} subclass to a specific extension.
     * By default, files with common image extensions will be created
     * as {@link Image} instead of {@link File} when using
     * {@link Folder::constructChild}, {@link Folder::addUploadToFolder}),
     * and the {@link Upload} class (either directly or through {@link FileField}).
     * For manually instanciated files please use this mapping getter.
     *
     * Caution: Changes to mapping doesn't apply to existing file records in the database.
     * Also doesn't hook into {@link Object::getCustomClass()}.
     *
     * @param String File extension, without dot prefix. Use an asterisk ('*')
     * to specify a generic fallback if no mapping is found for an extension.
     * @return String Classname for a subclass of {@link File}
     */
    public static function get_class_for_file_extension($ext)
    {
        $map = array_change_key_case(self::config()->get('class_for_file_extension'), CASE_LOWER);
        return (array_key_exists(strtolower($ext), $map)) ? $map[strtolower($ext)] : $map['*'];
    }

    /**
     * See {@link get_class_for_file_extension()}.
     *
     * @param String|array
     * @param String
     */
    public static function set_class_for_file_extension($exts, $class)
    {
        if (!is_array($exts)) {
            $exts = array($exts);
        }
        foreach ($exts as $ext) {
            if (!is_subclass_of($class, File::class)) {
                throw new InvalidArgumentException(
                    sprintf('Class "%s" (for extension "%s") is not a valid subclass of File', $class, $ext)
                );
            }
            self::config()->merge('class_for_file_extension', [$ext => $class]);
        }
    }

    public function getMetaData()
    {
        if (!$this->File->exists()) {
            return null;
        }
            return $this->File->getMetaData();
    }

    public function getMimeType()
    {
        if (!$this->File->exists()) {
            return null;
        }
            return $this->File->getMimeType();
    }

    public function getStream()
    {
        if (!$this->File->exists()) {
            return null;
        }
        return $this->File->getStream();
    }

    public function getString()
    {
        if (!$this->File->exists()) {
            return null;
        }
        return $this->File->getString();
    }

    public function setFromLocalFile($path, $filename = null, $hash = null, $variant = null, $config = array())
    {
        $result = $this->File->setFromLocalFile($path, $filename, $hash, $variant, $config);

        // Update File record to name of the uploaded asset
        if ($result) {
            $this->setFilename($result['Filename']);
        }
        return $result;
    }

    public function setFromStream($stream, $filename, $hash = null, $variant = null, $config = array())
    {
        $result = $this->File->setFromStream($stream, $filename, $hash, $variant, $config);

        // Update File record to name of the uploaded asset
        if ($result) {
            $this->setFilename($result['Filename']);
        }
        return $result;
    }

    public function setFromString($data, $filename, $hash = null, $variant = null, $config = array())
    {
        $result = $this->File->setFromString($data, $filename, $hash, $variant, $config);

        // Update File record to name of the uploaded asset
        if ($result) {
            $this->setFilename($result['Filename']);
        }
        return $result;
    }

    public function getIsImage()
    {
        return false;
    }

    public function getFilename()
    {
        return $this->File->Filename;
    }

    public function getHash()
    {
        return $this->File->Hash;
    }

    public function getVariant()
    {
        return $this->File->Variant;
    }

    /**
     * Return a html5 tag of the appropriate for this file (normally img or a)
     *
     * @return string
     */
    public function forTemplate()
    {
        return $this->getTag() ?: '';
    }

    /**
     * Return a html5 tag of the appropriate for this file (normally img or a)
     *
     * @return string
     */
    public function getTag()
    {
        $template = $this->File->getFrontendTemplate();
        if (empty($template)) {
            return '';
        }
        return (string)$this->renderWith($template);
    }

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();

        // Check if old file records should be migrated
        if (!$this->config()->get('migrate_legacy_file')) {
            return;
        }

        $migrated = FileMigrationHelper::singleton()->run();
        if ($migrated) {
            DB::alteration_message("{$migrated} File DataObjects upgraded", "changed");
        }
    }

    /**
     * Joins one or more segments together to build a Filename identifier.
     *
     * Note that the result will not have a leading slash, and should not be used
     * with local file paths.
     *
     * @param string $part,... Parts
     * @return string
     */
    public static function join_paths($part = null)
    {
        $args = func_get_args();
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        $parts = array();
        foreach ($args as $arg) {
            $part = trim($arg, ' \\/');
            if ($part) {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    public function deleteFile()
    {
        return $this->File->deleteFile();
    }

    public function getVisibility()
    {
        return $this->File->getVisibility();
    }

    public function publishFile()
    {
        $this->File->publishFile();
    }

    public function protectFile()
    {
        $this->File->protectFile();
    }

    public function grantFile()
    {
        $this->File->grantFile();
    }

    public function revokeFile()
    {
        $this->File->revokeFile();
    }

    public function canViewFile()
    {
        return $this->File->canViewFile();
    }

    public function CMSEditLink()
    {
        $link = null;
        $this->extend('updateCMSEditLink', $link);
        return $link;
    }

    public function PreviewLink($action = null)
    {
        // Since AbsoluteURL can whitelist protected assets,
        // do permission check first
        if (!$this->canView()) {
            return null;
        }
        $link = $this->getIcon();
        $this->extend('updatePreviewLink', $link, $action);
        return $link;
    }

    /**
     * @return PermissionChecker
     */
    public function getPermissionChecker()
    {
        return Injector::inst()->get(PermissionChecker::class.'.file');
    }

    /**
     * Return a map of permission codes to add to the dropdown shown in the Security section of the CMS.
     * array(
     *   'VIEW_SITE' => 'View the site',
     * );
     */
    public function providePermissions()
    {
        return [
            self::EDIT_ALL => [
                'name' => _t(__CLASS__.'.EDIT_ALL_DESCRIPTION', 'Edit any file'),
                'category' => _t('SilverStripe\\Security\\Permission.CONTENT_CATEGORY', 'Content permissions'),
                'sort' => -100,
                'help' => _t(__CLASS__.'.EDIT_ALL_HELP', 'Edit any file on the site, even if restricted')
            ],
            self::VIEW_ALL => [
                'name' => _t(__CLASS__.'.VIEW_ALL_DESCRIPTION', 'View any file'),
                'category' => _t('SilverStripe\\Security\\Permission.CONTENT_CATEGORY', 'Content permissions'),
                'sort' => -100,
                'help' => _t(__CLASS__.'.VIEW_ALL_HELP', 'View any file on the site, even if restricted')
            ]
        ];
    }

    /**
     * Pass name through standand FileNameFilter
     *
     * @param string $name
     * @return string
     */
    protected function filterFilename($name)
    {
        // Fix illegal characters
        $filter = FileNameFilter::create();
        $parts = array_filter(preg_split("#[/\\\\]+#", $name));
        return implode('/', array_map(function ($part) use ($filter) {
            return $filter->filter($part);
        }, $parts));
    }

    public function flushCache($persistent = true)
    {
        parent::flushCache($persistent);
        static::reset();
        ImageShortcodeProvider::flush();
        FileShortcodeProvider::flush();
    }

    public static function reset()
    {
        parent::reset();

        // Flush permissions on modification
        $permissions = File::singleton()->getPermissionChecker();
        if ($permissions instanceof InheritedPermissions) {
            $permissions->clearCache();
        }
    }
}
