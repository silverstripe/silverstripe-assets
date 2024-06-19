<?php

namespace SilverStripe\Assets;

use BadMethodCallException;
use Intervention\Image\Constraint;
use Intervention\Image\Exception\NotReadableException;
use Intervention\Image\Exception\NotSupportedException;
use Intervention\Image\Exception\NotWritableException;
use Intervention\Image\Image as InterventionImage;
use Intervention\Image\ImageManager;
use Intervention\Image\Size;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\StreamInterface;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Config\Config;

class InterventionBackend implements Image_Backend, Flushable
{
    use Configurable;

    /**
     * Cache prefix for marking
     */
    const CACHE_MARK = 'MARK_';

    /**
     * Cache prefix for dimensions
     */
    const CACHE_DIMENSIONS = 'DIMENSIONS_';

    /**
     * Is cache flushing enabled?
     *
     * @config
     * @var boolean
     */
    private static $flush_enabled = true;

    /**
     * How long to cache each error type
     *
     * @config
     * @var array Map of error type to config.
     * each config could be a single int (fixed cache time)
     * or list of integers (increasing scale)
     */
    private static $error_cache_ttl = [
        InterventionBackend::FAILED_INVALID => 0, // Invalid file type should probably never be retried
        InterventionBackend::FAILED_MISSING => '5,10,20,40,80', // Missing files may be eventually available
        InterventionBackend::FAILED_UNKNOWN => 300, // Unknown (edge case). Maybe system error? Needs a flush?
    ];

    /**
     * This file is invalid because it is not image data, or it cannot
     * be processed by the given backend
     */
    const FAILED_INVALID = 'invalid';

    /**
     * This file is invalid as it is missing from the filesystem
     */
    const FAILED_MISSING = 'missing';

    /**
     * Some unknown error
     */
    const FAILED_UNKNOWN = 'unknown';

