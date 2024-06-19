<?php

namespace SilverStripe\Assets;

use SilverStripe\Forms\FileHandleField;
use SilverStripe\Forms\FormField;
use SilverStripe\ORM\DataObject;

/**
 * Represents an Image
 */
class Image extends File
{
    /**
     * @config
     * @var string
     */
    private static $table_name = 'Image';

    /**
     * @config
     * @var string
     */
    private static $singular_name = "Image";

    /**
     * @config
     * @var string
     */
    private static $plural_name = "Images";

    /**
     * Globally control whether Images added via the WYSIWYG editor or inserted as Image objects in Silverstripe
     * templates have the loading="lazy" HTML added by default
     *
     * @config
     * @var bool
     */
    private static $lazy_loading_enabled = true;

    public function __construct($record = null, $isSingleton = false, $queryParams = [])
    {
        parent::__construct($record, $isSingleton, $queryParams);
        $this->File->setAllowedCategories('image/supported');
    }

    public function scaffoldFormFieldForHasOne(
        string $fieldName,
        ?string $fieldTitle,
        string $relationName,
        DataObject $ownerRecord
    ): FormField&FileHandleField {
        $field = parent::scaffoldFormFieldForHasOne($fieldName, $fieldTitle, $relationName, $ownerRecord);
        $field->setAllowedFileCategories('image/supported');
        return $field;
    }

    public function scaffoldFormFieldForHasMany(
        string $relationName,
        ?string $fieldTitle,
        DataObject $ownerRecord,
        bool &$includeInOwnTab
    ): FormField {
        $field = parent::scaffoldFormFieldForHasMany($relationName, $fieldTitle, $ownerRecord, $includeInOwnTab);
        if ($field instanceof FileHandleField) {
            $field->setAllowedFileCategories('image/supported');
        }
        return $field;
    }

    public function scaffoldFormFieldForManyMany(
        string $relationName,
        ?string $fieldTitle,
        DataObject $ownerRecord,
        bool &$includeInOwnTab
    ): FormField {
        $field = parent::scaffoldFormFieldForManyMany($relationName, $fieldTitle, $ownerRecord, $includeInOwnTab);
        if ($field instanceof FileHandleField) {
            $field->setAllowedFileCategories('image/supported');
        }
        return $field;
    }

    public function getIsImage()
    {
        return true;
    }

    /**
     * @param null $action
     * @return bool|string
     */
    public function PreviewLink($action = null)
    {
        // Since AbsoluteLink can whitelist protected assets,
        // do permission check first
        if (!$this->canView()) {
            return false;
        }

        // Size to width / height
        $width = (int)$this->config()->get('asset_preview_width');
        $height = (int)$this->config()->get('asset_preview_height');
        $resized = $this->FitMax($width, $height);
        if ($resized && $resized->exists()) {
            $link = $resized->getAbsoluteURL();
        } else {
            $link = $this->getIcon();
        }
        $this->extend('updatePreviewLink', $link, $action);
        return $link;
    }

    /**
     * @return bool
     */
    public static function getLazyLoadingEnabled(): bool
    {
        return self::config()->get('lazy_loading_enabled');
    }
}
