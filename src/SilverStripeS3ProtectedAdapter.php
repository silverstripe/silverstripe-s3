<?php

namespace Madmatt\SilverStripeS3;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use SilverStripe\Assets\Flysystem\ProtectedAdapter;

/**
 * An adapter that allows the use of AWS S3 to store and transmit assets rather than storing them locally.
 */
class SilverStripeS3ProtectedAdapter extends AwsS3Adapter implements ProtectedAdapter
{
    use SilverStripeS3AdapterTrait;

    /**
     * Pre-signed request expiration time in seconds, or relative string
     *
     * @var int|string
     */
    protected $expiry = 300;

    /**
     * @param S3Client $s3Client
     */
    public function __construct(S3Client $s3Client)
    {
        parent::__construct($s3Client, $this->findAwsBucket(), $this->findBucketPrefix());
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

    /**
     * @return string
     */
    protected function findBucketPrefix()
    {
        $prefix = 'protected';
        if (getenv('AWS_PROTECTED_BUCKET_PREFIX') !== false) {
            $prefix = (string) getenv('AWS_PROTECTED_BUCKET_PREFIX');
        }

        return $prefix;
    }
}
