<?php

namespace SilverStripe\Assets;

use BadMethodCallException;
use Intervention\Image\Constraint;
use Intervention\Image\Exception\NotReadableException;
use Intervention\Image\Exception\NotSupportedException;
use Intervention\Image\Exception\NotWritableException;
use Intervention\Image\Image as InterventionImage;
use Intervention\Image\ImageManager;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;

class InterventionBackend implements Image_Backend, Flushable
{

    /**
     * @var AssetContainer
     */
    private $container;

    /**
     * @var InterventionImage
     */
    private $image;

    /**
     * @var int
     */
    private $quality;

    /**
     * @var ImageManager
     */
    private $manager;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var string
     */
    private $tempPath;

    public function __construct(AssetContainer $assetContainer = null)
    {
        $this->setAssetContainer($assetContainer);
    }

    /**
     * @return string The temporary local path for this image
     */
    public function getTempPath()
    {
        return $this->tempPath;
    }

    /**
     * @param string $path
     *
     * @return $this
     */
    public function setTempPath($path)
    {
        $this->tempPath = $path;
        return $this;
    }

    /**
     * @return CacheInterface
     */
    public function getCache()
    {
        if (!$this->cache) {
            $this->setCache(Injector::inst()->get(CacheInterface::class . '.InterventionBackend_Manipulations'));
        }
        return $this->cache;
    }

    /**
     * @param CacheInterface $cache
     *
     * @return $this
     */
    public function setCache($cache)
    {
        $this->cache = $cache;
        return $this;
    }

    /**
     * @return AssetContainer
     */
    public function getAssetContainer()
    {
        return $this->container;
    }

    /**
     * @param AssetContainer $assetContainer
     *
     * @return $this
     */
    public function setAssetContainer($assetContainer)
    {
        $this->container = $assetContainer;
        return $this;
    }

    /**
     * @return ImageManager
     */
    public function getImageManager()
    {
        if (!$this->manager) {
            $this->setImageManager(Injector::inst()->create(ImageManager::class));
        }
        return $this->manager;
    }

    /**
     * @param ImageManager $manager
     *
     * @return $this
     */
    public function setImageManager($manager)
    {
        $this->manager = $manager;
        return $this;
    }

    /**
     * Populate the backend with a given object
     *
     * @param AssetContainer $assetContainer Object to load from
     * @return $this
     */
    public function loadFromContainer(AssetContainer $assetContainer)
    {
        // Avoid repeat load of broken images
        $hash = $assetContainer->getHash();
        if ($this->hasFailed($hash)) {
            return $this;
        }

        // Handle resource
        try {
            $this->markStart($hash);
            // write the file to a local path so we can extract exif data if it exists.
            // Currently exif data can only be read from file paths and not streams
            $path = tempnam(TEMP_FOLDER, 'interventionimage_');
            if ($extension = pathinfo($assetContainer->getFilename(), PATHINFO_EXTENSION)) {
                //tmpnam creates a file, we should clean it up if we are changing the path name
                unlink($path);
                $path .= "." . $extension;
            }
            $bytesWritten = file_put_contents($path, $assetContainer->getStream());
            // if we fail to write, then load from stream
            if ($bytesWritten === false) {
                $this->setImageResource($this->getImageManager()->make($assetContainer->getStream()));
            } else {
                $this->setTempPath($path);
                $this->setImageResource($this->getImageManager()->make($path));
            }
            $this->markEnd($hash);
        } catch (NotReadableException $ex) {
            // Handle unsupported image encoding on load (will be marked as failed)
        }
        return $this;
    }

    /**
     * Populate the backend from a local path
     *
     * @param string $path
     * @return $this
     */
    public function loadFrom($path)
    {
        // Avoid repeat load of broken images
        $hash = sha1($path);
        if ($this->hasFailed($hash)) {
            return $this;
        }

        // Handle resource
        try {
            $this->markStart($hash);
            $this->setImageResource($this->getImageManager()->make($path));
            $this->markEnd($hash);
        } catch (NotReadableException $ex) {
            // Handle unsupported image encoding on load (will be marked as failed)
        }

        return $this;
    }

