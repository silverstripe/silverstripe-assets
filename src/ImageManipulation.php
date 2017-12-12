<?php

namespace SilverStripe\Assets;

use InvalidArgumentException;
use LogicException;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Injector\InjectorNotFoundException;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\View\HTML;

/**
 * Provides image manipulation functionality.
 * Provides limited thumbnail generation functionality for non-image files.
 * Should only be applied to implementors of AssetContainer
 *
 * Allows raw images to be resampled via Resampled()
 *
 * Image scaling manipluations, including:
 * - Fit()
 * - FitMax()
 * - ScaleWidth()
 * - ScaleMaxWidth()
 * - ScaleHeight()
 * - ScaleMaxHeight()
 * - ResizedImage()
 *
 * Image cropping manipulations, including:
 * - CropHeight()
 * - CropWidth()
 * - Fill()
 * - FillMax()
 *
 * Thumbnail generation methods including:
 * - Icon()
 * - CMSThumbnail()
 *
 * @mixin AssetContainer
 */
trait ImageManipulation
{
    /**
     * If image resizes are allowed
     *
     * @var bool
     */
    protected $allowGeneration = true;

    /**
     * Set whether image resizes are allowed
     *
     * @param bool $allow
     * @return $this
     */
    public function setAllowGeneration($allow)
    {
        $this->allowGeneration = $allow;
        return $this;
    }

    /**
     * Check if resizes are allowed
     *
     * @return bool
     */
    public function getAllowGeneration()
    {
        return $this->allowGeneration;
    }

    /**
     * Return clone of self which promises to only return existing thumbnails
     *
     * @return DBFile
     */
    public function existingOnly()
    {
        $value = [
            'Filename' => $this->getFilename(),
            'Variant' => $this->getVariant(),
            'Hash' => $this->getHash()
        ];
        /** @var DBFile $file */
        $file = DBField::create_field('DBFile', $value);
        $file->setAllowGeneration(false);
        return $file;
    }

    /**
     * @return string Data from the file in this container
     */
    abstract public function getString();

    /**
     * @return resource Data stream to the asset in this container
     */
    abstract public function getStream();

    /**
     * @param bool $grant Ensures that the url for any protected assets is granted for the current user.
     * @return string public url to the asset in this container
     */
    abstract public function getURL($grant = true);

    /**
     * @return string The absolute URL to the asset in this container
     */
    abstract public function getAbsoluteURL();

    /**
     * Get metadata for this file
     *
     * @return array|null File information
     */
    abstract public function getMetaData();

    /**
     * Get mime type
     *
     * @return string Mime type for this file
     */
    abstract public function getMimeType();

    /**
     * Return file size in bytes.
     *
     * @return int
     */
    abstract public function getAbsoluteSize();

    /**
     * Determine if this container has a valid value
     *
     * @return bool Flag as to whether the file exists
     */
    abstract public function exists();

    /**
     * Get value of filename
     *
     * @return string
     */
    abstract public function getFilename();

    /**
     * Get value of hash
     *
     * @return string
     */
    abstract public function getHash();

    /**
     * Get value of variant
     *
     * @return string
     */
    abstract public function getVariant();

    /**
     * Determine if a valid non-empty image exists behind this asset
     *
     * @return bool
     */
    abstract public function getIsImage();

    /**
     * Force all images to resample in all cases
     * Off by default, as this can be resource intensive to apply to multiple images simultaneously.
     *
     * @config
     * @var bool
     */
    private static $force_resample = false;

    /**
     * @config
     * @var int The width of an image thumbnail in a strip.
     */
    private static $strip_thumbnail_width = 50;

    /**
     * @config
     * @var int The height of an image thumbnail in a strip.
     */
    private static $strip_thumbnail_height = 50;

    /**
     * The width of an image thumbnail in the CMS.
     *
     * @config
     * @var int
     */
    private static $cms_thumbnail_width = 100;

    /**
     * The height of an image thumbnail in the CMS.
     *
     * @config
     * @var int
     */
    private static $cms_thumbnail_height = 100;

    /**
     * The width of an image preview in the Asset section
     *
     * @config
     * @var int
     */
    private static $asset_preview_width = 930; // max for mobile full-width

