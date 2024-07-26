# silverstripe-s3

SilverStripe module to store assets in S3 rather than on the local filesystem.

```sh
composer require silverstripe/s3
```

> [!WARNING]
> This module does not currently implement any kind of bucket policy for
> protected assets. It is up to you to implement this yourself using AWS bucket
> policy.

> [!CAUTION]
> This replaces the built-in local asset store that comes with SilverStripe
> with one based on S3. Any files that had previously been uploaded to an
> existing asset store will be unavailable (though they won't be lost - just
> run `composer remove silverstripe/s3` to remove the module and restore
> access).

## Environment setup

The module requires a few environment variables to be set.

-   `AWS_REGION`: The AWS region your S3 bucket is hosted in (e.g. `eu-central-1`)
-   `AWS_BUCKET_NAME`: The name of the S3 bucket to store assets in.

If running outside of an EC2 instance it will be necessary to specify an API key
and secret.

-   `AWS_ACCESS_KEY_ID`: Your AWS access key that has access to the bucket you
    want to access
-   `AWS_SECRET_ACCESS_KEY`: Your AWS secret corresponding to the access key

**Example YML Config when running outside of EC2:**

```yml
---
Only:
    envvarset: AWS_BUCKET_NAME
After:
    - "#assetsflysystem"
---
SilverStripe\Core\Injector\Injector:
    Aws\S3\S3Client:
        constructor:
            configuration:
                region: "`AWS_REGION`"
                version: latest
                credentials:
                    key: "`AWS_ACCESS_KEY_ID`"
                    secret: "`AWS_SECRET_ACCESS_KEY`"
```

## (Optional) CDN Implementation

If you're serving assets from S3, it's recommended that you utilize CloudFront.
This improves performance and security over exposing from S3 directly.

Once you've set up your CloudFront distribution, ensure that assets are
reachable within the `assets` directory of the cdn (for example;
https://cdn.example.com/assets/Uploads/file.jpg) and set the following
environment variable:

-   `AWS_PUBLIC_CDN_PREFIX`: Your CloudFront distribution domain that has access
    to the bucket you want to access

For example, adding this to your `.env`:

`AWS_PUBLIC_CDN_PREFIX='https://cdn.example.com/'`

will change your URLs from something like:

`https://s3.ap-southeast-2.amazonaws.com/mycdn/public/example/live/assets/Uploads/file.jpg`

to something like:

`https://cdn.example.com/assets/Uploads/file.jpg`

You can override the default `/assets/` path by declaring the PublicCDNAdapter
constructor, with the parameter for the `cdnAssetsDir` set to a string of your
folder name. In your `app/_config/assets.yml` file add the following:

```yml
---
Name: app#silverstripes3-cdn
Only:
    envvarset: AWS_PUBLIC_CDN_PREFIX
After:
    - "#assetsflysystem"
    - "#silverstripes3-flysystem"
---
SilverStripe\Core\Injector\Injector:
    SilverStripe\S3\Adapter\PublicAdapter:
        class: SilverStripe\S3\Adapter\PublicCDNAdapter
        constructor:
            s3Client: '%$Aws\S3\S3Client'
            bucket: "`AWS_BUCKET_NAME`"
            prefix: "`AWS_PUBLIC_BUCKET_PREFIX`"
            visibility: null
            mimeTypeDetector: null
            cdnPrefix: "`AWS_PUBLIC_CDN_PREFIX`"
            options: []
            cdnAssetsDir: "cms-assets" # example of a custom assets folder name
```

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
        "Version": "2012-10-17",
        "Statement": [
            {
                "Sid": "AddPerm",
                "Effect": "Allow",
                "Principal": "*",
                "Action": "s3:GetObject",
                "Resource": "arn:aws:s3:::<bucket-name>/public/*"
            }
        ]
    }
}
```

If you are utilizing a CloudFront distribution for your public assets, you will
have the option of securing your S3 bucket against all public access while still
allowing access to your `public` files via your CloudFront distribution and
access to your `protected` files via signed URLs.

## For developers

Read [Setting up a local sandbox for developing the Silverstripe S3
module](doc/en/setting-local-dev-environment.md) if you wish to do some local
development.

### Performance

This module comes with a basic in-memory cache for calls to S3. It is highly
recommended to add an additional layer of caching to achieve the best results.

See https://docs.silverstripe.org/en/5/developer_guides/performance/caching/ for
more information.

```yaml
Name: silverstripes3-flysystem-memcached
After:
    - "#silverstripes3-flysystem"
---
SilverStripe\Core\Injector\Injector:
    MemcachedClient:
        class: "Memcached"
        calls:
            - [addServer, ["localhost", 11211]]
    MemcachedCacheFactory:
        class: 'SilverStripe\Core\Cache\MemcachedCacheFactory'
        constructor:
            client: "%$MemcachedClient"
    SilverStripe\Core\Cache\CacheFactory: "%$MemcachedCacheFactory"
```

## Uninstalling

Run `composer remove silverstripe/s3` to remove the module.
