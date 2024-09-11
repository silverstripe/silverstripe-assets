<?php

namespace SilverStripe\Assets\Tests\Shortcodes;

use SilverStripe\Assets\File;
use Silverstripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\View\Parsers\ShortcodeParser;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Shortcodes\ImageShortcodeProvider;
use SilverStripe\Assets\Shortcodes\FileShortcodeProvider;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Security\InheritedPermissions;
use SilverStripe\Security\Member;
use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;

class ImageShortcodeProviderTest extends SapphireTest
{

    protected static $fixture_file = '../ImageTestBase.yml';

    protected function setUp(): void
    {
        parent::setUp();

        // Set backend root to /ImageTest
        TestAssetStore::activate('ImageTest');

        // Copy test images for each of the fixture references
        $images = Image::get();
        /** @var Image $image */
        foreach ($images as $image) {
            $sourcePath = __DIR__ . '/../ImageTest/' . $image->Name;
            $image->setFromLocalFile($sourcePath, $image->Filename);
        }
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function testShortcodeHandlerDoesNotFallBackToFileProperties()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');
        $this->assertImageTag(
            [
                'src' => $image->Link(),
                'alt' => '',
                'width' => '300',
                'height' => '300',
                'title' => false,
                'id' => false,
            ],
            sprintf('[image id=%d]', $image->ID),
            'Shortcode handler does not fall back to file properties to generate alt attribute'
        );
    }