    /**
     * The height of an image preview in the Asset section
     *
     * @config
     * @var int
     */
    private static $asset_preview_height = 336;

    /**
     * Fit image to specified dimensions and fill leftover space with a solid colour (default white). Use in
     * templates with $Pad.
     *
     * @param int $width The width to size to
     * @param int $height The height to size to
     * @param string $backgroundColor
     * @param int $transparencyPercent Level of transparency
     * @return AssetContainer
     */
    public function Pad($width, $height, $backgroundColor = 'FFFFFF', $transparencyPercent = 0)
    {
        $width = $this->castDimension($width, 'Width');
        $height = $this->castDimension($height, 'Height');
        $variant = $this->variantName(__FUNCTION__, $width, $height, $backgroundColor, $transparencyPercent);
        return $this->manipulateImage(
            $variant,
            function (Image_Backend $backend) use ($width, $height, $backgroundColor, $transparencyPercent) {
                if ($backend->getWidth() === $width && $backend->getHeight() === $height) {
                    return $this;
                }
                return $backend->paddedResize($width, $height, $backgroundColor, $transparencyPercent);
            }
        );
    }

    /**
     * Forces the image to be resampled, if possible
     *
     * @return AssetContainer
     */
    public function Resampled()
    {
        // If image is already resampled, return self reference
        $variant = $this->getVariant();
        if ($variant) {
            return $this;
        }

        // Resample, but fallback to original object
        $result = $this->manipulateImage(__FUNCTION__, function (Image_Backend $backend) {
            return $backend;
        });
        if ($result) {
            return $result;
        }
        return $this;
    }

    /**
     * Update the url to point to a resampled version if forcing
     *
     * @param string $url
     */
    public function updateURL(&$url)
    {
        // Skip if resampling is off, or is already resampled, or is not an image
        if (!Config::inst()->get(get_class($this), 'force_resample') || $this->getVariant() || !$this->getIsImage()) {
            return;
        }

        // Attempt to resample
        $resampled = $this->Resampled();
        if (!$resampled) {
            return;
        }

        // Only update if resampled file is a smaller file size
        if ($resampled->getAbsoluteSize() < $this->getAbsoluteSize()) {
            $url = $resampled->getURL();
        }
    }


    /**
     * Generate a resized copy of this image with the given width & height.
     * This can be used in templates with $ResizedImage but should be avoided,
     * as it's the only image manipulation function which can skew an image.
     *
     * @param int $width Width to resize to
     * @param int $height Height to resize to
     * @return AssetContainer
     */
    public function ResizedImage($width, $height)
    {
        $width = $this->castDimension($width, 'Width');
        $height = $this->castDimension($height, 'Height');
        $variant = $this->variantName(__FUNCTION__, $width, $height);
        return $this->manipulateImage($variant, function (Image_Backend $backend) use ($width, $height) {
            if ($backend->getWidth() === $width && $backend->getHeight() === $height) {
                return $this;
            }
            return $backend->resize($width, $height);
        });
    }

    /**
     * Scale image proportionally to fit within the specified bounds
     *
     * @param int $width The width to size within
     * @param int $height The height to size within
     * @return AssetContainer
     */
    public function Fit($width, $height)
    {
        $width = $this->castDimension($width, 'Width');
        $height = $this->castDimension($height, 'Height');
        // Item must be regenerated
        $variant = $this->variantName(__FUNCTION__, $width, $height);
        return $this->manipulateImage($variant, function (Image_Backend $backend) use ($width, $height) {
            // Check if image is already sized to the correct dimension
            $currentWidth = $backend->getWidth();
            $currentHeight = $backend->getHeight();
            if (!$currentWidth || !$currentHeight) {
                return null;
            }
            $widthRatio = $width / $currentWidth;
            $heightRatio = $height / $currentHeight;

            if ($widthRatio < $heightRatio) {
                // Target is higher aspect ratio than image, so check width
                if ($currentWidth === $width) {
                    return $this;
                }
            } else {
                // Target is wider or same aspect ratio as image, so check height
                if ($currentHeight === $height) {
                    return $this;
                }
            }

            return $backend->resizeRatio($width, $height);
        });
    }

