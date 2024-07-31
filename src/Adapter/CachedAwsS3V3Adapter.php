<?php

namespace SilverStripe\S3\Adapter;

use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\CalculateChecksumFromStream;
use League\Flysystem\ChecksumAlgoIsNotSupported;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use SilverStripe\Core\Config\Config as SSConfig;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use Silverstripe\S3\Cache\CacheItemsTrait;

class CachedAwsS3V3Adapter extends AwsS3V3Adapter implements Flushable
{
    use CacheItemsTrait;
    use CalculateChecksumFromStream;
    use Configurable;

    /**
     * Is cache flushing enabled?
     *
     * @config
     * @var boolean
     */
    private static $flush_enabled = true;

    /**
     * Is logging enabled?
     *
     * @config
     * @var boolean
     */
    private static $logging_enabled = false;

    /**
     * Enablo local stream caching
     *
     * @config
     * @var boolean
     */
    private static $local_streaming = true;

    /**
     * @inheritdoc
     */
    public function fileExists(string $path): bool
    {
        $item = $this->getCacheItem($path);

        if ($item && isset($item->extraMetadata()['fileExists'])) {
            return $item->extraMetadata()['fileExists'];
        } else if ($item && isset($item->extraMetadata()['directoryExists'])) {
            return false;
        }

        $startTime = microtime(true);
        $fileExists = parent::fileExists($path);

        $this->logAwsCall(__FUNCTION__, $path, microtime(true) - $startTime);

        $state = new FileAttributes(
            path: $fileExists,
            extraMetadata: ['fileExists' => $fileExists]
        );

        $this->saveCacheItem($path, $state);

        return $fileExists;
    }

    /**
     * @inheritdoc
     */
    public function directoryExists(string $path): bool
    {
        $item = $this->getCacheItem($path);

        if ($item && isset($item->extraMetadata()['directoryExists'])) {
            return $item->extraMetadata()['directoryExists'];
        } else if ($item && isset($item->extraMetadata()['fileExists'])) {
            return false;
        }

        $startTime = microtime(true);

        $directoryExists = parent::directoryExists($path);

        $this->logAwsCall(__FUNCTION__, $path, microtime(true) - $startTime);

        $state = new FileAttributes(
            path: $path,
            extraMetadata: ['directoryExists' => $directoryExists]
        );

        $this->saveCacheItem($path, $state);

        return $directoryExists ?? \false;
    }


    public function publicUrl(string $path, Config $config): string
    {
        $item = $this->getCacheItem($path);

        if ($item && !empty($item->extraMetadata()['publicUrl'])) {
            return $item->extraMetadata()['publicUrl'];
        }

        $startTime = microtime(true);

        $url = parent::publicUrl($path, $config);

        $this->logAwsCall(__FUNCTION__, $path, microtime(true) - $startTime);

        if ($item) {
            $state = CachedAwsS3V3Adapter::mergeFileAttributes(
                fileAttributesBase: $item,
                fileAttributesExtension: new FileAttributes(
                    path: $path,
                    extraMetadata: ['publicUrl' => $url]
                ),
            );
        } else {
            $state = new FileAttributes(
                path: $path,
                extraMetadata: ['publicUrl' => $url]
            );
        }

        $this->saveCacheItem($path, $state);

        return $url;
    }


    /**
     * @inheritdoc
     */
    public function write(string $path, string $contents, Config $config): void
    {
        parent::write($path, $contents, $config);

        $this->purgeCachePath($path);
    }

