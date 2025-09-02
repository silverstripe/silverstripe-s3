<?php

namespace SilverStripe\S3\Adapter;

use Aws\S3\S3Client;
use InvalidArgumentException;
use League\Flysystem\AwsS3V3\VisibilityConverter;
use League\Flysystem\Config;
use League\MimeTypeDetection\MimeTypeDetector;
use SilverStripe\Assets\Flysystem\PublicAdapter as SilverstripePublicAdapter;

class PublicAdapter extends CachedAwsS3V3Adapter implements SilverstripePublicAdapter
{

    public function __construct(S3Client $client, $bucket, $prefix = '', ?VisibilityConverter $visibility = null, ?MimeTypeDetector $mimeTypeDetector = null, array $options = [])
    {
        if (!$bucket) {
            throw new InvalidArgumentException("AWS_BUCKET_NAME environment variable not set");
        }

        if (!$prefix) {
            $prefix = 'public';
        }

        parent::__construct($client, $bucket, $prefix ?? '', $visibility, $mimeTypeDetector, $options);
    }

    /**
     * @param string $path
     *
     * @return string
     */
    public function getPublicUrl($path)
    {

        return $this->publicUrl($path, new Config());
    }
}