    /**
     * Configure where cached intervention files will be stored
     *
     * @config
     * @var string
     */
    private static $local_temp_path = TEMP_PATH;

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
        $this->setImageResource(null);
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
        return $this->setAssetContainer($assetContainer);
    }

    /**
     * Get the currently assigned image resource, or generates one if not yet assigned.
     * Note: This method may return null if error
     *
     * @return InterventionImage
     */
    public function getImageResource()
    {
        // Get existing resource
        if ($this->image) {
            return $this->image;
        }

        // Load container
        $assetContainer = $this->getAssetContainer();
        if (!$assetContainer) {
            return null;
        }

        // Avoid repeat load of broken images
        $hash = $assetContainer->getHash();
        $variant = $assetContainer->getVariant();
        if ($this->hasFailed($hash, $variant)) {
            return null;
        }

        // Validate stream is readable
        // Note: Mark failed regardless of whether a failed stream is exceptional or not
        $error = InterventionBackend::FAILED_MISSING;
        try {
            $stream = $assetContainer->getStream();
            if ($this->isStreamReadable($stream)) {
                $error = null;
            } else {
                return null;
            }
        } finally {
            if ($error) {
                $this->markFailed($hash, $variant, $error);
            }
        }

        // Handle resource
        $error = InterventionBackend::FAILED_UNKNOWN;
        try {
            // write the file to a local path so we can extract exif data if it exists.
            // Currently exif data can only be read from file paths and not streams
            $tempPath = $this->config()->get('local_temp_path') ?? TEMP_PATH;
            $path = tempnam($tempPath ?? '', 'interventionimage_');
            if ($extension = pathinfo($assetContainer->getFilename() ?? '', PATHINFO_EXTENSION)) {
                //tmpnam creates a file, we should clean it up if we are changing the path name
                unlink($path ?? '');
                $path .= "." . $extension;
            }
            $bytesWritten = file_put_contents($path ?? '', $stream);
            // if we fail to write, then load from stream
            if ($bytesWritten === false) {
                $resource = $this->getImageManager()->make($stream);
            } else {
                $this->setTempPath($path);
                $resource = $this->getImageManager()->make($path);
            }

            // Fix image orientation
            try {
                $resource->orientate();
            } catch (NotSupportedException $e) {
                // noop - we can't orientate, don't worry about it
            }

            $this->setImageResource($resource);
            $this->markSuccess($hash, $variant);
            $this->warmCache($hash, $variant);
            $error = null;
            return $resource;
        } catch (NotReadableException $ex) {
            // Handle unsupported image encoding on load (will be marked as failed)
            // Unsupported exceptions are handled without being raised as exceptions
            $error = InterventionBackend::FAILED_INVALID;
        } finally {
            if ($error) {
                $this->markFailed($hash, $variant, $error);
            }
        }
        return null;
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
        $hash = sha1($path ?? '');
        if ($this->hasFailed($hash, null)) {
            return $this;
        }

        // Handle resource
        $error = InterventionBackend::FAILED_UNKNOWN;
        try {
            $this->setImageResource($this->getImageManager()->make($path));
            $this->markSuccess($hash, null);
            $error = null;
        } catch (NotReadableException $ex) {
            // Handle unsupported image encoding on load (will be marked as failed)
            // Unsupported exceptions are handled without being raised as exceptions
            $error = InterventionBackend::FAILED_INVALID;
        } finally {
            if ($error) {
                $this->markFailed($hash, null, $error);
            }
        }

        return $this;
    }

    /**
     * @param InterventionImage $image
     * @return $this
     */
    public function setImageResource($image)
    {
        $this->image = $image;
        if ($image === null) {
            // remove our temp file if it exists
            if (file_exists($this->getTempPath() ?? '')) {
                unlink($this->getTempPath());
            }
        }
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
    public function writeToStore(AssetStore $assetStore, $filename, $hash = null, $variant = null, $config = [])
    {
        try {
            $resource = $this->getImageResource();
            if (!$resource) {
                throw new BadMethodCallException("Cannot write corrupt file to store");
            }

            // Make sure we're using the extension of the variant file, which can differ from the original file
            $url = $assetStore->getAsURL($filename, $hash, $variant, false);
            $extension = pathinfo($url, PATHINFO_EXTENSION);
            // Save file
            $result = $assetStore->setFromString(
                $resource->encode($extension, $this->getQuality())->getEncoded(),
                $filename,
                $hash,
                $variant,
                $config
            );

            // Warm cache for the result
            if ($result) {
                $this->warmCache($result['Hash'], $result['Variant']);
            }

            return $result;
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
     * Return dimensions as array with cache enabled
     *
     * @return array Two-length array with width and height
     */
    protected function getDimensions()
    {
        // Default result
        $result = [0, 0];

        // If we have a resource already loaded, this means we have modified the resource since the
        // original image was loaded. This means the "Variant" tuple key is out of date, and we don't
        // have a reliable cache key to load from, or save to. If we use the original tuple as a key,
        // we would run the risk of overwriting the original dimensions in the cache, with the values
        // of the resized instead.
        // Instead, we use the immediately available dimensions attached to this resource, and we will
        // rely on cache warming in writeToStore to save these values, where the "Variant" becomes available,
        // before the next time this variant is loaded into memory.
        $resource = $this->image;
        if ($resource) {
            return $this->getResourceDimensions($resource);
        }

        // Check if we have a container
        $container = $this->getAssetContainer();
        if (!$container) {
            return $result;
        }

        // Check cache for unloaded image
        $cache = $this->getCache();
        $key = $this->getDimensionCacheKey($container->getHash(), $container->getVariant());
        if ($cache->has($key)) {
            return $cache->get($key);
        }

        // Cache-miss
        $resource = $this->getImageResource();
        if ($resource) {
            $result = $this->getResourceDimensions($resource);
            $cache->set($key, $result);
        }
        return $result;
    }

    /**
     * Get dimensions from the given resource
     *
     * @param InterventionImage $resource
     * @return array
     */
    protected function getResourceDimensions(InterventionImage $resource)
    {
        /** @var Size $size */
        $size = $resource->getSize();
        return [
            $size->getWidth(),
            $size->getHeight()
        ];
    }

    /**
     * Cache key for recording errors
     *
     * @param string $hash
     * @param string|null $variant
     * @return string
     */
    protected function getErrorCacheKey($hash, $variant = null)
    {
        return InterventionBackend::CACHE_MARK . sha1($hash . '-' . $variant);
    }

    /**
     * Cache key for dimensions for given container
     *
     * @param string $hash
     * @param string|null $variant
     * @return string
     */
    protected function getDimensionCacheKey($hash, $variant = null)
    {
        return InterventionBackend::CACHE_DIMENSIONS . sha1($hash . '-' . $variant);
    }

    /**
     * Warm dimension cache for the given asset
     *
     * @param string $hash
     * @param string|null $variant
     */
    protected function warmCache($hash, $variant = null)
    {
        // Warm dimension cache
        $key = $this->getDimensionCacheKey($hash, $variant);
        $resource = $this->getImageResource();
        if ($resource) {
            $result = $this->getResourceDimensions($resource);
            $this->getCache()->set($key, $result);
        }
    }

    /**
     * @return int The width of the image
     */
    public function getWidth()
    {
        list($width) = $this->getDimensions();
        return (int)$width;
    }

    /**
     * @return int The height of the image
     */
    public function getHeight()
    {
        list(, $height) = $this->getDimensions();
        return (int)$height;
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
        return $this->createCloneWithResource(
            function (InterventionImage $resource) use ($width, $height) {
                return $resource->resize($width, $height);
            }
        );
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
        return $this->createCloneWithResource(
            function (InterventionImage $resource) use ($width, $height, $useAsMinimum) {
                return $resource->resize(
                    $width,
                    $height,
                    function (Constraint $constraint) use ($useAsMinimum) {
                        $constraint->aspectRatio();
                        if (!$useAsMinimum) {
                            $constraint->upsize();
                        }
                    }
                );
            }
        );
    }

    /**
     * Resize an image by width. Preserves aspect ratio.
     *
     * @param int $width
     * @return static
     */
    public function resizeByWidth($width)
    {
        return $this->createCloneWithResource(
            function (InterventionImage $resource) use ($width) {
                return $resource->widen($width);
            }
        );
    }

    /**
     * Resize an image by height. Preserves aspect ratio.
     *
     * @param int $height
     * @return static
     */
    public function resizeByHeight($height)
    {
        return $this->createCloneWithResource(
            function (InterventionImage $resource) use ($height) {
                return $resource->heighten($height);
            }
        );
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
        return $this->createCloneWithResource(
            function (InterventionImage $resource) use ($width, $height, $background) {
                return $resource
                    ->resize(
                        $width,
                        $height,
                        function (Constraint $constraint) {
                            $constraint->aspectRatio();
                        }
                    )
                    ->resizeCanvas(
                        $width,
                        $height,
                        'center',
                        false,
                        $background
                    );
            }
        );
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
        return $this->createCloneWithResource(
            function (InterventionImage $resource) use ($width, $height) {
                return $resource->fit($width, $height);
            }
        );
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
        return $this->createCloneWithResource(
            function (InterventionImage $resource) use ($top, $left, $height, $width) {
                return $resource->crop($width, $height, $left, $top);
            }
        );
    }

    /**
     * Modify this image backend with either a provided resource, or transformation
     *
     * @param InterventionImage|callable $resourceOrTransformation Either the resource to assign to the clone,
     * or a function which takes the current resource as a parameter
     * @return static
     */
    protected function createCloneWithResource($resourceOrTransformation)
    {
        // No clone with no argument
        if (!$resourceOrTransformation) {
            return null;
        }

        // Handle transformation function
        if (is_callable($resourceOrTransformation)) {
            // Fail if resource not available
            $resource = $this->getImageResource();
            if (!$resource) {
                return null;
            }

            // Note: Closure may simply modify the resource rather than return a new one
            $resource = clone $resource;
            $resource = call_user_func($resourceOrTransformation, $resource) ?: $resource;

            // Clone with updated resource
            return $this->createCloneWithResource($resource);
        }

        // Ensure result is of a valid type
        if (!$resourceOrTransformation instanceof InterventionImage) {
            throw new InvalidArgumentException("Invalid resource type");
        }

        // Create clone
        $clone = clone $this;
        $clone->setImageResource($resourceOrTransformation);
        return $clone;
    }

    /**
     * Clear any cached errors / metadata for this image
     *
     * @param string $hash
     * @param string|null $variant
     */
    protected function markSuccess($hash, $variant = null)
    {
        $key = $this->getErrorCacheKey($hash, $variant);
        $this->getCache()->deleteMultiple([
            $key.'_reason',
            $key.'_ttl'
        ]);
    }

    /**
     * Mark this image as failed to load
     *
     * @param string $hash Hash of original file being manipluated
     * @param string|null $variant Variant being loaded
     * @param string $reason Reason this file is failed
     */
    protected function markFailed($hash, $variant = null, $reason = InterventionBackend::FAILED_UNKNOWN)
    {
        $key = $this->getErrorCacheKey($hash, $variant);

        // Get TTL for error
        $errorTTLs = $this->config()->get('error_cache_ttl');
        $ttl = isset($errorTTLs[$reason]) ? $errorTTLs[$reason] : $errorTTLs[InterventionBackend::FAILED_UNKNOWN];

        // Detect increasing waits
        if (is_string($ttl) && strstr($ttl ?? '', ',')) {
            $ttl = preg_split('#\s*,\s*#', $ttl ?? '');
        }
        if (is_array($ttl)) {
            $index = min(
                $this->getCache()->get($key.'_ttl', -1) + 1,
                count($ttl ?? []) - 1
            );
            $this->getCache()->set($key.'_ttl', $index);
            $ttl = $ttl[$index];
        }
        if (!is_numeric($ttl)) {
            throw new LogicException("Invalid TTL {$ttl}");
        }
        // Treat 0 as unlimited
        $ttl = $ttl ? (int)$ttl : null;
        $this->getCache()->set($key.'_reason', $reason, $ttl);
    }

    /**
     * Determine reason this file could not be loaded.
     * Will return one of the FAILED_* constant values, or null if not failed
     *
     * @param string $hash Hash of the original file being manipulated
     * @param string|null $variant
     * @return string|null
     */
    protected function hasFailed($hash, $variant = null)
    {
        $key = $this->getErrorCacheKey($hash, $variant);
        return $this->getCache()->get($key.'_reason', null);
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
        if (file_exists($this->getTempPath() ?? '')) {
            unlink($this->getTempPath() ?? '');
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
        if (Config::inst()->get(static::class, 'flush_enabled')) {
            /** @var CacheInterface $cache */
            $cache = Injector::inst()->get(CacheInterface::class . '.InterventionBackend_Manipulations');
            $cache->clear();
        }
    }

    /**
     * Validate the stream resource is readable
     *
     * @param mixed $stream
     * @return bool
     */
    protected function isStreamReadable($stream)
    {
        if (empty($stream)) {
            return false;
        }
        if ($stream instanceof StreamInterface) {
            return $stream->isReadable();
        }

        // Ensure resource is stream type
        if (!is_resource($stream)) {
            return false;
        }
        if (get_resource_type($stream) !== 'stream') {
            return false;
        }

        // Ensure stream is readable
        $meta = stream_get_meta_data($stream);
        return isset($meta['mode']) && (strstr($meta['mode'] ?? '', 'r') || strstr($meta['mode'] ?? '', '+'));
    }
}
