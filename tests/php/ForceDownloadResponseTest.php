<?php

namespace SilverStripe\Assets\Tests;

use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Dev\FunctionalTest;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * Ensures that protected file responses only render inline for allowlisted content types, so an
 * SVG/XML payload containing an inline <script> downloads rather than executing as stored XSS
 * when opened from the CMS.
 */
class ForceDownloadResponseTest extends FunctionalTest
{
    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('ForceDownloadResponseTest');
        // svg and html are not uploadable by default, but plenty of projects add them
        File::config()->merge('allowed_extensions', ['svg', 'html']);
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public static function provideFileResponse(): array
    {
        return [
            'svg markup under a kml name' => ['company-logo.kml', 'image/svg+xml', 'attachment'],
            'svg' => ['payload.svg', 'image/svg+xml', 'attachment'],
            'html' => ['payload.html', 'text/html', 'attachment'],
            // Contents are indistinguishable from plain text, so the extension decides the type
            'csv' => ['sample.csv', 'text/csv', 'attachment'],
            'plain text' => ['sample.txt', 'text/plain', 'inline'],
            'png' => ['sample.png', 'image/png', 'inline'],
            'pdf' => ['sample.pdf', 'application/pdf', 'inline'],
        ];
    }

    #[DataProvider('provideFileResponse')]
    public function testFileResponse(string $filename, string $expectedMime, string $expectedDisposition): void
    {
        $file = File::create();
        $file->setFromLocalFile(__DIR__ . '/ForceDownloadResponseTest/' . $filename, $filename);
        $file->write();

        $response = $this->get(ltrim(parse_url($file->getURL(true), PHP_URL_PATH), '/'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($expectedMime, $response->getHeader('Content-Type'));
        $this->assertSame($expectedDisposition, $response->getHeader('Content-Disposition'));
        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        $this->assertSame("default-src 'none'; sandbox", $response->getHeader('Content-Security-Policy'));
    }

    public static function provideIsInlineMime(): array
    {
        return [
            'svg' => ['image/svg+xml', false],
            'svg with charset' => ['image/svg+xml; charset=utf-8', false],
            'svg uppercase' => ['IMAGE/SVG+XML', false],
            'html' => ['text/html', false],
            'kml' => ['application/vnd.google-earth.kml+xml', false],
            'xml' => ['application/xml', false],
            'json' => ['application/json', false],
            'csv' => ['text/csv', false],
            'png' => ['image/png', true],
            'png uppercase' => ['IMAGE/PNG', true],
            'jpeg with parameters' => ['image/jpeg; charset=binary', true],
            'video' => ['video/mp4', true],
            'audio' => ['audio/mpeg', true],
            'pdf' => ['application/pdf', true],
            'plain text' => ['text/plain', true],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('provideIsInlineMime')]
    public function testIsInlineMime(string $mime, bool $expected): void
    {
        $store = new FlysystemAssetStore();
        $reflection = new ReflectionMethod($store, 'isInlineMime');
        $reflection->setAccessible(true);
        $this->assertSame($expected, $reflection->invoke($store, $mime));
    }
}
