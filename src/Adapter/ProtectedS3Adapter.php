<?php

namespace SilverStripe\S3\Adapter;

use Aws\S3\S3Client;
use InvalidArgumentException;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use SilverStripe\Assets\Flysystem\ProtectedAdapter;

/**
 * An adapter that allows the use of AWS S3 to store and transmit assets rather than storing them locally.
 */
class ProtectedS3Adapter extends AwsS3Adapter implements ProtectedAdapter
{
    /**
     * Pre-signed request expiration time in seconds, or relative string
     *
     * @var int|string
     */
    protected $expiry = 300;

    public function __construct(S3Client $client, $bucket, $prefix = '', array $options = [])
    {
        if (!$bucket) {
            throw new InvalidArgumentException("AWS_BUCKET_NAME environment variable not set");
        }
        if (!$prefix) {
            $prefix = 'protected';
        }
        parent::__construct($client, $bucket, $prefix, $options);
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
        $cmd = $this->getClient()
            ->getCommand('GetObject', [
                'Bucket' => $this->getBucket(),
                'Key' => $this->applyPathPrefix($path),
            ]);

        // Format expiry
        $expiry = $this->getExpiry();
        if (is_numeric($expiry)) {
            $expiry = "+{$expiry} seconds";
        }

        return (string) $this->getClient()
            ->createPresignedRequest($cmd, $expiry)
            ->getUri();
    }
}
