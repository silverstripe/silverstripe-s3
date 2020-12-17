# silverstripe-s3

SilverStripe module to store assets in S3 rather than on the local filesystem.

Note: This module does not currently implement any kind of bucket policy for
protected assets. It is up to you to implement this yourself using AWS bucket
policy.

## Environment setup

The module requires a few environment variables to be set. Full details can be
seen in the `SilverStripeS3AdapterTrait` trait. These are mandatory.

* `AWS_REGION`: The AWS region your S3 bucket is hosted in (e.g. `eu-central-1`)
* `AWS_BUCKET_NAME`: The name of the S3 bucket to store assets in.

If running outside of an EC2 instance it will be necessary to specify an API key
and secret.

* `AWS_ACCESS_KEY_ID`: Your AWS access key that has access to the bucket you
  want to access
* `AWS_SECRET_ACCESS_KEY`: Your AWS secret corresponding to the access key

**Example YML Config when running outside of EC2:**

```yml
---
Only:
  envvarset: AWS_BUCKET_NAME
After:
  - '#assetsflysystem'
---
SilverStripe\Core\Injector\Injector:
  Aws\S3\S3Client:
    constructor:
      configuration:
        region: '`AWS_REGION`'
        version: latest
        credentials:
          key: '`AWS_ACCESS_KEY_ID`'
          secret: '`AWS_SECRET_ACCESS_KEY`'
```

## (Optional) CDN Implementation

If you're serving assets from S3, it's recommended that you utilise CloudFront.
This improves performance and security over exposing from S3 directly.

Once you've set up your CloudFront distribution, ensure that assets are
reachable within the `assets` directory of the cdn (for example;
https://cdn.example.com/assets/Uploads/file.jpg) and set the following
environment variable:

* `AWS_PUBLIC_CDN_PREFIX`: Your CloudFront distribution domain that has access
  to the bucket you want to access

For example, adding this to your `.env`:

`AWS_PUBLIC_CDN_PREFIX='https://cdn.example.com/'`

will change your URLs from something like:

`https://s3.ap-southeast-2.amazonaws.com/mycdn/public/example/live/assets/Uploads/file.jpg`

to something like:

`https://cdn.example.com/assets/Uploads/file.jpg`


## Installation

* Define the environment variables listed above.
* [Install Composer from
  https://getcomposer.org](https://getcomposer.org/download/)
* Run `composer require silverstripe/s3`

This will install the most recent applicable version of the module given your
other Composer requirements.

**Note:** This currently immediately replaces the built-in local asset store
that comes with SilverStripe with one based on S3. Any files that had previously
been uploaded to an existing asset store will be unavailable (though they won't
be lost - just run `composer remove silverstripe/s3` to remove the module and
restore access).

## Configuration

Assets are classed as either 'public' or 'protected' by SilverStripe. Public
assets can be freely downloaded, whereas protected assets (e.g. assets not yet
published) shouldn't be directly accessed.

The module supports this by streaming the contents of protected files down to
the browser via the web server (as opposed to linking to S3 directly) by
default. To ensure that protected assets can't be accessed, ensure you setup an
appropriate bucket policy (see below for an untested example).

### Configuring S3

The 'protected' S3 asset store should be protected using standard AWS IAM
policies that disallow all access to anonymous users, but still allow the action
`s3:GetObject` for both public and protected files. Protected files will be
streamed from AWS, so they do not need to be accessed by users directly.
Therefore, something similar to the following bucket policy may be useful.

Make sure you replace `<bucket-name>` below with the appropriate values.

**Note:** The below policy has not been extensively tested - feedback welcome.

```json
{
    "Policy": {
		"Version":"2012-10-17",
		"Statement":[
			{
				"Sid":"AddPerm",
				"Effect":"Allow",
				"Principal":"*",
				"Action":"s3:GetObject",
				"Resource":"arn:aws:s3:::<bucket-name>/public/*"
			}
		]
	}
}
```

If you are utilising a CloudFront distribution for your public assets, you will
have the option of securing your S3 bucket against all public access while still
allowing access to your `public` files via your CloudFront distribution and
access to your `protected` files via signed URLs.

## For developers

Read [Setting up a local sandbox for developing the Silverstripe S3
module](doc/en/setting-local-dev-environment.md) if you wish to do some local
development.

## Uninstalling

* Run `composer remove silverstripe/s3` to remove the module.
