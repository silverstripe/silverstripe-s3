# silverstripe-s3

SilverStripe module to store assets in S3 rather than on the local filesystem.

Note: This is a pre-release module, and does not currently implement any kind of bucket policy for protected assets. It is up to you to implement this yourself using AWS bucket policy.

## Environment setup

The module requires a few environment variables to be set. Full details can be seen in the `SilverStripeS3AdapterTrait` trait.

* `SS_AWS_S3_KEY`: Your AWS access key that has access to the bucket you want to access
* `SS_AWS_S3_SECRET`: Your AWS secret corresponding to the access key
* `SS_AWS_S3_REGION`: The AWS region your S3 bucket is hosted in (e.g. `eu-central-1`)
* `SS_AWS_S3_BUCKET_NAME`: The name of the S3 bucket to store assets in.

## Installation

For now, just use `composer require madmatt/silverstripe-s3 dev-master`, then define the environment variables listed above. You'll need to add `"minimum-stability": "dev"` to your composer.json as there's no stable versions released yet.
