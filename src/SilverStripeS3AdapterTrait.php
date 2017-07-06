<?php

namespace Madmatt\SilverStripeS3;

/**
 * Class SilverStripeAwsS3Adapter_Trait
 * @package Madmatt\SilverStripeS3
 *
 * Contains methods shared between both public and protected adapters, primarily to find an appropriate AWS S3
 * key/secret to use.
 */
trait SilverStripeS3AdapterTrait
{
    public function findAwsKey()
    {
        if (getenv('SS_AWS_S3_KEY') !== false) {
            return (string)getenv('SS_AWS_S3_KEY');
        } elseif (getenv('SS_AWS_KEY') !== false) {
            return (string)getenv('SS_AWS_KEY');
        } else {
            throw new \LogicException('No valid AWS Access Key found. Please add SS_AWS_S3_KEY to your env.');
        }
    }

    public function findAwsSecret()
    {
        if (getenv('SS_AWS_S3_SECRET') !== false) {
            return (string)getenv('SS_AWS_S3_SECRET');
        } elseif (getenv('SS_AWS_SECRET') !== false) {
            return (string)getenv('SS_AWS_SECRET');
        } else {
            throw new \LogicException('No valid AWS secret found. Please add SS_AWS_S3_SECRET to your env.');
        }
    }

    public function findAwsRegion()
    {
        if (getenv('SS_AWS_S3_REGION') !== false) {
            return (string)getenv('SS_AWS_S3_REGION');
        } elseif (getenv('SS_AWS_REGION') !== false) {
            return (string)getenv('SS_AWS_REGION');
        } else {
            throw new \LogicException('No valid AWS region specified. Please add SS_AWS_S3_REGION to your env.');
        }
    }

    public function findAwsBucket()
    {
        if (getenv('SS_AWS_S3_BUCKET_NAME') !== false) {
            return (string)getenv('SS_AWS_S3_BUCKET_NAME');
        } elseif (getenv('SS_AWS_BUCKET_NAME') !== false) {
            return (string)getenv('SS_AWS_BUCKET_NAME');
        } else {
            throw new \LogicException('No valid AWS S3 bucket found. Please add SS_AWS_S3_BUCKET_NAME to your env.');
        }
    }
}