    /**
     * Proportionally scale down this image if it is wider or taller than the specified dimensions.
     * Similar to Fit but without up-sampling. Use in templates with $FitMax.
     *
     * @uses ScalingManipulation::Fit()
     * @param int $width The maximum width of the output image
     * @param int $height The maximum height of the output image
     * @return AssetContainer
     */
    public function FitMax($width, $height)
    {
        $width = $this->castDimension($width, 'Width');
        $height = $this->castDimension($height, 'Height');
        $variant = $this->variantName(__FUNCTION__, $width, $height);
        return $this->manipulateImage($variant, function (Image_Backend $backend) use ($width, $height) {
            // Check if image is already sized to the correct dimension
            $currentWidth = $backend->getWidth();
            $currentHeight = $backend->getHeight();
            if (!$currentWidth || !$currentHeight) {
                return null;
            }

            // Check if inside bounds
            if ($currentWidth <= $width && $currentHeight <= $height) {
                return $this;
            }

            $widthRatio = $width / $currentWidth;
            $heightRatio = $height / $currentHeight;

            if ($widthRatio < $heightRatio) {
                // Target is higher aspect ratio than image, so check width
                if ($currentWidth === $width) {
                    return $this;
                }
            } else {
                // Target is wider or same aspect ratio as image, so check height
                if ($currentHeight === $height) {
                    return $this;
                }
            }

            return $backend->resizeRatio($width, $height);
        });
    }


    /**
     * Scale image proportionally by width. Use in templates with $ScaleWidth.
     *
     * @param int $width The width to set
     * @return AssetContainer
     */
    public function ScaleWidth($width)
    {
        $width = $this->castDimension($width, 'Width');
        $variant = $this->variantName(__FUNCTION__, $width);
        return $this->manipulateImage($variant, function (Image_Backend $backend) use ($width) {
            if ($backend->getWidth() === $width) {
                return $this;
            }
            return $backend->resizeByWidth($width);
        });
    }

    /**
     * Proportionally scale down this image if it is wider than the specified width.
     * Similar to ScaleWidth but without up-sampling. Use in templates with $ScaleMaxWidth.
     *
     * @uses ScalingManipulation::ScaleWidth()
     * @param int $width The maximum width of the output image
     * @return AssetContainer
     */
    public function ScaleMaxWidth($width)
    {
        $width = $this->castDimension($width, 'Width');
        $variant = $this->variantName(__FUNCTION__, $width);
        return $this->manipulateImage($variant, function (Image_Backend $backend) use ($width) {
            if ($backend->getWidth() <= $width) {
                return $this;
            }
            return $backend->resizeByWidth($width);
        });
    }

    /**
     * Scale image proportionally by height. Use in templates with $ScaleHeight.
     *
     * @param int $height The height to set
     * @return AssetContainer
     */
    public function ScaleHeight($height)
    {
        $height = $this->castDimension($height, 'Height');
        $variant = $this->variantName(__FUNCTION__, $height);
        return $this->manipulateImage($variant, function (Image_Backend $backend) use ($height) {
            if ($backend->getHeight() === $height) {
                return $this;
            }
            return $backend->resizeByHeight($height);
        });
    }

    /**
     * Proportionally scale down this image if it is taller than the specified height.
     * Similar to ScaleHeight but without up-sampling. Use in templates with $ScaleMaxHeight.
     *
     * @uses ScalingManipulation::ScaleHeight()
     * @param int $height The maximum height of the output image
     * @return AssetContainer
     */
    public function ScaleMaxHeight($height)
    {
        $height = $this->castDimension($height, 'Height');
        $variant = $this->variantName(__FUNCTION__, $height);
        return $this->manipulateImage($variant, function (Image_Backend $backend) use ($height) {
            if ($backend->getHeight() <= $height) {
                return $this;
            }
            return $backend->resizeByHeight($height);
        });
    }


    /**
     * Crop image on X axis if it exceeds specified width. Retain height.
     * Use in templates with $CropWidth. Example: $Image.ScaleHeight(100).$CropWidth(100)
     *
     * @uses CropManipulation::Fill()
     * @param int $width The maximum width of the output image
     * @return AssetContainer
     */
    public function CropWidth($width)
    {
        $variant = $this->variantName(__FUNCTION__, $width);
        return $this->manipulateImage($variant, function (Image_Backend $backend) use ($width) {
            // Already within width
            if ($backend->getWidth() <= $width) {
                return $this;
            }

            // Crop to new width (same height)
            return $backend->croppedResize($width, $backend->getHeight());
        });
    }

