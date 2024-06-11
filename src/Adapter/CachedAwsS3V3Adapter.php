<?php

namespace SilverStripe\S3\Adapter;

use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\CalculateChecksumFromStream;
use League\Flysystem\ChecksumAlgoIsNotSupported;
use League\Flysystem\FileAttributes;
use Silverstripe\S3\Cache\CacheItemsTrait;
use League\Flysystem\Config;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;

class CachedAwsS3V3Adapter extends AwsS3V3Adapter implements Flushable
{
    use CacheItemsTrait;
    use CalculateChecksumFromStream;


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

        $fileExists = parent::fileExists($path);

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

        $directoryExists = parent::directoryExists($path);

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

        $url = parent::publicUrl($path, $config);

        if ($item) {
            $state = self::mergeFileAttributes(
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
        try {
            $contents = parent::read($path);
            $item = $this->getCacheItem($path);
        } catch (UnableToReadFile $e) {
            $this->purgeCachePath($path);

            throw $e;
        }

        if (isset($item) && $item instanceof FileAttributes) {
            $fileAttributes = self::mergeFileAttributes(
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

        $this->saveCacheItem($path, $fileAttributes);

        return $contents;
    }

    /**
     * @inheritdoc
     */
    public function readStream(string $path)
    {
        try {
            $resource = parent::readStream($path);
        } catch (UnableToReadFile $e) {
            $this->purgeCachePath($path);

            throw $e;
        }

        $item = $this->getCacheItem($path);

        if ($item && $item instanceof FileAttributes) {
            $fileAttributes = self::mergeFileAttributes(
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
            $attributes = self::mergeFileAttributes(
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
        return $this->getFileAttributes(
            path: $path,
            loader: function () use ($path) {
                try {
                    return parent::mimeType($path);
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
        Injector::inst()->get(CacheInterface::class . '.s3Cache')->clear();
    }
}
