<?php

namespace SilverStripe\S3\Tests\Cache;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\S3\Cache\ContentCache;
use SilverStripe\S3\Tests\S3TestHelperTrait;
use Symfony\Component\Cache\Simple\ArrayCache;

class ContentCacheTest extends SapphireTest
{
    use S3TestHelperTrait;

    /**
     * @var ContentCache
     */
    protected $cache;

    protected function setUp()
    {
        parent::setUp();

        $backend = new ArrayCache();
        $this->cache = new ContentCache();
        $this->cache->setLocationCache($backend);
    }

    protected function tearDown()
    {
        $this->tearDownFiles();
        parent::tearDown();
    }

    public function testWarmFromStream()
    {
        // Create temp file stream
        $tempFile = $this->createTempFile();
        $stream = fopen($tempFile, 'r');
        try {
            $this->cache->warmFromStream(sha1('somefile.jpg'), $stream);
        } finally {
            fclose($stream);
        }

        // Check cache results
        $this->assertNull($this->cache->get(sha1('anotherfile.jpg')));
        $path = $this->cache->get(sha1('somefile.jpg'));
        $this->assertNotEmpty($path);
        $this->assertFileExists($path);
        $this->assertFileEquals($tempFile, $path);
    }

    public function testWarmFromString()
    {
        // Create temp file stream
        $content = 'some test content';
        $this->cache->warmFromString(sha1('somefile.jpg'), $content);

        // Check cache results
        $this->assertNull($this->cache->get(sha1('anotherfile.jpg')));
        $path = $this->cache->get(sha1('somefile.jpg'));
        $this->assertNotEmpty($path);
        $this->assertFileExists($path);
        $this->assertStringEqualsFile($path, $content);
    }

    public function testWarmFromFile()
    {
        // Create temp file stream
        $tempFile = $this->createTempFile();
        $this->cache->warmFromPath(sha1('somefile.jpg'), $tempFile);

        // Check cache results
        $this->assertNull($this->cache->get(sha1('anotherfile.jpg')));
        $path = $this->cache->get(sha1('somefile.jpg'));
        $this->assertNotEmpty($path);
        $this->assertEquals($tempFile, $path);
    }

    public function testClear()
    {
        // Create temp file stream
        $tempFile = $this->createTempFile();
        $this->cache->warmFromPath(sha1('somefile.jpg'), $tempFile);

        $this->cache->clear();
        $this->assertNull($this->cache->get(sha1('anotherfile.jpg')));
        $this->assertNull($this->cache->get(sha1('somefile.jpg')));
    }
}
