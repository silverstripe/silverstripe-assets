<?php

namespace SilverStripe\Assets\Flysystem;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Convert;

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
    private static $server_configuration = [
        'apache' => [
            '.htaccess' => PublicAssetAdapter::class . '_HTAccess'
        ],
        'microsoft-iis' => [
            'web.config' => PublicAssetAdapter::class . '_WebConfig'
        ]
    ];

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
        $path = Convert::slashes($path, '/');
        return Controller::join_links(Director::baseURL(), $this->parentUrlPrefix, $path);
    }

    /**
     * Initialise parent URL prefix
     *
     * @param string $path base path
     */
    protected function initParentURLPrefix($path)
    {
        // Detect segment between web root directory and assets root
        $path = Convert::slashes($path, '/');
        $basePath = Convert::slashes(Director::publicFolder(), '/');
        if (stripos($path ?? '', $basePath ?? '') === 0) {
            $prefix = substr($path ?? '', strlen($basePath ?? ''));
        } else {
            $prefix = ASSETS_DIR;
        }
        $this->parentUrlPrefix = ltrim($prefix ?? '', '/');
    }
}
