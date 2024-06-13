<?php

namespace Silverstripe\S3;

use Exception;
use SilverStripe\Assets\FilenameParsing\FileResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Assets\Flysystem\Filesystem;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore as BaseFlysystemAssetStore;
use SilverStripe\Assets\Storage\AssetStore;

class S3FlysystemAssetStore extends BaseFlysystemAssetStore
{
    /**
     * @config
     *
     * @var bool
     */
    private static $throw_exceptions_on_missing_files = true;

    protected function applyToFileOnFilesystem(callable $callable, ParsedFileID $parsedFileID, $strictHashCheck = true)
    {
        $publicSet = [
            $this->getPublicFilesystem(),
            $this->getPublicResolutionStrategy(),
            self::VISIBILITY_PUBLIC
        ];

        $protectedSet = [
            $this->getProtectedFilesystem(),
            $this->getProtectedResolutionStrategy(),
            self::VISIBILITY_PROTECTED
        ];


        foreach ([$publicSet, $protectedSet] as $set) {
            try {
                list($fs, $strategy, $visibility) = $set;

                // Get a FileID string based on the type of FileID
                $fileID =  $strategy->buildFileID($parsedFileID);

                if ($fs->has($fileID)) {
                    $closureParsedFileID = $parsedFileID->setFileID($fileID);

                    $response = $callable(
                        $closureParsedFileID,
                        $fs,
                        $strategy,
                        $visibility
                    );

                    if ($response !== false) {
                        return $response;
                    }
                }
            } catch (Exception $e) {
                // not found
            }
        }

        return null;
    }


    protected function writeWithCallback($callback, $filename, $hash, $variant = null, $config = [])
    {
        $this->purgeCaches($filename);

        $result = parent::writeWithCallback($callback, $filename, $hash, $variant, $config);

        $this->purgeCaches($filename);

        return $result;
    }


    public function exists($filename, $hash, $variant = null)
    {
        if (empty($filename)) {
            return false;
        }

        $result = $this->applyToFileOnFilesystem(
            function (ParsedFileID $parsedFileID, Filesystem $fs, FileResolutionStrategy $strategy) use ($filename) {
                $parsedFileID = $strategy->stripVariant($parsedFileID);

                if ($parsedFileID && $originalFileID = $parsedFileID->getFileID()) {
                    if ($fs->has($originalFileID)) {
                        return true;
                    }
                }

                return false;
            },
            new ParsedFileID($filename, $hash, $variant)
        ) ?: false;

        return $result;
    }


    public function getAsURL($filename, $hash, $variant = null, $grant = true)
    {
        $tuple = new ParsedFileID($filename, $hash, $variant);

        // Check with filesystem this asset exists in
        $public = $this->getPublicFilesystem();
        $protected = $this->getProtectedFilesystem();

        try {
            if ($parsedFileID = $this->getPublicResolutionStrategy()->searchForTuple($tuple, $public)) {
                /** @var PublicAdapter $publicAdapter */
                $publicAdapter = $public->getAdapter();

                return $publicAdapter->getPublicUrl($parsedFileID->getFileID());
            }
        } catch (Exception $e) {
            //
        }

        try {
            if ($parsedFileID = $this->getProtectedResolutionStrategy()->searchForTuple($tuple, $protected)) {
                if ($grant) {
                    $this->grant($parsedFileID->getFilename(), $parsedFileID->getHash());
                }
                /** @var ProtectedAdapter $protectedAdapter */
                $protectedAdapter = $protected->getAdapter();
                return $protectedAdapter->getProtectedUrl($parsedFileID->getFileID());
            }
        } catch (Exception $e) {
            //
        }

        $fileID = $this->getPublicResolutionStrategy()->buildFileID($tuple);

        /** @var PublicAdapter $publicAdapter */
        $publicAdapter = $public->getAdapter();

        return $publicAdapter->getPublicUrl($fileID);
    }


    public function getVisibility($filename, $hash)
    {
        // Check with filesystem this asset exists in
        $public = $this->getPublicFilesystem();
        $tuple = new ParsedFileID($filename, $hash);

        try {
            if ($this->getPublicResolutionStrategy()->searchForTuple($tuple, $public)) {
                return self::VISIBILITY_PUBLIC;
            }
        } catch (Exception $e) {
            //
        }

        return AssetStore::VISIBILITY_PROTECTED;
    }


    public function publish($filename, $hash)
    {
        parent::publish($filename, $hash);

        $this->purgeCaches($filename);
    }


    protected function purgeCaches($filename)
    {
        /** @var CachedAwsS3V3Adapter */
        $public = $this->getPublicFilesystem()->getAdapter();
        $public->purgeCachePath($filename);

        /** @var CachedAwsS3V3Adapter */
        $protected = $this->getProtectedFilesystem()->getAdapter();
        $protected->purgeCachePath($filename);
    }
}