    /**
     * Get the currently assigned image resource
     * Note: This method may return null if error
     *
     * @return InterventionImage
     */
    public function getImageResource()
    {
        if (!$this->image && $this->getAssetContainer()) {
            $this->loadFromContainer($this->getAssetContainer());
        }
        return $this->image;
    }

    /**
     * @param InterventionImage $image
     * @return $this
     */
    public function setImageResource($image)
    {
        $this->image = $image;
        return $this;
    }

    /**
     * Write to the given asset store
     *
     * @param AssetStore $assetStore
     * @param string $filename Name for the resulting file
     * @param string $hash Hash of original file, if storing a variant.
     * @param string $variant Name of variant, if storing a variant.
     * @param array $config Write options. {@see AssetStore}
     * @return array Tuple associative array (Filename, Hash, Variant) Unless storing a variant, the hash
     * will be calculated from the given data.
     * @throws BadMethodCallException If image isn't valid
     */
    public function writeToStore(AssetStore $assetStore, $filename, $hash = null, $variant = null, $config = array())
    {
        try {
            $this->getImageResource()->orientate();
        } catch (NotSupportedException $e) {
            // noop - we can't orientate, don't worry about it
        }
        try {
            $resource = $this->getImageResource();
            if (!$resource) {
                throw new BadMethodCallException("Cannot write corrupt file to store");
            }

            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            return $assetStore->setFromString(
                $resource->encode($extension, $this->getQuality())->getEncoded(),
                $filename,
                $hash,
                $variant,
                $config
            );
        } catch (NotSupportedException $e) {
            return null;
        }
    }

    /**
     * Write the backend to a local path
     *
     * @param string $path
     * @return bool If the writing was successful
     * @throws BadMethodCallException If image isn't valid
     */
    public function writeTo($path)
    {
        try {
            $resource = $this->getImageResource();
            if (!$resource) {
                throw new BadMethodCallException("Cannot write corrupt file to store");
            }
            $resource->save($path, $this->getQuality());
        } catch (NotWritableException $e) {
            return false;
        }
        return true;
    }

    /**
     * @return int
     */
    public function getQuality()
    {
        return $this->quality;
    }

    /**
     * @return int The width of the image
     */
    public function getWidth()
    {
        $resource = $this->getImageResource();
        if ($resource) {
            return $resource->getWidth();
        }
        return 0;
    }

    /**
     * @return int The height of the image
     */
    public function getHeight()
    {
        $resource = $this->getImageResource();
        if ($resource) {
            return $resource->getHeight();
        }
        return 0;
    }

    /**
     * Set the quality to a value between 0 and 100
     *
     * @param int $quality
     * @return $this
     */
    public function setQuality($quality)
    {
        $this->quality = $quality;
        return $this;
    }

    /**
     * Resize an image, skewing it as necessary.
     *
     * @param int $width
     * @param int $height
     * @return static
     */
    public function resize($width, $height)
    {
        $resource = $this->getImageResource();
        if (!$resource) {
            return null;
        }
        return $this->createCloneWithResource($resource->resize($width, $height));
    }

    /**
     * Resize the image by preserving aspect ratio. By default, it will keep the image inside the maxWidth
     * and maxHeight. Passing useAsMinimum will make the smaller dimension equal to the maximum corresponding dimension
     *
     * @param int $width
     * @param int $height
     * @param bool $useAsMinimum If true, image will be sized outside of these dimensions.
     * If false (default) image will be sized inside these dimensions.
     * @return static
     */
    public function resizeRatio($width, $height, $useAsMinimum = false)
    {
        $resource = $this->getImageResource();
        if (!$resource) {
            return null;
        }
        return $this->createCloneWithResource($resource->resize(
            $width,
            $height,
            function (Constraint $constraint) use ($useAsMinimum) {
                $constraint->aspectRatio();
                if (!$useAsMinimum) {
                    $constraint->upsize();
                }
            }
        ));
    }

