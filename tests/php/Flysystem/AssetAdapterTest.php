<?php

namespace SilverStripe\Assets\Tests\Flysystem;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Filesystem;
use SilverStripe\Assets\Flysystem\AssetAdapter;
use SilverStripe\Assets\Flysystem\ProtectedAssetAdapter;
use SilverStripe\Assets\Flysystem\PublicAssetAdapter;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

class AssetAdapterTest extends SapphireTest
{
    protected $rootDir = null;

    protected $originalServer = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootDir = ASSETS_PATH . '/AssetAdapterTest';
        Filesystem::makeFolder($this->rootDir);
        Config::modify()->set(Director::class, 'alternate_base_url', '/');
        $this->originalServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        if ($this->rootDir) {
            Filesystem::removeFolder($this->rootDir);
            $this->rootDir = null;
        }
        if ($this->originalServer) {
            $_SERVER = $this->originalServer;
            $this->originalServer = null;
        }
        parent::tearDown();
    }

    public function testDefaultPermissions()
    {
        $this->assertSame(Config::inst()->get(AssetAdapter::class, 'file_permissions'), [
            'file' => [
                'public' => 0664,
                'private' => 0600,
            ],
            'dir' => [
                'public' => 0775,
                'private' => 0775,
            ]
        ]);
    }

    public function testPublicAdapter()
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.2.22 (Win64) PHP/5.3.13';
        $adapter = new PublicAssetAdapter($this->rootDir);
        $this->assertFileExists($this->rootDir . '/.htaccess');
        $this->assertFileDoesNotExist($this->rootDir . '/web.config');

        $content = $adapter->read('.htaccess');
        // Allowed extensions set
        $this->assertStringContainsString('RewriteCond %{REQUEST_URI} !^[^.]*[^\/]*\.(?i:', $content);
        foreach (File::getAllowedExtensions() as $extension) {
            $this->assertMatchesRegularExpression('/\b'.preg_quote($extension ?? '').'\b/', $content);
        }

        // Executable scripts are denied regardless of how PHP is wired up
        $this->assertStringContainsString(
            '<FilesMatch "(?i)\\.(?:php[0-9]*|phar|phtml|pht|inc)$">',
            $content
        );
        $this->assertStringContainsString('Require all denied', $content);

        // Rewrite rules
        $this->assertStringContainsString('RewriteRule .* ../index.php [QSA]', $content);
        $this->assertStringContainsString('RewriteCond %{REQUEST_FILENAME} (?:^|/)error[^/]*\\.html$', $content);

        // Test flush restores invalid content
        file_put_contents($this->rootDir . '/.htaccess', '# broken content');
        $adapter->flush();
        $htaccess2 = $adapter->read('.htaccess');
        $this->assertEquals($content, $htaccess2);

        // Test URL
        $this->assertEquals('/assets/AssetAdapterTest/file.jpg', $adapter->getPublicUrl('file.jpg'));
    }

    public function provideErrorPageAllowanceMatchesResolvedFileNotPathInfo(): array
    {
        return [
            'genuine error page' => [
                '/assets/error-500.html',
                'error-500.html',
                true,
            ],
            'plain error.html' => [
                '/assets/error.html',
                'error.html',
                true,
            ],
            'nested error page' => [
                '/assets/nested/error-404.html',
                'nested/error-404.html',
                true,
            ],
            'php script requested directly' => [
                '/assets/shell.php',
                'shell.php',
                false,
            ],
            'path-info bypass' => [
                '/assets/shell.php/error-404.html',
                'shell.php',
                false,
            ],
            'php file merely named like an error page' => [
                '/assets/error-500.php',
                'error-500.php',
                false,
            ],
        ];
    }

    /**
     * @dataProvider provideErrorPageAllowanceMatchesResolvedFileNotPathInfo
     */
    public function testErrorPageAllowanceMatchesResolvedFileNotPathInfo(
        string $requestUri,
        string $resolvedFile,
        bool $allowed
    ): void {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.68 (Ubuntu)';
        $adapter = new PublicAssetAdapter($this->rootDir);
        $content = $adapter->read('.htaccess');
        // Extract the error-page guard (the RewriteCond tested against the resolved file).
        $this->assertMatchesRegularExpression('/RewriteCond\s+%\{REQUEST_FILENAME\}\s+\S*error[^\s]*html\S*/', $content);
        preg_match('/RewriteCond\s+%\{REQUEST_FILENAME\}\s+(\S*error[^\s]*html\S*)/', $content, $matches);
        $guard = '#' . $matches[1] . '#';
        // Apache tests the guard against the resolved file on disk, so match against that.
        $resolvedPath = ASSETS_PATH . '/' . $resolvedFile;
        $matched = preg_match($guard, $resolvedPath) === 1;
        $this->assertSame($allowed, $matched);
    }

    public function testProtectedAdapter()
    {
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.2.22 (Win64) PHP/5.3.13';
        $adapter = new ProtectedAssetAdapter($this->rootDir . '/.protected');
        $this->assertFileExists($this->rootDir . '/.protected/.htaccess');
        $this->assertFileDoesNotExist($this->rootDir . '/.protected/web.config');

        // Test url
        $this->assertEquals('/assets/file.jpg', $adapter->getProtectedUrl('file.jpg'));
    }

    public function testPermissions()
    {
        if (stripos(PHP_OS, 'win') === 0) {
            $this->markTestSkipped("This test doesn't work on windows");
        }
        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.2.22 (Win64) PHP/5.3.13';

        // Public asset adapter writes .htaccess with public perms
        $adapter = new PublicAssetAdapter($this->rootDir);
        $adapter->flush();
        $this->assertFileExists($this->rootDir . '/.htaccess');
        $publicPerm = fileperms($this->rootDir . '/.htaccess');

        // Public read
        $this->assertEquals(
            0044,
            $publicPerm & 0044,
            $this->readablePerm($publicPerm) . ' has public read'
        );

        // Same as protected adapter
        $adapter = new ProtectedAssetAdapter($this->rootDir . '/.protected');
        $adapter->flush();
        $this->assertFileExists($this->rootDir . '/.protected/.htaccess');
        $protectedPerm = fileperms($this->rootDir . '/.protected/.htaccess');
        // Public read
        $this->assertEquals(
            0044,
            $protectedPerm & 0044,
            $this->readablePerm($protectedPerm) . ' has public read'
        );
    }

    public function testNormalisePermissions()
    {
        $this->assertEquals(
            [
                'file' => [
                    'private' => 0644,
                    'public' => 0666,
                ],
                'dir' => [
                    'public' => 0755,
                    'private' => 0700,
                ]
            ],
            AssetAdapter::normalisePermissions([
                'file' => [
                    'private' => '0644',
                    'public' => '666',
                ],
                'dir' => [
                    'public' => 0755,
                    'private' => 0700,
                ]
            ])
        );
    }

    /**
     * Human readable perm mask
     *
     * @param int $mask
     * @return string
     */
    protected function readablePerm($mask)
    {
        return substr(sprintf('%o', $mask), -4);
    }
}
