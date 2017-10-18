<?php

namespace SilverStripe\Assets\Shortcodes;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\View\HTML;
use SilverStripe\View\Parsers\ShortcodeHandler;
use SilverStripe\View\Parsers\ShortcodeParser;

/**
 * Class ImageShortcodeProvider
 *
 * @package SilverStripe\Forms\HtmlEditor
 */
class ImageShortcodeProvider extends FileShortcodeProvider implements ShortcodeHandler, Flushable
{

    /**
     * Gets the list of shortcodes provided by this handler
     *
     * @return mixed
     */
    public static function get_shortcodes()
    {
        return array('image');
    }

    /**
     * Replace"[image id=n]" shortcode with an image reference.
     * Permission checks will be enforced by the file routing itself.
     *
     * @param array $args Arguments passed to the parser
     * @param string $content Raw shortcode
     * @param ShortcodeParser $parser Parser
     * @param string $shortcode Name of shortcode used to register this handler
     * @param array $extra Extra arguments
     * @return string Result of the handled shortcode
     */
    public static function handle_shortcode($args, $content, $parser, $shortcode, $extra = array())
    {
        $cache = static::getCache();
        $cacheKey = static::getCacheKey($args);

        $item = $cache->get($cacheKey);
        if ($item) {
            /** @var AssetStore $store */
            $store = Injector::inst()->get(AssetStore::class);
            if (!empty($item['filename'])) {
                $store->grant($item['filename'], $item['hash']);
            }
            return $item['markup'];
        }

        // Find appropriate record, with fallback for error handlers
        $record = static::find_shortcode_record($args, $errorCode);
        if ($errorCode) {
            $record = static::find_error_record($errorCode);
        }
        if (!$record) {
            return null; // There were no suitable matches at all.
        }

        // Check if a resize is required
        $src = $record->Link();
        if ($record instanceof Image) {
            $width = isset($args['width']) ? $args['width'] : null;
            $height = isset($args['height']) ? $args['height'] : null;
            $hasCustomDimensions = ($width && $height);
            if ($hasCustomDimensions && (($width != $record->getWidth()) || ($height != $record->getHeight()))) {
                $resized = $record->ResizedImage($width, $height);
                // Make sure that the resized image actually returns an image
                if ($resized) {
                    $src = $resized->getURL();
                }
            }
        }

        // Build the HTML tag
        $attrs = array_merge(
            // Set overrideable defaults
            ['src' => '', 'alt' => $record->Title],
            // Use all other shortcode arguments
            $args,
            // But enforce some values
            ['id' => '', 'src' => $src]
        );

        // Clean out any empty attributes
        $attrs = array_filter($attrs, function ($v) {
            return (bool)$v;
        });

        $markup = HTML::createTag('img', $attrs);

        // cache it for future reference
        $cache->set($cacheKey, [
            'markup' => $markup,
            'filename' => $record instanceof File ? $record->getFilename() : null,
            'hash' => $record instanceof File ? $record->getHash() : null,
        ]);

        return $markup;
    }

    /**
     * Regenerates "[image id=n]" shortcode with new src attribute prior to being edited within the CMS.
     *
     * @param array $args Arguments passed to the parser
     * @param string $content Raw shortcode
     * @param ShortcodeParser $parser Parser
     * @param string $shortcode Name of shortcode used to register this handler
     * @param array $extra Extra arguments
     * @return string Result of the handled shortcode
     */
    public static function regenerate_shortcode($args, $content, $parser, $shortcode, $extra = array())
    {
        // Check if there is a suitable record
        $record = static::find_shortcode_record($args);
        if ($record) {
            $args['src'] = $record->getURL();
        }

        // Rebuild shortcode
        $parts = array();
        foreach ($args as $name => $value) {
            $htmlValue = Convert::raw2att($value ?: $name);
            $parts[] = sprintf('%s="%s"', $name, $htmlValue);
        }
        return sprintf("[%s %s]", $shortcode, implode(' ', $parts));
    }

    /**
     * Helper method to regenerate all shortcode links.
     *
     * @param string $value HTML value
     * @return string value with links resampled
     */
    public static function regenerate_html_links($value)
    {
        // Create a shortcode generator which only regenerates links
        $regenerator = ShortcodeParser::get('regenerator');
        return $regenerator->parse($value);
    }

    /**
     * Gets the cache used by this provider
     *
     * @return CacheInterface
     */
    public static function getCache()
    {
        /** @var CacheInterface $cache */
        return Injector::inst()->get(CacheInterface::class . '.ImageShortcodeProvider');
    }
}
