# silverstripe-s3

SilverStripe module to store assets in S3 rather than on the local filesystem.

Note: This is a pre-release module, and does not currently implement any kind of
bucket policy for protected assets. It is up to you to implement this yourself
using AWS bucket policy.

## Environment setup

The module requires a few environment variables to be set. Full details can
be seen in the `SilverStripeS3AdapterTrait` trait. These are mandatory.

* `SS_AWS_S3_REGION`: The AWS region your S3 bucket is hosted in (e.g. `eu-central-1`)
* `SS_AWS_S3_BUCKET_NAME`: The name of the S3 bucket to store assets in.

If running outside of an EC2 instance it will be necessary to specify an API key and secret.

* `SS_AWS_S3_KEY`: Your AWS access key that has access to the bucket you want to access
* `SS_AWS_S3_SECRET`: Your AWS secret corresponding to the access key

## Installation

* Define the environment variables listed above.
* [Install Composer from https://getcomposer.org](https://getcomposer.org/download/)
* Run `composer require madmatt/silverstripe-s3`

This will install the most recent applicable version of the module given your other Composer
requirements.

**Note:** This currently immediately replaces the built-in local asset store that comes with
SilverStripe with one based on S3. Any files that had previously been uploaded to an existing
asset store will be unavailable (though they won't be lost - just run `composer remove
madmatt/silverstripe-s3` to remove the module and restore access).

## Configuration

Assets are classed as either 'public' or 'protected' by SilverStripe. Public assets can be
freely downloaded, whereas protected assets (e.g. assets not yet published) shouldn't be
directly accessed.

The module supports this by streaming the contents of protected files down to the browser
via the web server (as opposed to linking to S3 directly) by default. To ensure that
protected assets can't be accessed, ensure you setup an appropriate bucket policy (see
below for an untested example).

### Configuring S3

The 'protected' S3 asset store should be protected using standard AWS IAM policies that
disallow all access to anonymous users, but still allow the action `s3:GetObject` for
both public and protected files. Protected files will be streamed from AWS, so they do
not need to be accessed by users directly. Therefore, something similar to the following
bucket policy may be useful.

Make sure you replace `<bucket-name>` and `<your-ARN>` below with the appropriate values.
`<your-ARN>` should match the IAM arn of the ec2 instance running your SilverStripe site.

```
{
    "Version": "2012-10-17",
    "Id": "AccessForPublicAssetsAndPassthruForProtectedAssets",
    "Statement": [
        {
            "Sid": "AllowAccessToPublicAssets",
            "Effect": "Allow",
            "Principal": "*",
            "Action": "s3:GetObject",
            "Resource": "arn:aws:s3:::<bucket-name>/public/*"
        },
        {
            "Sid": "ServerFullAccess",
            "Effect": "Allow",
            "Principal": {"AWS":"<your-ARN>"},
            "Action": "*",
            "Resource": "arn:aws:s3:::<bucket-name>/*"
        }
    ]
}
```

## Uninstalling

* Run `composer remove madmatt/silverstripe-s3` to remove the module.