    public function testShortcodeHandlerUsesShortcodeProperties()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');
        $this->assertImageTag(
            [
                'src' => $image->Link(),
                'alt' => 'Alt content',
                'title' => 'Title content',
            ],
            sprintf('[image id="%d" alt="Alt content" title="Title content"]', $image->ID),
            'Shortcode handler uses properties from shortcode'
        );
    }

    public function testShortcodeHandlerHandlesEntities()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');
        // Because we are using a DOMDocument to read the values, we need to compare to non-escaped value
        $this->assertImageTag(
            [
                'src' => $image->Link(),
                'alt' => 'Alt & content',
                'title' => '" Hockey > Rugby "',
            ],
            sprintf('[image id="%d" alt="Alt & content" title="&quot; Hockey &gt; Rugby &quot;"]', $image->ID),
            'Shortcode handler can handle HTML entities'
        );
    }

    public function testShorcodeRegenrator()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');
        $parser = new ShortcodeParser();
        $parser->register('image', [ImageShortcodeProvider::class, 'regenerate_shortcode']);

        $this->assertEquals(
            sprintf(
                '[image id="%d" alt="My alt text" title="My Title &amp; special character" src="%s"]',
                $image->ID,
                $image->Link()
            ),
            $parser->parse(sprintf(
                '[image id="%d" alt="My alt text" title="My Title & special character"]',
                $image->ID
            )),
            'Shortcode regeneration properly reads attributes'
        );

        $this->assertEquals(
            sprintf(
                '[image id="%d" alt="" title="" src="%s"]',
                $image->ID,
                $image->Link()
            ),
            $parser->parse(
                sprintf(
                    '[image id="%d" alt="" title=""]',
                    $image->ID
                )
            ),
            "Shortcode regeneration properly handles empty attributes"
        );
    }

    public function testShortcodeHandlerAddsDefaultAttributes()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithoutTitle');
        $this->assertImageTag(
            [
                'src' => $image->Link(),
                'alt' => '',
                'width' => '300',
                'height' => '300',
                'title' => false,
                'id' => false,
            ],
            sprintf('[image id=%d]', $image->ID),
            'Shortcode handler adds default attributes'
        );
    }

    public function testShortcodeHandlerDoesNotResampleToNonIntegerImagesSizes()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithoutTitle');
        $this->assertImageTag(
            [
                'src' => $image->Link(),
                'alt' => '',
                'width' => '50%',
                'title' => false,
                'id' => false,
            ],
            sprintf('[image id="%d" alt="" width="50%%"]', $image->ID),
            'Shortcode handler does resample to non-integer image sizes'
        );
    }

    public function testShortcodeHandlerFailsGracefully()
    {
        $nonExistentImageID = File::get()->max('ID') + 1;

        $this->assertImageTag(
            [
                'src' => false,
                'alt' => 'Image not found',
                'width' => false,
                'height' => false,
                'title' => false,
                'id' => false,
            ],
            '[image id="' . $nonExistentImageID . '"]',
            'Shortcode handler fails gracefully when image does not exist'
        );

        $this->assertImageTag(
            [
                'src' => false,
                'alt' => 'Image not found',
                'width' => false,
                'height' => false,
                'title' => false,
                'id' => false,
            ],
            '[image id="' . $nonExistentImageID . '" alt="my-alt-attr"]',
            'Shortcode handler fails gracefully when image does not exist asd overrides alt attribute'
        );
    }

    public function testMissingImageDoesNotCache()
    {

        $parser = new ShortcodeParser();
        $parser->register('image', [ImageShortcodeProvider::class, 'handle_shortcode']);

        $nonExistentImageID = File::get()->max('ID') + 1;
        $shortcode = '[image id="' . $nonExistentImageID . '"]';

        // make sure cache is not populated from a previous test
        $cache = ImageShortcodeProvider::getCache();
        $cache->clear();

        $args = ['id' => (string)$nonExistentImageID];
        $cacheKey = ImageShortcodeProvider::getCacheKey($args);

        // assert that cache is empty before parsing shortcode
        $this->assertNull($cache->get($cacheKey));

        $parser->parse($shortcode);

        // assert that cache is still empty after parsing shortcode
        $this->assertNull($cache->get($cacheKey));
    }

    public function testLazyLoading()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithoutTitle');
        $id = $image->ID;

        // regular shortcode
        $this->assertImageTag(
            [
                'src' => $image->ResizedImage(300, 200)->Link(),
                'alt' => '',
                'width' => '300',
                'height' => '200',
                'title' => false,
                'id' => false,
                'loading' => 'lazy',
            ],
            '[image id="' . $id . '" width="300" height="200"]',
            'Lazy loading is enabled by default'
        );


        // regular shortcode
        $this->assertImageTag(
            [
                'src' => $image->Link(),
                'alt' => '',
                'width' => '300',
                'height' => '300',
                'title' => false,
                'id' => false,
                'loading' => 'lazy',
            ],
            '[image id="' . $id . '"]',
            'Lazy loading still works if width and height are not provided. Dimensions are read from the file instead.'
        );

        // missing width
        $this->assertImageTag(
            [
                'src' => $image->Link(),
                'alt' => '',
                'height' => '200',
                'title' => false,
                'id' => false,
                'loading' => false,
            ],
            '[image id="' . $id . '" height="200"]',
            'If the width of the image can not be determined, lazy loading is not applied'
        );

        // missing height
        $this->assertImageTag(
            [
                'src' => $image->Link(),
                'alt' => '',
                'width' => '300',
                'title' => false,
                'id' => false,
                'loading' => false,
            ],
            '[image id="' . $id . '" width="300"]',
            'If the height of the image can not be determined, lazy loading is not applied'
        );

        // loading="eager"
        $this->assertImageTag(
            [
                'src' => $image->ResizedImage(300, 200)->Link(),
                'alt' => '',
                'height' => '200',
                'width' => '300',
                'title' => false,
                'id' => false,
                'loading' => false,
            ],
            '[image id="' . $id . '" width="300" height="200" loading="eager"]',
            'Shortcode can force eager loading'
        );

        // loading="nonsense"
        $this->assertImageTag(
            [
                'src' => $image->ResizedImage(300, 200)->Link(),
                'alt' => '',
                'height' => '200',
                'width' => '300',
                'title' => false,
                'id' => false,
                'loading' => 'lazy',
            ],
            '[image id="' . $id . '" width="300" height="200" loading="nonsense"]',
            'Invalid loading value in short code are ignored and the image is lazy loaded'
        );

        // globally disabled
        Config::withConfig(
            function () use ($id, $image) {
                Config::modify()->set(Image::class, 'lazy_loading_enabled', false);
                // clear-provider-cache is so that we don't get a cached result from the 'regular shortcode'
                // assertion earlier in this function from ImageShortCodeProvider::handle_shortcode()
                $this->assertImageTag(
                    [
                        'src' => $image->ResizedImage(300, 200)->Link(),
                        'alt' => '',
                        'height' => '200',
                        'width' => '300',
                        'title' => false,
                        'id' => false,
                        'loading' => false,
                    ],
                    '[image id="' . $id . '" width="300" height="200" clear-provider-cache="1"]',
                    'If lazy loading is disabled globally the image is not lazy loaded'
                );
            }
        );
    }

    public function testRegenerateShortcode()
    {
        $assetStore = Injector::inst()->get(AssetStore::class);
        $member = Member::create();
        $member->write();
        // Logout first to throw away the existing session which may have image grants.
        $this->logOut();
        $this->logInAs($member);
        // image is in protected asset store
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');
        $image->CanViewType = InheritedPermissions::ONLY_THESE_USERS;
        $image->write();
        $url = $image->getUrl(false);
        $args = [
            'id' => $image->ID,
            'src' => $url,
            'width' => '550',
            'height' => '366',
            'class' => 'leftAlone ss-htmleditorfield-file image',
        ];
        $shortHash = substr($image->getHash(), 0, 10);
        $expected = implode(
            ' ',
            [
            '[image id="' . $image->ID . '" src="/assets/folder/' . $shortHash . '/test-image.png" width="550"',
            'height="366" class="leftAlone ss-htmleditorfield-file image"]'
            ]
        );
        $parsedFileID = new ParsedFileID($image->getFilename(), $image->getHash());
        $html = ImageShortcodeProvider::regenerate_shortcode($args, '', '', 'image');
        $this->assertSame($expected, $html);
        $this->assertFalse($assetStore->isGranted($parsedFileID));

        // Login as member with 'VIEW_DRAFT_CONTENT' permisson to access to file and get session access
        $this->logOut();

        $this->logInWithPermission('VIEW_DRAFT_CONTENT');
        // Provide permissions to view file for any logged in users
        $image->CanViewType = InheritedPermissions::LOGGED_IN_USERS;
        $image->write();

        Config::modify()->set(FileShortcodeProvider::class, 'allow_session_grant', true);
        $html = ImageShortcodeProvider::regenerate_shortcode($args, '', '', 'image');
        $this->assertSame($expected, $html);
        $this->assertTrue($assetStore->isGranted($parsedFileID));
    }

    public function testEmptyAttributesAreRemoved()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithTitle');

        $this->assertImageTag(
            [
                'src' => $image->Link(),
                'alt' => '',
                'width' => '300',
                'height' => '300',
                'title' => false,
                'id' => false,
                'loading' => 'lazy',
                'class' => false,
            ],
            sprintf('[image id=%d alt="" class=""]', $image->ID),
            'Image shortcode does not render empty attributes'
        );
    }

    public function testOnlyWhitelistedAttributesAllowed()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithoutTitle');
        $whitelist = ImageShortcodeProvider::config()->get('attribute_whitelist');

        $attributeString = '';
        $attributes = [];
        foreach ($whitelist as $attrName) {
            if ($attrName === 'src') {
                continue;
            }
            $attributeString .= $attrName . '="' . $attrName . '" ';
            $attributes[$attrName] = $attrName;
        }

        $this->assertImageTag(
            array_merge(
                $attributes,
                [
                    'src' => $image->Link(),
                    'id' => false,
                    'loading' => 'lazy',
                    'style' => false,
                    'data-some-value' => false
                ]
            ),
            sprintf(
                '[image id="%d" %s style="width:100px" data-some-value="my-data"]',
                $image->ID,
                trim($attributeString)
            ),
            'Image shortcode does not render attributes not in whitelist'
        );
    }

    public function testWhiteIsConfigurable()
    {
        $image = $this->objFromFixture(Image::class, 'imageWithoutTitle');
        $whitelist = ImageShortcodeProvider::config()->get('attribute_whitelist');

        $attributeString = '';
        $attributes = [];
        foreach ($whitelist as $attrName) {
            if ($attrName === 'src') {
                continue;
            }
            $attributeString .= $attrName . '="' . $attrName . '" ';
            $attributes[$attrName] = $attrName;
        }

        // Allow new whitelisted attribute
        Config::modify()->merge(ImageShortcodeProvider::class, 'attribute_whitelist', ['data-some-value']);

        $this->assertImageTag(
            array_merge(
                $attributes,
                [
                    'src' => $image->Link(),
                    'id' => false,
                    'loading' => 'lazy',
                    'style' => false,
                    'data-some-value' => 'my-data'
                ]
            ),
            sprintf(
                '[image id="%d" %s style="width:100px" data-some-value="my-data"]',
                $image->ID,
                trim($attributeString)
            ),
            'Image shortcode does not render attributes not in whitelist'
        );
    }

    public static function gettersAndSettersProvider(): array
    {
        return [
                    'image without special characters' => [
                        '<img src="http://example.com/image.jpg" alt="My alt text" title="My Title" width="300" height="200" class="leftAlone ss-htmleditorfield-file image" />',
                        [
                            'src' => 'http://example.com/image.jpg',
                            'alt' => 'My alt text',
                            'title' => 'My Title',
                            'width' => '300',
                            'height' => '200',
                            'class' => 'leftAlone ss-htmleditorfield-file image',
                        ],
                    ],
                    'image with special characters' => [
                        '<img src="http://example.com/image.jpg" alt="My alt text &amp; special character" title="My Title &amp; special character" width="300" height="200" class="leftAlone ss-htmleditorfield-file image" />',
                        [
                            'src' => 'http://example.com/image.jpg',
                            'alt' => 'My alt text &amp; special character',
                            'title' => 'My Title & special character',
                            'width' => '300',
                            'height' => '200',
                            'class' => 'leftAlone ss-htmleditorfield-file image',
                        ]
                    ]
                ];
    }

    #[DataProvider('gettersAndSettersProvider')]
    public function testCreateImageTag(string $expected, array $attributes)
    {
        $this->assertEquals($expected, ImageShortcodeProvider::createImageTag($attributes));
    }

    /**
     * This method will assert that the $tag will contain an image with specific attributes and values
     *
     * @param array $attrs Key pair values of attributes and values.
     *                     If the value is FALSE we assume that it is not present
     */
    private function assertImageTag(array $attrs, string $shortcode, $message = ""): void
    {
        $parser = new ShortcodeParser();
        $parser->register('image', [ImageShortcodeProvider::class, 'handle_shortcode']);
        $tag = $parser->parse($shortcode);

        $doc = new DOMDocument();
        $doc->loadHTML($tag);
        $node = $doc->getElementsByTagName('img')->item(0);
        $nodeAttrs = $node->attributes;

        foreach ($attrs as $key => $value) {
            $nodeAttr = $nodeAttrs->getNamedItem($key);
            if ($value === false) {
                if ($nodeAttr !== null) {
                    $this->fail("$message\nImage should not contain attribute '$key'\n$tag");
                }
            } else {
                if ($nodeAttr === null) {
                    $this->fail("$message\nImage should contain attribute '$key'\n$tag");
                }
                if ($nodeAttr->nodeValue !== $value) {
                    $this->fail("$message\nImage attribute '$key' should have value '$value'\n$tag");
                }
            }
            $this->assertTrue(true, 'Suppress warning about not having any assertion');
        }
    }
}