    /**
     * @inheritdoc
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        parent::writeStream($path, $contents, $config);

        $this->purgeCachePath($path);
    }

    /**
     * @inheritdoc
     */
    public function read(string $path): string
    {
        $time = microtime(true);
        try {
            $contents = parent::read($path);

            $item = $this->getCacheItem($path);
        } catch (UnableToReadFile $e) {
            $this->purgeCachePath($path);

            throw $e;
        }

        if (isset($item) && $item instanceof FileAttributes) {
            $fileAttributes = CachedAwsS3V3Adapter::mergeFileAttributes(
                fileAttributesBase: $item,
                fileAttributesExtension: new FileAttributes(
                    path: $path,
                ),
            );
        } else {
            $fileSize = parent::fileSize($path);

            $fileAttributes = new FileAttributes(
                path: $path,
                fileSize: $fileSize ?? 0
            );
        }

        $this->logAwsCall(__FUNCTION__, $path, microtime(true) - $time);

        $this->saveCacheItem($path, $fileAttributes);

        return $contents;
    }

    /**
     * @inheritdoc
     */
    public function readStream(string $path)
    {
        $time = microtime(true);
        $resource = false;

        if ($this->config()->get('local_streaming')) {
            // save the stream to a local file
            $localPath = TEMP_PATH . '/s3__local__' . md5($path);

            if (!file_exists($localPath)) {
                $resource = parent::read($path);
                file_put_contents($localPath, $resource);
            } else {
                $resource = fopen($localPath, 'r');
            }
        } else {
            try {
                $resource = parent::readStream($path);
            } catch (UnableToReadFile $e) {
                $this->purgeCachePath($path);

                throw $e;
            }

            $this->logAwsCall(__FUNCTION__, $path, microtime(true) - $time);
        }

        $item = $this->getCacheItem($path);

        if ($item && $item instanceof FileAttributes) {
            $fileAttributes = CachedAwsS3V3Adapter::mergeFileAttributes(
                fileAttributesBase: $item,
                fileAttributesExtension: new FileAttributes(
                    path: $path,
                ),
            );
        } else {
            $fileAttributes = new FileAttributes(
                path: $path,
            );
        }


        $this->saveCacheItem($path, $fileAttributes);

        return $resource;
    }

    /**
     * @inheritdoc
     */
    public function delete(string $path): void
    {
        try {
            parent::delete($path);
        } finally {
            $this->purgeCachePath($path);
        }
    }

    /**
     * @inheritdoc
     */
    public function deleteDirectory(string $path): void
    {
        try {
            foreach (parent::listContents($path, true) as $storageAttributes) {
                /** @var StorageAttributes $storageAttributes */
                $this->purgeCachePath($storageAttributes->path());
            }

            parent::deleteDirectory($path);
        } finally {
            $this->purgeCachePath($path);
        }
    }

    /**
     * @inheritdoc
     */
    public function createDirectory(string $path, Config $config): void
    {
        parent::createDirectory($path, $config);

        $this->purgeCachePath($path);
    }

    /**
     * @inheritdoc
     */
    public function setVisibility(string $path, string $visibility): void
    {
        try {
            parent::setVisibility($path, $visibility);
        } catch (UnableToSetVisibility $e) {
            $this->purgeCachePath($path);

            throw $e;
        }

        $attributes = $this->getCacheItem($path);

        if ($attributes) {
            $attributes = CachedAwsS3V3Adapter::mergeFileAttributes(
                fileAttributesBase: $attributes,
                fileAttributesExtension: new FileAttributes(
                    path: $path,
                    visibility: $visibility,
                ),
            );
        } else {
            $attributes = new FileAttributes(
                path: $path,
                visibility: $visibility,
            );
        }

        $this->saveCacheItem($path, $attributes);
    }


    /**
     * @inheritdoc
     */
    public function visibility(string $path): FileAttributes
    {
        return $this->getFileAttributes(
            path: $path,
            loader: function () use ($path) {
                try {
                    return parent::visibility($path);
                } catch (UnableToRetrieveMetadata $e) {
                    return new FileAttributes($path, null, '');
                }
            },
            attributeAccessor: function (FileAttributes $fileAttributes) {
                return $fileAttributes->visibility();
            },
        );
    }

