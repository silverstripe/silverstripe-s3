<?php

namespace SilverStripe\S3\Adapter;

use Aws\S3\S3Client;
use InvalidArgumentException;
use League\Flysystem\AwsS3V3\VisibilityConverter;
use League\Flysystem\Config;
use League\MimeTypeDetection\MimeTypeDetector;
use SilverStripe\Assets\Flysystem\ProtectedAdapter as SilverstripeProtectedAdapter;

/**
 * An adapter that allows the use of AWS S3 to store and transmit assets rather than storing them locally.
 */
class ProtectedAdapter extends CachedAwsS3V3Adapter implements SilverstripeProtectedAdapter
{
    /**
     * Pre-signed request expiration time in seconds, or relative string
     *
     * @var int|string
     */
    protected $expiry = 300;

    public function __construct(S3Client $client, $bucket, $prefix = '', VisibilityConverter $visibility = null, MimeTypeDetector $mimeTypeDetector = null, array $options = [])
    {
        if (!$bucket) {
            throw new InvalidArgumentException("AWS_BUCKET_NAME environment variable not set");
        }

        if (!$prefix) {
            $prefix = 'protected';
        }

        parent::__construct($client, $bucket, $prefix, $visibility, $mimeTypeDetector, $options);

        $this->setCachePrefix('protected');
    }

    /**
     * @return int|string
     */
    public function getExpiry()
    {
        return $this->expiry;
    }


    /**
     * Set expiry. Supports either number of seconds (in int) or
     * a literal relative string.
     *
     * @param int|string $expiry
     * @return $this
     */
    public function setExpiry($expiry)
    {
        $this->expiry = $expiry;
        return $this;
    }


    /**
     * @param string $path
     *
     * @return string
     */
    public function getProtectedUrl($path)
    {
        $dt = new \DateTime();
        if (is_string($this->getExpiry())) {
            $dt = $dt->setTimestamp(strtotime($this->getExpiry()));
        } else {
            $dt = $dt->setTimestamp(strtotime('+' . $this->getExpiry() . ' seconds'));
        }

        return $this->temporaryUrl($path, $dt, new Config());
    }
}