    /**
     * Resize an image by width. Preserves aspect ratio.
     *
     * @param int $width
     * @return static
     */
    public function resizeByWidth($width)
    {
        $resource = $this->getImageResource();
        if (!$resource) {
            return null;
        }
        return $this->createCloneWithResource($resource->widen($width));
    }

    /**
     * Resize an image by height. Preserves aspect ratio.
     *
     * @param int $height
     * @return static
     */
    public function resizeByHeight($height)
    {
        $resource = $this->getImageResource();
        if (!$resource) {
            return null;
        }
        return $this->createCloneWithResource($resource->heighten($height));
    }

    /**
     * Return a clone of this image resized, with space filled in with the given colour
     *
     * @param int $width
     * @param int $height
     * @param string $backgroundColor
     * @param int $transparencyPercent
     * @return static
     */
    public function paddedResize($width, $height, $backgroundColor = "FFFFFF", $transparencyPercent = 0)
    {
        $resource = $this->getImageResource();
        if (!$resource) {
            return null;
        }

        // caclulate the background colour
        $background = $resource->getDriver()->parseColor($backgroundColor)->format('array');
        // convert transparancy % to alpha
        $background[3] = 1 - round(min(100, max(0, $transparencyPercent)) / 100, 2);

        // resize the image maintaining the aspect ratio and then pad out the canvas
        return $this->createCloneWithResource($resource->resize($width, $height, function (Constraint $constraint) {
            $constraint->aspectRatio();
        })->resizeCanvas(
            $width,
            $height,
            'center',
            false,
            $background
        ));
    }

    /**
     * Resize an image to cover the given width/height completely, and crop off any overhanging edges.
     *
     * @param int $width
     * @param int $height
     * @return static
     */
    public function croppedResize($width, $height)
    {
        $resource = $this->getImageResource();
        if (!$resource) {
            return null;
        }
        return $this->createCloneWithResource($resource->fit($width, $height));
    }

    /**
     * Crop's part of image.
     * @param int $top y position of left upper corner of crop rectangle
     * @param int $left x position of left upper corner of crop rectangle
     * @param int $width rectangle width
     * @param int $height rectangle height
     * @return Image_Backend
     */
    public function crop($top, $left, $width, $height)
    {
        $resource = $this->getImageResource();
        if (!$resource) {
            return null;
        }
        return $this->createCloneWithResource($resource->crop($width, $height, $left, $top));
    }

    /**
     * @param InterventionImage $resource
     * @return static
     */
    protected function createCloneWithResource($resource)
    {
        $clone = clone $this;
        $clone->setImageResource(clone $resource);
        return $clone;
    }

    protected function markStart($hash)
    {
        return $this->getCache()->set($hash, 1);
    }

    protected function markEnd($hash)
    {
        return $this->getCache()->delete($hash);
    }

    protected function hasFailed($hash)
    {
        return $this->getCache()->has($hash);
    }

    /**
     * Make sure we clean up the image resource when this object is destroyed
     */
    public function __destruct()
    {
        //skip the `getImageResource` method because we don't want to load the resource just to destroy it
        if ($this->image) {
            $this->image->destroy();
        }
        // remove our temp file if it exists
        if (file_exists($this->getTempPath())) {
            unlink($this->getTempPath());
        }
    }

    /**
     * This function is triggered early in the request if the "flush" query
     * parameter has been set. Each class that implements Flushable implements
     * this function which looks after it's own specific flushing functionality.
     *
     * @see FlushRequestFilter
     */
    public static function flush()
    {
        /** @var CacheInterface $cache */
        $cache = Injector::inst()->get(CacheInterface::class . '.InterventionBackend_Manipulations');
        $cache->clear();
    }
}
