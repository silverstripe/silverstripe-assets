<?php

namespace SilverStripe\Assets\Flysystem;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;

class PublicAssetAdapter extends AssetAdapter implements PublicAdapter
{
    /**
     * Prefix between the root url and base of the assets folder
     * Used for generating public urls
     *
     * @var string
     */
    protected $parentUrlPrefix = null;

    /**
     * Server specific configuration necessary to block http traffic to a local folder
     *
     * @config
     * @var array Mapping of server configurations to configuration files necessary
     */
    private static $server_configuration = array(
        'apache' => array(
            '.htaccess' => "SilverStripe\\Assets\\Flysystem\\PublicAssetAdapter_HTAccess"
        ),
        'microsoft-iis' => array(
            'web.config' => "SilverStripe\\Assets\\Flysystem\\PublicAssetAdapter_WebConfig"
        )
    );

    protected function findRoot($root)
    {
        if ($root) {
            $path = parent::findRoot($root);
        } else {
            $path = ASSETS_PATH;
        }

        // Assign prefix based on path
        $this->initParentURLPrefix($path);

        return $path;
    }

    /**
     * Provide downloadable url
     *
     * @param string $path
     * @return string|null
     */
    public function getPublicUrl($path)
    {
        return Controller::join_links(Director::baseURL(), $this->parentUrlPrefix, $path);
    }

    /**
     * Initialise parent URL prefix
     *
     * @param string $path base path
     */
    protected function initParentURLPrefix($path)
    {
        // Detect segment between root directory and assets root
        $path = str_replace('\\', '/', $path);
        $basePath = str_replace('\\', '/', BASE_PATH);
        if (stripos($path, $basePath) === 0) {
            $prefix = substr($path, strlen($basePath));
        } else {
            $prefix = ASSETS_DIR;
        }
        $this->parentUrlPrefix = ltrim($prefix, '/');
    }
}
