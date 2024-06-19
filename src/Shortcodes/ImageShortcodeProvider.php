<?php

namespace SilverStripe\Assets\Shortcodes;

use Psr\SimpleCache\CacheInterface;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
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
     * A whitelist of attributes which are allowed in the resultant markup.
     *
     * @config
     */
    private static array $attribute_whitelist = [
        'alt',
        'class',
        'height',
        'loading',
        'src',
        'title',
        'width',
    ];

    /**
     * Gets the list of shortcodes provided by this handler
     *
     * @return mixed
     */
    public static function get_shortcodes()
    {
        return ['image'];
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
    public static function handle_shortcode($args, $content, $parser, $shortcode, $extra = [])
    {
        $cache = static::getCache();
        $cacheKey = static::getCacheKey($args, $content);
        $cachedMarkup = static::getCachedMarkup($cache, $cacheKey, $args);
        if ($cachedMarkup) {
            return $cachedMarkup;
        }

        // Find appropriate record, with fallback for error handlers
        $fileFound = true;
        $record = static::find_shortcode_record($args, $errorCode);
        if ($errorCode) {
            $fileFound = false;
            $record = static::find_error_record($errorCode);
        }
        if (!$record) {
            return null; // There were no suitable matches at all.
        }

        // Check if a resize is required
        $width = null;
        $height = null;
        $grant = static::getGrant($record);
        $src = $record->getURL($grant);
        if ($record instanceof Image) {
            $width = isset($args['width']) ? (int) $args['width'] : null;
            $height = isset($args['height']) ? (int) $args['height'] : null;
            $hasCustomDimensions = ($width && $height);
            if ($hasCustomDimensions && (($width != $record->getWidth()) || ($height != $record->getHeight()))) {
                $resized = $record->ResizedImage($width, $height);
                // Make sure that the resized image actually returns an image
                if ($resized) {
                    $src = $resized->getURL($grant);
                }
            }
        }

        // Determine whether loading="lazy" is set
        $args = ImageShortcodeProvider::updateLoadingValue($args, $width, $height);

        // Build the HTML tag
        $attrs = array_merge(
            // Set overrideable defaults ('alt' must be present regardless of contents)
            ['src' => '', 'alt' => ''],
            // Use all other shortcode arguments
            $args,
            // But enforce some values
            ['id' => '', 'src' => $src]
        );

        // If file was not found then use the Title value from static::find_error_record() for the alt attr
        if (!$fileFound) {
            $attrs['alt'] = $record->Title;
        }

        // Clean out any empty attributes (aside from alt) and anything not whitelisted
        $whitelist = static::config()->get('attribute_whitelist');
        $attrs = array_filter($attrs ?? [], function ($v, $k) use ($whitelist) {
            return in_array($k, $whitelist) && (strlen(trim($v ?? '')) || $k === 'alt');
        }, ARRAY_FILTER_USE_BOTH);

        $markup = ImageShortcodeProvider::createImageTag($attrs);

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
     * Construct and return HTML image tag.
     */
    public static function createImageTag(array $attributes) : string
    {
        $preparedAttributes = '';
        foreach ($attributes as $attributeKey => $attributeValue) {
            if (strlen($attributeValue ?? '') > 0 || $attributeKey === 'alt') {
                $preparedAttributes .= sprintf(
                    ' %s="%s"',
                    $attributeKey,
                    htmlspecialchars($attributeValue ?? '', ENT_QUOTES, 'UTF-8', false)
                );
            }
        }

        return "<img{$preparedAttributes} />";
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
    public static function regenerate_shortcode($args, $content, $parser, $shortcode, $extra = [])
    {
        $allowSessionGrant = static::config()->allow_session_grant;

        // Check if there is a suitable record
        $record = static::find_shortcode_record($args);
        if ($record) {
            $args['src'] = $record->getURL($allowSessionGrant);
        }

        // Rebuild shortcode
        $parts = [];
        foreach ($args as $name => $value) {
            $htmlValue = htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8', false);
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
        return Injector::inst()->get(CacheInterface::class . '.ImageShortcodeProvider');
    }

    /**
     * @inheritdoc
     */
    protected static function find_error_record($errorCode)
    {
        return Image::create([
            'Title' => _t(__CLASS__ . '.IMAGENOTFOUND', 'Image not found'),
        ]);
    }

    /**
     * Updated the loading attribute which is used to either lazy-load or eager-load images
     * Eager-load is the default browser behaviour so when eager loading is specified, the
     * loading attribute is omitted
     *
     * @param array $args
     * @param int|null $width
     * @param int|null $height
     * @return array
     */
    private static function updateLoadingValue(array $args, ?int $width, ?int $height): array
    {
        if (!Image::getLazyLoadingEnabled()) {
            return $args;
        }
        if (isset($args['loading']) && $args['loading'] == 'eager') {
            // per image override - unset the loading attribute unset to eager load (default browser behaviour)
            unset($args['loading']);
        } elseif ($width && $height) {
            // width and height must be present to prevent content shifting
            $args['loading'] = 'lazy';
        }
        return $args;
    }
}
