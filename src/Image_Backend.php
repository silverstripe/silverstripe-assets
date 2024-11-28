<?php

namespace SilverStripe\Assets;

use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Assets\Storage\AssetStore;

/**
 * Image_Backend
 *
 * A backend for manipulation of images via the Image class
 */
interface Image_Backend
{

    /**
     * Represents a square orientation
     */
    const ORIENTATION_SQUARE = 0;

    /**
     * Represents a portrait orientation
     */
    const ORIENTATION_PORTRAIT = 1;

    /**
     * Represents a landscape orientation
     */
    const ORIENTATION_LANDSCAPE = 2;

    /**
     * Create a new backend with the given object
     *
     * @param AssetContainer $assetContainer Object to load from
     */
    public function __construct(?AssetContainer $assetContainer = null);

    /**
     * Get the width of the image
     */
    public function getWidth(): int;

    /**
     * Get the height of the image
     */
    public function getHeight(): int;

    /**
     * Populate the backend with a given object
     *
     * @param AssetContainer $assetContainer Object to load from
     */
    public function loadFromContainer(AssetContainer $assetContainer): static;

    /**
     * Populate the backend from a local path
     */
    public function loadFrom(string $path): static;

    /**
     * Get the currently assigned image resource
     */
    public function getImageResource(): mixed;

    /**
     * Set the currently assigned image resource
     */
    public function setImageResource($resource): static;

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
     */
    public function writeToStore(AssetStore $assetStore, string $filename, ?string $hash = null, ?string $variant = null, array $config = []): array;

    /**
     * Write the backend to a local path
     *
     * @param string $path
     * @return bool if the write was successful
     */
    public function writeTo(string $path): bool;

    /**
     * Set the quality to a value between 0 and 100
     */
    public function setQuality(int $quality): static;

    /**
     * Get the current quality (between 0 and 100).
     */
    public function getQuality(): int;

    /**
     * Resize an image, skewing it as necessary.
     */
    public function resize(int $width, int $height): ?static;

    /**
     * Resize the image by preserving aspect ratio. By default, the image cannot be resized to be larger
     * than its current size.
     * Passing true to useAsMinimum will allow the image to be scaled up.
     */
    public function resizeRatio(int $width, int $height, bool $useAsMinimum = false): ?static;

    /**
     * Resize an image by width. Preserves aspect ratio.
     */
    public function resizeByWidth(int $width): ?static;

    /**
     * Resize an image by height. Preserves aspect ratio.
     */
    public function resizeByHeight(int $height): ?static;

    /**
     * Return a clone of this image resized, with space filled in with the given colour.
     */
    public function paddedResize(string $width, string $height, string $backgroundColour = 'FFFFFF', int $transparencyPercent = 0): ?static;

    /**
     * Resize an image to cover the given width/height completely, and crop off any overhanging edges.
     */
    public function croppedResize(int $width, int $height, string $position = 'center'): ?static;

    /**
     * Crop's part of image.
     * @param int $top Amount of pixels the cutout will be moved on the y (vertical) axis
     * @param int $left Amount of pixels the cutout will be moved on the x (horizontal) axis
     * @param int $width rectangle width
     * @param int $height rectangle height
     * @param string $position Postion at which the cutout will be aligned
     * @param string $backgroundColour Colour to fill any newly created areas
     */
    public function crop(int $top, int $left, int $width, int $height, string $position, string $backgroundColour = 'FFFFFF'): ?static;

    /**
     * Set whether this image backend is allowed to output animated images as a result of manipulations.
     */
    public function setAllowsAnimationInManipulations(bool $allow): static;

    /**
     * Get whether this image backend is allowed to output animated images as a result of manipulations.
     */
    public function getAllowsAnimationInManipulations(): bool;

    /**
     * Check if the image is animated (e.g. an animated GIF).
     * Will return false if animations are not allowed for manipulations.
     */
    public function getIsAnimated(): bool;

    /**
     * Discards all animation frames of the current image instance except the one at the given position. Turns an animated image into a static one.
     *
     * @param integer|string $position Which frame to use as the still image.
     * If an integer is passed, it represents the exact frame number to use (starting at 0). If that frame doesn't exist, an exception is thrown.
     * If a string is passed, it must be in the form of a percentage (e.g. '0%' or '50%'). The frame to use is then determined based
     * on this percentage (e.g. if '50%' is passed, a frame halfway through the animation is used).
     */
    public function removeAnimation(int|string $position): ?static;
}