    /**
     * Crop image on Y axis if it exceeds specified height. Retain width.
     * Use in templates with $CropHeight. Example: $Image.ScaleWidth(100).CropHeight(100)
     *
     * @uses CropManipulation::Fill()
     * @param int $height The maximum height of the output image
     * @return AssetContainer
     */
    public function CropHeight($height)
    {
        $variant = $this->variantName(__FUNCTION__, $height);
        return $this->manipulateImage($variant, function (Image_Backend $backend) use ($height) {
            // Already within height
            if ($backend->getHeight() <= $height) {
                return $this;
            }

            // Crop to new height (same width)
            return $backend->croppedResize($backend->getWidth(), $height);
        });
    }

    /**
     * Crop this image to the aspect ratio defined by the specified width and height,
     * then scale down the image to those dimensions if it exceeds them.
     * Similar to Fill but without up-sampling. Use in templates with $FillMax.
     *
     * @uses ImageManipulation::Fill()
     * @param int $width The relative (used to determine aspect ratio) and maximum width of the output image
     * @param int $height The relative (used to determine aspect ratio) and maximum height of the output image
     * @return AssetContainer
     */
    public function FillMax($width, $height)
    {
        $width = $this->castDimension($width, 'Width');
        $height = $this->castDimension($height, 'Height');
        $variant = $this->variantName(__FUNCTION__, $width, $height);
        return $this->manipulateImage($variant, function (Image_Backend $backend) use ($width, $height) {
            // Validate dimensions
            $currentWidth = $backend->getWidth();
            $currentHeight = $backend->getHeight();
            if (!$currentWidth || !$currentHeight) {
                return null;
            }
            if ($currentWidth === $width && $currentHeight === $height) {
                return $this;
            }

            // Compare current and destination aspect ratios
            $imageRatio = $currentWidth / $currentHeight;
            $cropRatio = $width / $height;
            if ($cropRatio < $imageRatio && $currentHeight < $height) {
                // Crop off sides
                return $backend->croppedResize(round($currentHeight * $cropRatio), $currentHeight);
            } elseif ($currentWidth < $width) {
                // Crop off top/bottom
                return $backend->croppedResize($currentWidth, round($currentWidth / $cropRatio));
            } else {
                // Crop on both
                return $backend->croppedResize($width, $height);
            }
        });
    }

    /**
     * Resize and crop image to fill specified dimensions.
     * Use in templates with $Fill
     *
     * @param int $width Width to crop to
     * @param int $height Height to crop to
     * @return AssetContainer
     */
    public function Fill($width, $height)
    {
        $width = $this->castDimension($width, 'Width');
        $height = $this->castDimension($height, 'Height');
        $variant = $this->variantName(__FUNCTION__, $width, $height);
        return $this->manipulateImage($variant, function (Image_Backend $backend) use ($width, $height) {
            if ($backend->getWidth() === $width && $backend->getHeight() === $height) {
                return $this;
            }
            return $backend->croppedResize($width, $height);
        });
    }

    /**
     * Default CMS thumbnail
     *
     * @return DBFile|DBHTMLText Either a resized thumbnail, or html for a thumbnail icon
     */
    public function CMSThumbnail()
    {
        $width = (int)Config::inst()->get(__CLASS__, 'cms_thumbnail_width');
        $height = (int)Config::inst()->get(__CLASS__, 'cms_thumbnail_height');
        return $this->ThumbnailIcon($width, $height);
    }

    /**
     * Generates a thumbnail for use in the gridfield view
     *
     * @return AssetContainer|DBHTMLText Either a resized thumbnail, or html for a thumbnail icon
     */
    public function StripThumbnail()
    {
        $width = (int)Config::inst()->get(__CLASS__, 'strip_thumbnail_width');
        $height = (int)Config::inst()->get(__CLASS__, 'strip_thumbnail_height');
        return $this->ThumbnailIcon($width, $height);
    }