    /**
     * @inheritdoc
     */
    public function mimeType(string $path): FileAttributes
    {
        $time = microtime(true);

        return $this->getFileAttributes(
            path: $path,
            loader: function () use ($path, $time) {
                try {
                    $type = parent::mimeType($path);

                    $this->logAwsCall(__FUNCTION__, $path, microtime(true) - $time);

                    return $type;
                } catch (UnableToRetrieveMetadata $e) {
                    return new FileAttributes($path, null, null, null, '');
                }
            },
            attributeAccessor: function (FileAttributes $fileAttributes) {
                return $fileAttributes->mimeType();
            },
        );
    }

    /**
     * @inheritdoc
     */
    public function lastModified(string $path): FileAttributes
    {
        return $this->getFileAttributes(
            path: $path,
            loader: function () use ($path) {
                try {
                    return parent::lastModified($path);
                } catch (UnableToRetrieveMetadata $e) {
                    return new FileAttributes($path, null, null, time(), null);
                }
            },
            attributeAccessor: function (FileAttributes $fileAttributes) {
                return $fileAttributes->lastModified();
            },
        );
    }

    /**
     * @inheritdoc
     */
    public function fileSize(string $path): FileAttributes
    {
        return $this->getFileAttributes(
            path: $path,
            loader: function () use ($path) {
                return parent::fileSize($path);
                try {
                    return parent::fileSize($path);
                } catch (UnableToRetrieveMetadata $e) {
                    return new FileAttributes($path, 0);
                }
            },
            attributeAccessor: function (FileAttributes $fileAttributes) {
                return $fileAttributes->fileSize();
            },
        );
    }

    /**
     * @inheritdoc
     */
    public function checksum(string $path, Config $config): string
    {
        $time = microtime(true);

        $algo = $config->get('checksum_algo');
        $metadataKey = isset($algo) ? 'checksum_' . $algo : 'checksum';

        $attributeAccessor = function (StorageAttributes $storageAttributes) use ($metadataKey) {
            $eTag = $storageAttributes->extraMetadata()['ETag'] ?? \null;
            if (isset($eTag)) {
                $checksum = trim($eTag, '" ');
            }

            return $checksum ?? $storageAttributes->extraMetadata()[$metadataKey] ?? \null;
        };

        try {
            $fileAttributes = $this->getFileAttributes(
                path: $path,
                loader: function () use ($path, $config, $metadataKey) {
                    // This part is "mirrored" from FileSystem class to provide the fallback mechanism
                    // and be able to cache the result
                    try {
                        $checksum = $this->checksum($path, $config);
                    } catch (ChecksumAlgoIsNotSupported) {
                        $checksum = $this->calculateChecksumFromStream($path, $config);
                    }

                    return new FileAttributes($path, extraMetadata: [$metadataKey => $checksum]);
                },
                attributeAccessor: $attributeAccessor
            );
        } catch (RuntimeException $e) {
            return '';
        }

        $this->logAwsCall(__FUNCTION__, $path, microtime(true) - $time);

        return $attributeAccessor($fileAttributes);
    }


    /**
     * @inheritdoc
     */
    public function move(string $source, string $destination, Config $config): void
    {
        $this->purgeCachePath($source);
        $this->purgeCachePath($destination);

        try {
            parent::move($source, $destination, $config);
        } catch (UnableToMoveFile $e) {
            throw $e;
        }
    }

    /**
     * @inheritdoc
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $this->purgeCachePath($source);
        $this->purgeCachePath($destination);

        try {
            parent::copy($source, $destination, $config);
        } catch (UnableToCopyFile $e) {
            throw $e;
        }
    }


    public static function flush()
    {
        if (SSConfig::inst()->get(static::class, 'flush_enabled')) {
            Injector::inst()->get(CacheInterface::class . '.s3Cache')->clear();
        }
    }


    protected function logAwsCall(string $method, string $path, float $mircoseconds): void
    {
        if (SSConfig::inst()->get(static::class, 'logging_enabled')) {
            $time = number_format($mircoseconds, 6);

            Injector::inst()->get(LoggerInterface::class)->info(
                sprintf('AWS S3 call: %s(%s) took %s seconds', $method, $path, $time)
            );
        }
    }
}
