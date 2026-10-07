<?php

namespace Silverstripe\S3;

use Exception;
use SilverStripe\Assets\FilenameParsing\FileResolutionStrategy;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Assets\Flysystem\Filesystem;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore as BaseFlysystemAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Versioned\Versioned;

class S3FlysystemAssetStore extends BaseFlysystemAssetStore
{
    /**
     * @config
     *
     * In pre 4.4 versions of SilverStripe, public files were stored in a
     * hashed folder structure such as public/{folder}/{hash}/{filename}. By
     * default we have moved away from that structure to a more simple structure
     * but if you have files that were uploaded in a pre 4.4 version then enable
     * this option.
     *
     * @var bool
     */
    private static $check_legacy_path = true;

    /**
     * @config
     *
     * @var bool
     */
    private static $throw_exceptions_on_missing_files = true;

    /**
     * @var bool
     */
    private $checkedForLegacyPath = false;



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


        // Protected first, as its file IDs include the hash. A public file ID does not, so a
        // draft asset would otherwise be mistaken for the published file of the same name.
        foreach ([$protectedSet, $publicSet] as $set) {
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
            function (ParsedFileID $parsedFileID, Filesystem $fs, FileResolutionStrategy $strategy) {
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

        // if the file is not found then it may be a legacy file where even
        // public files are stored hashed (i.e public/{folder}/{hash}/{filename})
        if (!$result && !$this->checkedForLegacyPath && $this->config()->check_legacy_path) {
            $exploded = explode('/', $filename);
            $newPath = [];

            $this->checkedForLegacyPath = true;

            foreach ($exploded as $i => $part) {
                if ($i === count($exploded) - 1) {
                    $newPath[] = substr($hash, 0, 10);
                }

                $newPath[] = $part;
            }

            $result = $this->exists(implode('/', $newPath), $hash, $variant);
            $this->checkedForLegacyPath = false;
        }

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


    /**
     * Whether a file in the given stage uses this asset. Core accounts for the other stage of the
     * file it is writing, but not for another file sharing the asset (see ImageEditorExtension).
     */
    protected function isUsedInStage($filename, $hash, $stage)
    {
        return Versioned::get_by_stage(File::class, $stage)
            ->filter(['FileFilename' => $filename, 'FileHash' => $hash])
            ->exists();
    }


    public function delete($filename, $hash)
    {
        $otherStage = Versioned::get_stage() === Versioned::LIVE ? Versioned::DRAFT : Versioned::LIVE;
        if ($this->isUsedInStage($filename, $hash, $otherStage)) {
            return false;
        }

        return parent::delete($filename, $hash);
    }


    public function protect($filename, $hash)
    {
        // A draft file must not take a published file's asset out of the public store
        if (Versioned::get_stage() === Versioned::DRAFT
            && $this->isUsedInStage($filename, $hash, Versioned::LIVE)
        ) {
            return;
        }

        parent::protect($filename, $hash);
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
