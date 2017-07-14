<?php

namespace Madmatt\SilverStripeS3;

use LogicException;

/**
 * Contains methods shared between both public and protected adapters,
 * primarily to find an appropriate AWS S3 key/secret to use.
 */
trait SilverStripeS3AdapterTrait
{
    /**
     * @return string
     * @throws LogicException
     */
    public function findAwsBucket()
    {
        if (getenv('AWS_BUCKET_NAME') !== false) {
            return (string) getenv('AWS_BUCKET_NAME');
        }
        throw new LogicException(
            'No valid AWS S3 bucket found. Please set AWS_BUCKET_NAME to your env.'
        );
    }

}