    /**
     * Get preview for this file
     *
     * @return AssetContainer|DBHTMLText Either a resized thumbnail, or html for a thumbnail icon
     */
    public function PreviewThumbnail()
    {
        $width = (int)Config::inst()->get(__CLASS__, 'asset_preview_width');
        return $this->ScaleMaxWidth($width)  ?: $this->IconTag();
    }

    /**
     * Default thumbnail generation for Images
     *
     * @param int $width
     * @param int $height
     * @return AssetContainer
     */
    public function Thumbnail($width, $height)
    {
        return $this->Fill($width, $height);
    }

    /**
     * Thubnail generation for all file types.
     *
     * Resizes images, but returns an icon <img /> tag if this is not a resizable image
     *
     * @param int $width
     * @param int $height
     * @return AssetContainer|DBHTMLText
     */
    public function ThumbnailIcon($width, $height)
    {
        return $this->Thumbnail($width, $height) ?: $this->IconTag();
    }

    /**
     * Get HTML for img containing the icon for this file
     *
     * @return DBHTMLText
     */
    public function IconTag()
    {
        /** @var DBHTMLText $image */
        $image = DBField::create_field(
            'HTMLFragment',
            HTML::createTag('img', ['src' => $this->getIcon()])
        );
        return $image;
    }

    /**
     * Get URL to thumbnail of the given size.
     *
     * May fallback to default icon
     *
     * @param int $width
     * @param int $height
     * @return string
     */
    public function ThumbnailURL($width, $height)
    {
        $thumbnail = $this->Thumbnail($width, $height);
        if ($thumbnail) {
            return $thumbnail->getURL();
        }
        return $this->getIcon();
    }

    /**
     * Return the relative URL of an icon for the file type,
     * based on the {@link appCategory()} value.
     * Images are searched for in "framework/images/app_icons/".
     *
     * @return string URL to icon
     */
    public function getIcon()
    {
        $filename = $this->getFilename();
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        return File::get_icon_for_extension($ext);
    }

    /**
     * Get Image_Backend instance for this image
     *
     * @return Image_Backend
     */
    public function getImageBackend()
    {
        // Skip files we know won't be an image
        if (!$this->getIsImage() || !$this->getHash()) {
            return null;
        }

        // Pass to backend service factory
        try {
            return Injector::inst()->createWithArgs(Image_Backend::class, array($this));
        } catch (InjectorNotFoundException $ex) {
            // Handle file-not-found errors
            return null;
        }
    }

    /**
     * Get the width of this image.
     *
     * @return int
     */
    public function getWidth()
    {
        $backend = $this->getImageBackend();
        if ($backend) {
            return $backend->getWidth();
        }
        return 0;
    }

    /**
     * Get the height of this image.
     *
     * @return int
     */
    public function getHeight()
    {
        $backend = $this->getImageBackend();
        if ($backend) {
            return $backend->getHeight();
        }
        return 0;
    }

    /**
     * Get the orientation of this image.
     *
     * @return int ORIENTATION_SQUARE | ORIENTATION_PORTRAIT | ORIENTATION_LANDSCAPE
     */
    public function getOrientation()
    {
        $width = $this->getWidth();
        $height = $this->getHeight();
        if ($width > $height) {
            return Image_Backend::ORIENTATION_LANDSCAPE;
        } elseif ($height > $width) {
            return Image_Backend::ORIENTATION_PORTRAIT;
        } else {
            return Image_Backend::ORIENTATION_SQUARE;
        }
    }

    /**
     * Determine if this image is of the specified size
     *
     * @param int $width Width to check
     * @param int $height Height to check
     * @return boolean
     */
    public function isSize($width, $height)
    {
        return $this->isWidth($width) && $this->isHeight($height);
    }

    /**
     * Determine if this image is of the specified width
     *
     * @param int $width Width to check
     * @return boolean
     */
    public function isWidth($width)
    {
        $width = $this->castDimension($width, 'Width');
        return $this->getWidth() === $width;
    }

    /**
     * Determine if this image is of the specified width
     *
     * @param int $height Height to check
     * @return boolean
     */
    public function isHeight($height)
    {
        $height = $this->castDimension($height, 'Height');
        return $this->getHeight() === $height;
    }

