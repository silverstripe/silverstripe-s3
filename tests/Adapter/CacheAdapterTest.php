<?php

namespace SilverStripe\S3\Tests\Adapter;

use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\S3\Adapter\CacheAdapter;
use SilverStripe\S3\Cache\ContentCache;
use SilverStripe\S3\Tests\S3TestHelperTrait;
use Symfony\Component\Cache\Simple\ArrayCache;

class CacheAdapterTest extends SapphireTest
{
    use S3TestHelperTrait;

    /**
     * @var CacheAdapter
     */
    protected $adapter = null;

    /**
     * @var AdapterInterface
     */
    protected $mockBackend = null;

    protected function setUp()
    {
        parent::setUp();

        // Fix now to point in time
        DBDatetime::set_mock_now(DBDatetime::now());

        // Create test adapter
        $this->adapter = new CacheAdapter();

        // Set caches
        $contentCache = new ContentCache();
        $contentCache->setLocationCache(new ArrayCache());
        $this->adapter->setContentCache($contentCache);
        $this->adapter->setMetadataCache(new ArrayCache());

        // Note: original backend is assigned on a test-by-test basis
    }

    public function tearDown()
    {
        $this->tearDownFiles();
        parent::tearDown();
    }

    /**
     * Uploading stream should only ever make one backend request
     */
    public function testUploadStream()
    {
        /** @var ObjectProphecy|AdapterInterface $adapterMock */
        $adapterMock = $this->prophesize(AdapterInterface::class);
        // Mock write
        $adapterMock
            ->writeStream('somefile.txt', Argument::type('resource'), Argument::type(Config::class))
            ->willReturn(['type' => 'file', 'path' => 'somefile.txt', 'visibility' => 'public'])
            ->shouldBeCalledTimes(1);

        // Certain backend items should not be called on upload
        $adapterMock
            ->has(Argument::any())
            ->shouldNotBeCalled();
        $adapterMock
            ->getSize(Argument::any())
            ->shouldNotBeCalled();
        $adapterMock
            ->getMimetype(Argument::any())
            ->shouldNotBeCalled();

        // Getting metadata of a non-warmed file should hit the backend
        $adapterMock
            ->getMetadata('somefile.txt')
            ->shouldNotBeCalled();
        $anotherFileMetadata = [
            'type' => 'file',
            'path' => 'anotherfile.txt',
            'size' => 100,
            'timestamp' => DBDatetime::now()->getTimestamp(),
            'mimetype' => 'text/plain',
        ];
        $adapterMock
            ->getMetadata('anotherfile.txt')
            ->willReturn($anotherFileMetadata)
            ->shouldBeCalledTimes(1);
        $adapterMock
            ->getMetadata('nonfile.png')
            ->willReturn(false)
            ->shouldBeCalledTimes(1);

        /** @var AdapterInterface $backendAdapter */
        $backendAdapter = $adapterMock->reveal();
        $this->adapter->setBackend($backendAdapter);

        // Upload from local file
        $tempFile = $this->createTempFile('uploaded content');
        $stream = fopen($tempFile, 'r');
        $this->adapter->writeStream('somefile.txt', $stream, new Config());

        // check both cached and uncached metadata
        $this->assertEquals([
            'dirname' => '.',
            'basename' => 'somefile.txt',
            'extension' => 'txt',
            'type' => 'file',
            'path' => 'somefile.txt',
            'timestamp' => DBDatetime::now()->getTimestamp(),
            'mimetype' => 'text/plain',
            'size' => 16,
        ], $this->adapter->getMetadata('somefile.txt'));
        $this->assertEquals($anotherFileMetadata, $this->adapter->getMetadata('anotherfile.txt'));
        $this->assertEquals(false, $this->adapter->getMetadata('nonfile.png'));

        // check both cached and uncached has
        $this->assertTrue($this->adapter->has('somefile.txt'));
        $this->assertTrue($this->adapter->has('anotherfile.txt'));
        $this->assertFalse($this->adapter->has('nonfile.png'));
    }

    public function testGetMetadata()
    {
        $tempFile = $this->createTempFile('uploaded content');
        $this->adapter->getContentCache()->warmFromPath(sha1('somefile.txt'), $tempFile);

        // Note: any file warmed from the cache should be available to the cache adapter
        // without having to hit the backend
        $this->assertEquals([
            'dirname' => '.',
            'basename' => 'somefile.txt',
            'extension' => 'txt',
            'type' => 'file',
            'path' => 'somefile.txt',
            'timestamp' => DBDatetime::now()->getTimestamp(),
            'mimetype' => 'text/plain',
            'size' => 16,
        ], $this->adapter->getMetadata('somefile.txt'));
    }

    public function testHas()
    {
        /** @var ObjectProphecy|AdapterInterface $adapterMock */
        $adapterMock = $this->prophesize(AdapterInterface::class);
        // Mock write
        $metadata = [
            'dirname' => '.',
            'basename' => 'somefile.txt',
            'extension' => 'txt',
            'type' => 'file',
            'path' => 'somefile.txt',
            'timestamp' => DBDatetime::now()->getTimestamp(),
            'mimetype' => 'text/plain',
            'size' => 16,
        ];
        $adapterMock
            ->getMetadata('somefile.txt')
            ->willReturn($metadata)
            ->shouldBeCalledTimes(1);

        /** @var AdapterInterface $backendAdapter */
        $backendAdapter = $adapterMock->reveal();
        $this->adapter->setBackend($backendAdapter);

        // Even if warming data from the cache, a ->has() call will always make a real call
        // as a cache of warm data doesn't ensure the backend actually contains that file
        $tempFile = $this->createTempFile('uploaded content');
        $this->adapter->getContentCache()->warmFromPath(sha1('somefile.txt'), $tempFile);
        $this->assertTrue($this->adapter->has('somefile.txt'));

        // However, the metadata doesn't trigger any subsequent calls once cached
        $this->assertEquals($metadata, $this->adapter->getMetadata('somefile.txt'));
    }
}
