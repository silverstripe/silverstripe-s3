<?php

namespace Silverstripe\S3\Cache;

use Closure;
use League\Flysystem\FileAttributes;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Core\Injector\Injector;

/**
 * @property CacheInterface $cache
 */
trait CacheItemsTrait
{
    /**
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @var bool
     */
    protected $enabled = true;

    /**
     * @var string
     */
    protected $cachePrefix = 's3-adapter';


    public function setCachePrefix(string $prefix): self
    {
        $this->cachePrefix = $prefix;

        return $this;
    }


    protected function getCache(): CacheInterface
    {
        if (!$this->cache) {
            $this->cache = Injector::inst()->get(CacheInterface::class . '.s3Cache');
        }

        return $this->cache;
    }


    protected function getCacheItem(string $path): ?FileAttributes
    {
        if (!$this->enabled) {
            return null;
        }

        $key = $this->getCacheItemKey($path);
        $item = $this->getCache()->get($key);

        return $item;
    }


    protected function saveCacheItem(string $path, FileAttributes $cacheItem): self
    {
        $key = $this->getCacheItemKey($path);

        if ($this->enabled) {
            $this->getCache()->set($key, $cacheItem);
        }

        return $this;
    }


    public function purgeCachePath(string $path): self
    {
        $key = $this->getCacheItemKey($path);
        $this->getCache()->delete($key);

        return $this;
    }


    protected function getCacheItemKey(string $path): string
    {
        return md5($this->cachePrefix . '-' . $path);
    }


    /**
     * Returns a new FileAttributes with all properties from $fileAttributesExtension
     * overriding existing properties from $fileAttributesBase (with the exception of path)
     *
     * For extraMetadata, each individual element in the array is also merged
     */
    protected static function mergeFileAttributes(
        FileAttributes $fileAttributesBase,
        FileAttributes $fileAttributesExtension
    ): FileAttributes {
        return new FileAttributes(
            path: $fileAttributesBase->path(),
            fileSize: $fileAttributesExtension->fileSize() ??
                $fileAttributesBase->fileSize(),
            visibility: $fileAttributesExtension->visibility() ??
                $fileAttributesBase->visibility(),
            lastModified: $fileAttributesExtension->lastModified() ??
                $fileAttributesBase->lastModified(),
            mimeType: $fileAttributesExtension->mimeType() ??
                $fileAttributesBase->mimeType(),
            extraMetadata: array_merge(
                $fileAttributesBase->extraMetadata(),
                $fileAttributesExtension->extraMetadata()
            ),
        );
    }

    /**
     * Returns FileAttributes from cache if desired attribute is found,
     * or loads the desired missing attribute from the adapter and merges it with the cached attributes.
     *
     * @param Closure $loader Returns FileAttributes with the desired attribute loaded from adapter
     * @param Closure $attributeAccessor Returns value of desired attribute from cached item
     */
    protected function getFileAttributes(
        string $path,
        Closure $loader,
        Closure $attributeAccessor,
    ): FileAttributes {
        $fileAttributes = $this->getCacheItem($path);

        if ($fileAttributes) {
            if (!$fileAttributes instanceof FileAttributes) {
                $fileAttributes = new FileAttributes(
                    path: $path,
                );
            }
        } else {
            $fileAttributes = new FileAttributes(
                path: $path,
            );
        }

        if ($attributeAccessor($fileAttributes) === null) {
            $fileAttributesExtension = $loader();

            if ($fileAttributesExtension) {
                $fileAttributes = self::mergeFileAttributes(
                    fileAttributesBase: $fileAttributes,
                    fileAttributesExtension: $fileAttributesExtension,
                );
            }

            $this->saveCacheItem($path, $fileAttributes);
        }

        return $fileAttributes;
    }
}