    /**
     * Wrapper for manipulate that passes in and stores Image_Backend objects instead of tuples
     *
     * @param string $variant
     * @param callable $callback Callback which takes an Image_Backend object, and returns an Image_Backend result.
     * If this callback returns `true` then the current image will be duplicated without modification.
     * @return DBFile The manipulated file
     */
    public function manipulateImage($variant, $callback)
    {
        return $this->manipulate(
            $variant,
            function (AssetStore $store, $filename, $hash, $variant) use ($callback) {
                /** @var Image_Backend $backend */
                $backend = $this->getImageBackend();

                // If backend isn't available
                if (!$backend || !$backend->getImageResource()) {
                    return null;
                }

                // Delegate to user manipulation
                $result = $callback($backend);

                // Empty result means no image generated
                if (!$result) {
                    return null;
                }

                // Write from another container
                if ($result instanceof AssetContainer) {
                    try {
                        return $store->setFromStream($result->getStream(), $filename, $hash, $variant);
                    } finally {
                        gc_collect_cycles();
                    }
                }

                // Write from modified backend
                if ($result instanceof Image_Backend) {
                    try {
                        /** @var Image_Backend $result */
                        return $result->writeToStore(
                            $store,
                            $filename,
                            $hash,
                            $variant,
                            array('conflict' => AssetStore::CONFLICT_USE_EXISTING)
                        );
                    } finally {
                        gc_collect_cycles();
                    }
                }

                // Unknown result from callback
                throw new LogicException("Invalid manipulation result");
            }
        );
    }

    /**
     * Generate a new DBFile instance using the given callback if it hasn't been created yet, or
     * return the existing one if it has.
     *
     * @param string $variant name of the variant to create
     * @param callable $callback Callback which should return a new tuple as an array.
     * This callback will be passed the backend, filename, hash, and variant
     * This will not be called if the file does not
     * need to be created.
     * @return DBFile The manipulated file
     */
    public function manipulate($variant, $callback)
    {
        // Verify this manipulation is applicable to this instance
        if (!$this->exists()) {
            return null;
        }

        // Build output tuple
        $filename = $this->getFilename();
        $hash = $this->getHash();
        $existingVariant = $this->getVariant();
        if ($existingVariant) {
            $variant = $existingVariant . '_' . $variant;
        }

        // Skip empty files (e.g. Folder does not have a hash)
        if (empty($filename) || empty($hash)) {
            return null;
        }

        // Create this asset in the store if it doesn't already exist,
        // otherwise use the existing variant
        $store = Injector::inst()->get(AssetStore::class);
        $result = null;
        if (!$store->exists($filename, $hash, $variant)) {
            // Circumvent generation of thumbnails if we only want to get existing ones
            if (!$this->getAllowGeneration()) {
                return null;
            }
            $result = call_user_func($callback, $store, $filename, $hash, $variant);
        } else {
            $result = array(
                'Filename' => $filename,
                'Hash' => $hash,
                'Variant' => $variant
            );
        }

        // Callback may fail to perform this manipulation (e.g. resize on text file)
        if (!$result) {
            return null;
        }

        // Store result in new DBFile instance
        /** @var DBFile $file */
        $file = DBField::create_field('DBFile', $result);
        return $file->setOriginal($this);
    }

    /**
     * Name a variant based on a format with arbitrary parameters
     *
     * @param string $format The format name.
     * @param mixed $arg,... Additional arguments
     * @return string
     * @throws InvalidArgumentException
     */
    public function variantName($format, $arg = null)
    {
        $args = func_get_args();
        array_shift($args);
        return $format . Convert::base64url_encode($args);
    }

    /**
     * Validate a width or size is valid and casts it to integer
     *
     * @param mixed $value value of dimension
     * @param string $dimension Name of dimension
     * @return int Validated value
     */
    protected function castDimension($value, $dimension)
    {
        // Check type
        if (!is_numeric($value)) {
            throw new InvalidArgumentException("{$dimension} must be a numeric value");
        }

        // Cast to integer
        $value = intval($value);

        // Check empty
        if (empty($value)) {
            throw new InvalidArgumentException("{$dimension} is required");
        }
        // Check flag
        if ($value < 1) {
            throw new InvalidArgumentException("{$dimension} must be positive");
        }
        return $value;
    }
}
