<?php

namespace SilverStripe\S3;

use League\Flysystem\Filesystem as LeagueFilesystem;
use SilverStripe\Assets\Flysystem\Filesystem;
use SilverStripe\Assets\Storage\Sha1FileHashingService;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\S3\Adapter\CachedAwsS3V3Adapter;

/**
 * Reads the hash of a file from the metadata stored on its S3 object, rather
 * than downloading the file to hash it. Objects without that metadata are
 * hashed from their contents as usual.
 */
class S3FileHashingService extends Sha1FileHashingService
{
    public function computeFromFile($fileID, $fs)
    {
        if ($hash = $this->get($fileID, $fs)) {
            return $hash;
        }

        $filesystem = $fs;
        if (is_string($fs) && Injector::inst()->has(LeagueFilesystem::class . '.' . $fs)) {
            $filesystem = Injector::inst()->get(LeagueFilesystem::class . '.' . $fs);
        }

        $adapter = $filesystem instanceof Filesystem ? $filesystem->getAdapter() : null;
        if ($adapter instanceof CachedAwsS3V3Adapter) {
            $hash = $adapter->getObjectMetadata($fileID)[CachedAwsS3V3Adapter::METADATA_SHA1] ?? '';

            if (preg_match('/^[a-f0-9]{40}$/', $hash)) {
                $this->set($fileID, $fs, $hash);

                return $hash;
            }
        }

        return parent::computeFromFile($fileID, $fs);
    }
}
