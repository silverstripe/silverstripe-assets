<?php

namespace SilverStripe\Assets;

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

    public function __construct($record = null, $isSingleton = false, $queryParams = array())
    {
        parent::__construct($record, $isSingleton, $queryParams);
        $this->File->setAllowedCategories('image/supported');
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
}
