<?php

namespace SilverStripe\Assets\Tests\Flysystem;

use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Extension;

class FlysystemAssetStoreExtension extends Extension
{
    public static $lastHookCall = [];
    public static $callCount = 0;

    /**
     * @param HTTPResponse $response
     * @param string $asset
     * @param array $context
     */
    protected function updateResponse($response, $asset, $context)
    {
        self::$lastHookCall = [$response, $asset, $context];
        self::$callCount++;

        $response->addHeader('FlysystemAssetStoreExtensionCallCount', self::$callCount);
    }
}
