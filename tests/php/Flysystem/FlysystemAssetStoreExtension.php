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
    public function updateResponse($response, $asset, $context)
    {
        FlysystemAssetStoreExtension::$lastHookCall = [$response, $asset, $context];
        FlysystemAssetStoreExtension::$callCount++;

        $response->addHeader('FlysystemAssetStoreExtensionCallCount', FlysystemAssetStoreExtension::$callCount);
    }
}
