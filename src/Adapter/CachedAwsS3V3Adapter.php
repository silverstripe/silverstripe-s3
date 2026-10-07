<?php

namespace SilverStripe\S3\Adapter;

use Aws\S3\S3ClientInterface;
use Exception;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;
use League\Flysystem\AwsS3V3\VisibilityConverter;
use League\Flysystem\CalculateChecksumFromStream;
use League\Flysystem\ChecksumAlgoIsNotSupported;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Throwable;
use SilverStripe\Core\Config\Config as SSConfig;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Flushable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\S3\Cache\CacheItemsTrait;

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
     * When an object has no hash in its metadata, add the metadata the first time it is
     * asked for. This spreads the work of the S3BackfillMetadata task over normal use
     * of the site. Disable if requests shouldn't write to the bucket.
     *
     * @config
     * @var boolean
     */
    private static $lazy_metadata_backfill = true;

    /**
     * Keys of the S3 user metadata written alongside every object, so its hash
     * and image dimensions can be read without downloading it.
     */
    public const METADATA_SHA1 = 'sha1';
    public const METADATA_WIDTH = 'width';
    public const METADATA_HEIGHT = 'height';
    // Only stored for images whose EXIF orientation turns them on their side (values 5-8).
    // Width and height are always those in the file, before any such rotation.
    public const METADATA_ORIENTATION = 'orientation';

    /**
     * Object properties that replacing metadata would otherwise reset.
     */
    private const BACKFILL_PRESERVED_FIELDS = [
        'ContentType',
        'CacheControl',
        'ContentDisposition',
        'ContentEncoding',
        'ContentLanguage',
        'StorageClass',
        'ServerSideEncryption',
        'SSEKMSKeyId',
    ];

    // The parent keeps these private, and the metadata backfill needs them to copy an object onto itself
    private S3ClientInterface $s3Client;
    private string $s3Bucket;
    private PathPrefixer $s3Prefixer;
    private VisibilityConverter $s3Visibility;

    public function __construct(
        S3ClientInterface $client,
        string $bucket,
        string $prefix = '',
        ?VisibilityConverter $visibility = null,
        ...$args
    ) {
        $this->s3Client = $client;
        $this->s3Bucket = $bucket;
        $this->s3Prefixer = new PathPrefixer($prefix);
        $this->s3Visibility = $visibility ?? new PortableVisibilityConverter();

        parent::__construct($client, $bucket, $prefix, $this->s3Visibility, ...$args);
    }

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

        try {
            $fileExists = parent::fileExists($path);
        } catch (Exception $e) {
            $fileExists = false;
        }

        $state = new FileAttributes(
            path: $path,
            extraMetadata: ['fileExists' => $fileExists]
        );

        // Keep attributes already cached for this file, rather than forcing them to be fetched again
        if ($fileExists && $item) {
            $state = CachedAwsS3V3Adapter::mergeFileAttributes(
                fileAttributesBase: $item,
                fileAttributesExtension: $state,
            );
        }

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

        try {
            $directoryExists = parent::directoryExists($path);
        } catch (Exception $e) {
            $directoryExists = false;
        }

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
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        $config = $this->withObjectMetadata($stream, $config);
        fclose($stream);

        parent::write($path, $contents, $config);

        $this->purgeCachePath($path);
    }

    /**
     * @inheritdoc
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        // The stream is read once for its metadata and again for the upload, so buffer
        // streams which can't be rewound (e.g. one read from S3 while publishing a file)
        $buffer = null;
        if (!stream_get_meta_data($contents)['seekable']) {
            $buffer = fopen('php://temp', 'r+');
            stream_copy_to_stream($contents, $buffer);
            $contents = $buffer;
        }

        try {
            parent::writeStream($path, $contents, $this->withObjectMetadata($contents, $config));
        } finally {
            if (is_resource($buffer)) {
                fclose($buffer);
            }
        }

        $this->purgeCachePath($path);
    }

    /**
     * Add the hash and image dimensions of the given stream to the metadata being written
     *
     * @param resource $stream Seekable stream
     */
    private function withObjectMetadata($stream, Config $config): Config
    {
        return $config->extend([
            'Metadata' => static::detectObjectMetadata($stream) + (array) $config->get('Metadata', []),
        ]);
    }

    /**
     * Build the S3 user metadata describing the contents of a stream
     *
     * @param resource $stream Seekable stream, which is left rewound
     * @return array<string,string>
     */
    public static function detectObjectMetadata($stream): array
    {
        rewind($stream);
        $hash = hash_init('sha1');
        hash_update_stream($hash, $stream);
        $metadata = [self::METADATA_SHA1 => hash_final($hash)];

        if ($dimensions = self::detectDimensions($stream)) {
            $metadata[self::METADATA_WIDTH] = (string) $dimensions[0];
            $metadata[self::METADATA_HEIGHT] = (string) $dimensions[1];

            if ($dimensions[2] >= 5) {
                $metadata[self::METADATA_ORIENTATION] = (string) $dimensions[2];
            }
        }

        rewind($stream);

        return $metadata;
    }

    /**
     * Read image dimensions and EXIF orientation from a stream without decoding the image.
     *
     * Returns null for anything that isn't a plain raster image, and where the orientation
     * can't be established. Those simply have no stored dimensions, so they are measured
     * from the image as before.
     *
     * @param resource $stream Seekable stream
     * @return array{int,int,int}|null Width, height and orientation (1-8)
     */
    private static function detectDimensions($stream): ?array
    {
        $header = (string) stream_get_contents($stream, 262144, 0);
        $info = @getimagesizefromstring($header);

        if (!$info && str_starts_with($header, "\xFF\xD8")) {
            // Large EXIF or colour profile segments can push a JPEG's dimensions past the header
            $info = @getimagesizefromstring((string) stream_get_contents($stream, null, 0));
        }

        if (!$info || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        $orientation = 1;

        switch ($info[2]) {
            case IMAGETYPE_GIF:
            case IMAGETYPE_BMP:
                break;
            case IMAGETYPE_JPEG:
                $orientation = self::readExifOrientation($stream);
                break;
            case IMAGETYPE_PNG:
            case IMAGETYPE_WEBP:
                // The EXIF block in these is a TIFF file in its own right
                $exif = self::findExifChunk($stream, $info[2], $header);
                if ($exif !== null) {
                    $tiff = fopen('php://temp', 'r+');
                    fwrite($tiff, $exif);
                    $orientation = self::readExifOrientation($tiff);
                    fclose($tiff);

                    // Image backends disagree on whether to turn these, so leave them to be measured
                    if ($orientation >= 5) {
                        return null;
                    }
                }
                break;
            default:
                return null;
        }

        return $orientation === null ? null : [$info[0], $info[1], $orientation];
    }

    /**
     * @param resource $stream A JPEG or TIFF
     * @return int|null Null if it can't be read on this server
     */
    private static function readExifOrientation($stream): ?int
    {
        if (!function_exists('exif_read_data')) {
            return null;
        }

        rewind($stream);
        $exif = @exif_read_data($stream);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /**
     * Find the EXIF block of a PNG or WebP image, which can sit anywhere in the file
     *
     * @param resource $stream Seekable stream
     */
    private static function findExifChunk($stream, int $type, string $header): ?string
    {
        if ($type === IMAGETYPE_WEBP) {
            // Only the extended format can carry EXIF, and it flags whether it does
            if (substr($header, 12, 4) !== 'VP8X' || !(ord($header[20] ?? "\0") & 0x08)) {
                return null;
            }
            [$start, $format, $name, $trailer] = [12, 'a4id/Vsize', 'EXIF', 0];
        } else {
            [$start, $format, $name, $trailer] = [8, 'Nsize/a4id', 'eXIf', 4];
        }

        fseek($stream, $start);
        while (strlen($chunk = (string) fread($stream, 8)) === 8) {
            $chunk = unpack($format, $chunk);

            if ($chunk['id'] === $name) {
                $exif = (string) stream_get_contents($stream, $chunk['size']);

                // Some encoders keep the JPEG style prefix
                return str_starts_with($exif, "Exif\0\0") ? substr($exif, 6) : $exif;
            }
            if ($chunk['id'] === 'IEND') {
                break;
            }

            // WebP chunks are padded to an even length, PNG chunks are followed by a checksum
            $padding = $type === IMAGETYPE_WEBP ? $chunk['size'] & 1 : 0;
            fseek($stream, $chunk['size'] + $padding + $trailer, SEEK_CUR);
        }

        return null;
    }

    /**
     * Get the S3 user metadata of an object. Cached along with its other attributes.
     *
     * An object written before the module stored its hash is given its metadata here,
     * unless lazy_metadata_backfill is disabled.
     *
     * @return array<string,string> Empty if the object is missing or has no metadata
     */
    public function getObjectMetadata(string $path): array
    {
        $metadata = $this->loadObjectMetadata($path);

        if ($metadata === null
            || isset($metadata[self::METADATA_SHA1])
            || !SSConfig::inst()->get(static::class, 'lazy_metadata_backfill')
        ) {
            return $metadata ?? [];
        }

        try {
            $backfilled = $this->backfillObjectMetadata($path);
        } catch (Throwable $e) {
            // e.g. no permission to write. The caller falls back to reading the file itself.
            return $metadata;
        }

        // Nothing to backfill means the object gained its metadata after it was cached here
        if ($backfilled === null) {
            $this->purgeCachePath($path);
            $backfilled = $this->loadObjectMetadata($path);
        }

        return $backfilled ?? [];
    }

    /**
     * @return array<string,string>|null Null if the object is missing
     */
    private function loadObjectMetadata(string $path): ?array
    {
        try {
            $attributes = $this->getFileAttributes(
                path: $path,
                loader: function () use ($path) {
                    $attributes = parent::fileSize($path);

                    // Always record a value, so objects without metadata aren't looked up again
                    return CachedAwsS3V3Adapter::mergeFileAttributes(
                        fileAttributesBase: $attributes,
                        fileAttributesExtension: new FileAttributes(
                            path: $path,
                            extraMetadata: ['Metadata' => $attributes->extraMetadata()['Metadata'] ?? []]
                        ),
                    );
                },
                attributeAccessor: function (FileAttributes $fileAttributes) {
                    return $fileAttributes->extraMetadata()['Metadata'] ?? null;
                },
            );
        } catch (UnableToRetrieveMetadata $e) {
            return null;
        }

        return $attributes->extraMetadata()['Metadata'] ?? [];
    }

    /**
     * Add the hash and dimension metadata to an object written before this metadata existed,
     * by downloading it once and copying it onto itself.
     *
     * @param bool $force Recalculate even if the object already has a hash
     * @param bool $dryRun Calculate the metadata, but don't write it
     * @return array<string,string>|null The object's new metadata, or null if it was left alone
     */
    public function backfillObjectMetadata(string $path, bool $force = false, bool $dryRun = false): ?array
    {
        $key = $this->s3Prefixer->prefixPath($path);
        $head = $this->s3Client->headObject(['Bucket' => $this->s3Bucket, 'Key' => $key]);
        $existing = $head['Metadata'] ?? [];

        if (!$force && isset($existing[self::METADATA_SHA1])) {
            return null;
        }

        $buffer = fopen('php://temp', 'r+');
        $source = parent::readStream($path);
        stream_copy_to_stream($source, $buffer);
        fclose($source);
        $detected = static::detectObjectMetadata($buffer);
        fclose($buffer);

        // Keep any unrelated metadata, but not dimensions which no longer apply
        unset(
            $existing[self::METADATA_WIDTH],
            $existing[self::METADATA_HEIGHT],
            $existing[self::METADATA_ORIENTATION]
        );
        $metadata = $detected + $existing;

        if ($dryRun) {
            return $metadata;
        }

        // Only copy if the object is still the one that was just hashed
        $params = ['Metadata' => $metadata, 'CopySourceIfMatch' => $head['ETag']];
        foreach (self::BACKFILL_PRESERVED_FIELDS as $field) {
            if (isset($head[$field]) && $head[$field] !== '') {
                $params[$field] = $head[$field];
            }
        }

        // A copy doesn't carry over the ACL either
        $acl = $this->s3Visibility->visibilityToAcl(parent::visibility($path)->visibility() ?: 'private');

        $this->s3Client->copy(
            $this->s3Bucket,
            $key,
            $this->s3Bucket,
            $key,
            $acl,
            ['params' => $params, 'MetadataDirective' => 'REPLACE']
        );

        $this->purgeCachePath($path);

        return $metadata;
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
        if (SSConfig::inst()->get(static::class, 'flush_enabled')) {
            Injector::inst()->get(CacheInterface::class . '.s3Cache')->clear();
        }
    }
}
