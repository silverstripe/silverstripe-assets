<?php

use SilverStripe\Assets\Shortcodes\FileShortcodeProvider;
use SilverStripe\Assets\Shortcodes\ImageShortcodeProvider;
use SilverStripe\View\Parsers\ShortcodeParser;

ShortcodeParser::get('default')
    ->register('file_link', [FileShortcodeProvider::class, 'handle_shortcode'])
    ->register('image', [ImageShortcodeProvider::class, 'handle_shortcode']);

// Shortcode parser which only regenerates shortcodes
ShortcodeParser::get('regenerator')
    ->register('image', [ImageShortcodeProvider::class, 'regenerate_shortcode']);
