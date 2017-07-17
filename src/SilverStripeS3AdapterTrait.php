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
     * @throws LogicException
     *
     * @return string
     */
    public function findAwsBucket()
    {
        if (getenv('AWS_BUCKET_NAME') !== false) {
            return (string) getenv('AWS_BUCKET_NAME');
        }
        throw new LogicException('AWS_BUCKET_NAME environment variable not set');
    }
}
