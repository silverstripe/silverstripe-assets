<?php

namespace SilverStripe\Assets\Shortcodes;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ErrorPage\ErrorPage;
use SilverStripe\ORM\DataObject;
use SilverStripe\View\ArrayData;
use SilverStripe\View\HTML;
use SilverStripe\View\Parsers\ShortcodeHandler;
use SilverStripe\View\Parsers\ShortcodeParser;
use SilverStripe\View\SSViewer;
use SilverStripe\View\SSViewer_FromString;

/**
 * Provides shortcodes for File dataobject
 */
class FileShortcodeProvider implements ShortcodeHandler, Flushable
{
    use Extensible;
    use Injectable;
    use Configurable;

    /**
     * Assume canView() = true for all files provided via shortcodes.
     * This relies on the application applying canView() on the parent record
     * to ensure access control.
     *
     * @config
     * @var bool
     */
    private static $shortcodes_inherit_canview = true;

    /**
     * Set to true if shortcodes should apply a session grant on their calls to `getAsURL`.
     * While this is useful for allowing users to view draft files in shortcodes, it produces
     * an inherent security risk by exposing direct access to the file for the duration of
     * the session.
     *
     * @config
     * @var bool
     */
    private static $allow_session_grant = false;

    /**
     * Gets the list of shortcodes provided by this handler
     *
     * @return mixed
     */
    public static function get_shortcodes()
    {
        return 'file_link';
    }

    /**
     * Replace "[file_link id=n]" shortcode with an anchor tag or link to the file.
     *
     * @param array $arguments Arguments passed to the parser
     * @param string $content Raw shortcode
     * @param ShortcodeParser $parser Parser
     * @param string $shortcode Name of shortcode used to register this handler
     * @param array $extra Extra arguments
     *
     * @return string Result of the handled shortcode
     */
    public static function handle_shortcode($arguments, $content, $parser, $shortcode, $extra = [])
    {
        $cache = static::getCache();
        $cacheKey = static::getCacheKey($arguments, $content);
        $cachedMarkup = static::getCachedMarkup($cache, $cacheKey, $arguments);
        if ($cachedMarkup) {
            return $cachedMarkup;
        }

        // Find appropriate record, with fallback for error handlers
        $fileFound = true;
        $record = static::find_shortcode_record($arguments, $errorCode);
        if ($errorCode) {
            $fileFound = false;
            $record = static::find_error_record($errorCode);
        }
        if (!$record) {
            return null; // There were no suitable matches at all.
        }

        // Retrieve the file URL (ensuring session grant config is respected)
        if ($record instanceof File) {
            $grant = static::getGrant($record);
            $url = $record->getURL($grant);
        } else {
            $url = $record->Link();
        }

        // build the HTML tag
        if ($content) {
            // build some useful meta-data (file type and size) as data attributes
            $attrs = [ 'href' => $url ];
            if ($record instanceof File) {
                $attrs = array_merge($attrs, [
                     'class' => 'file',
                     'data-type' => $record->getExtension(),
                     'data-size' => $record->getSize(),
                ]);
            }
            $markup = HTML::createTag('a', $attrs, $parser->parse($content));
        } else {
            $markup = $url;
        }

        // cache it for future reference
        if ($fileFound) {
            $cache->set($cacheKey, [
                'markup' => $markup,
                'filename' => $record instanceof File ? $record->getFilename() : null,
                'hash' => $record instanceof File ? $record->getHash() : null,
            ]);
        }

        return $markup;
    }

    /**
     * @param CacheInterface $cache
     * @param string $cacheKey
     * @param array $arguments
     * @return string
     */
    protected static function getCachedMarkup($cache, $cacheKey, $arguments): string
    {
        // Retrieve the cache markup
        $item = $cache->get($cacheKey);
        if (empty($item)) {
            // This is a cache miss
            return '';
        }

        // Destructure our cache entry
        $markup = $item['markup'] ?? '';
        $filename = $item['filename'] ?? '';
        $hash = $item['hash'] ?? '';
        $assetStore = Injector::inst()->get(AssetStore::class);

        // Validate the cache entry
        if (empty($markup) || empty($filename) || empty($hash) || !$assetStore->exists($filename, $hash)) {
            // Cache entry is wrong, delete it
            $cache->delete($cacheKey);
            return '';
        }

        // Shortcode can be configured to allow session grant to files even if the file is protected
        if (static::getGrant(null, $arguments)) {
            $assetStore->grant($filename, $hash);
        }

        return $markup;
    }

    /**
     * @param File|null $record
     * @param array|null $args
     * @return bool
     */
    protected static function getGrant(?File $record, ?array $args = null): bool
    {
        $grant = static::config()->allow_session_grant;
        static::singleton()->extend('updateGrant', $grant, $record, $args);
        return $grant;
    }

    /**
     * Find the record to use for a given shortcode.
     *
     * @param array $args Array of input shortcode arguments
     * @param int $errorCode If the file is not found, or is inaccessible, this will be assigned to a HTTP error code.
     *
     * @return File|null The File DataObject, if it can be found.
     */
    public static function find_shortcode_record($args, &$errorCode = null)
    {
        // Validate shortcode
        if (!isset($args['id']) || !is_numeric($args['id'])) {
            return null;
        }

        // Check if the file is found
        $file = DataObject::get_by_id(File::class, $args['id']);
        if (!$file) {
            $errorCode = 404;
            return null;
        }

        // Check if the file is viewable
        $inheritsCanView = Config::inst()->get(static::class, 'shortcodes_inherit_canview');
        if (!$inheritsCanView && !$file->canView()) {
            $errorCode = 403;
            return null;
        }

        // Success
        return $file;
    }


    /**
     * Given a HTTP Error, find an appropriate substitute File or SiteTree data object instance.
     *
     * @param int $errorCode HTTP Error value
     *
     * @return File|SiteTree File or SiteTree object to use for the given error
     */
    protected static function find_error_record($errorCode)
    {
        $result = static::singleton()->invokeWithExtensions('getErrorRecordFor', $errorCode);
        $result = array_filter($result ?? []);
        if ($result) {
            return reset($result);
        }

        return null;
    }

    /**
     * Generates a cachekey with the given parameters
     *
     * @param $params
     * @param $content
     * @return string
     */
    public static function getCacheKey($params, $content = null)
    {
        $key = SSViewer::config()->get('global_key');
        $viewer = new SSViewer_FromString($key);
        $globalKey = md5($viewer->process(ArrayData::create([])) ?? '');
        $argsKey = md5(serialize($params)) . '#' . md5(serialize($content));

        return "{$globalKey}#{$argsKey}";
    }

    /**
     * Gets the cache used by this provider
     *
     * @return CacheInterface
     */
    public static function getCache()
    {
        return Injector::inst()->get(CacheInterface::class . '.FileShortcodeProvider');
    }

    /**
     *
     */
    public static function flush()
    {
        $cache = static::getCache();
        $cache->clear();
    }
}
