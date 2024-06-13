# Setting up a local sandbox for developing the Silverstripe S3 module

This article will guide you through configuring a local project to do
development work on the `silverstripe/s3` module.

**This set up has not been optimised or hardened for a production environment.**
It may be sub-optimal or it may be too permissive for a production system.

## Setting up a bucket

In this step, we create a publicly accessible S3 bucket. **Any file you publish
on your test site will be accessible on the internet.** So be careful what test
files you upload in your sandbox.

-   [Access the AWS S3 console](https://s3.console.aws.amazon.com/s3/home).
-   Create a new bucket.
    -   Note down your bucket name and region.
-   Uncheck _Block all public access_ and make sure all the other sub-options are
    also unchecked.
    -   A scary warning will appear. Check the warning to acknowledge you are
        accepting the risk.
-   Leave the other options as-is and complete the bucket creation.

## Creating credentials to access your bucket

In this step, we create an AWS user and grant it the permision to read and write
files to our bucket.

-   [Acces the AWS _Identity and Access Management_
    console](https://console.aws.amazon.com/iam/home).
-   Navigate to the _Policies_ section.
-   Create a new policiy with the following JSON code, substituting
    `my-silverstripe-bucket` with the actual name of your bucket.

```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Sid": "ssbucket1",
            "Effect": "Allow",
            "Action": ["s3:*"],
            "Resource": "arn:aws:s3:::my-silverstripe-bucket"
        },
        {
            "Sid": "ssbucket2",
            "Effect": "Allow",
            "Action": ["s3:*"],
            "Resource": "arn:aws:s3:::my-silverstripe-bucket/*"
        }
    ]
}
```

-   Review your policy and give it a suitable name.
-   Save your policy by cliking the _Create policy_ button.
-   Navigate to the _Users_ section of the AWS _Identity and Access Management_
    console.
-   Add a user.
    -   Give the user a name.
    -   Check _Programmatic access_ under _Access type_.
    -   Click on _Next: Permissions_
    -   Attach the policy you just created to the user.
    -   Keep clicking next until your user has been created.
-   On the final screen, you'll be provided a _Access key ID_ and a _Secret access
    key_.
    -   Note down those values for later.

## Creating a sandbox project

In this step, you will create a Silverstripe CMS sandbox project that will be
configured to save files to your S3 bucket with the credentials that you just
generated. You can run your project in whichever test environment suits you.

-   Create a new Silverstripe CMS project with `composer create-project
silverstripe/installer s3-sandbox 4.x-dev`
-   From the project folder, run this command to install the development branch of
    the module `composer require silverstripe/s3 dev-master`
-   Configure a web server to run your sandbox project.
-   Configure an `.env` file with these values, adapting them as need be.

```dotenv
# These values should be configured to match your local environment
SS_BASE_URL="http://s3-sandbox.local"
SS_DATABASE_NAME="s3-sandbox"
SS_DATABASE_SERVER="localhost"

SS_DATABASE_CLASS="MySQLDatabase"
SS_DATABASE_PASSWORD=""
SS_DATABASE_USERNAME="root"
SS_ENVIRONMENT_TYPE="dev"

SS_DEFAULT_ADMIN_USERNAME="admin"
SS_DEFAULT_ADMIN_PASSWORD="admin"

# You should have generated these values in the first step
AWS_REGION="ap-southeast-2"
AWS_BUCKET_NAME="YOURBUCKETNAME"

# You should have generated these values in the second step
AWS_ACCESS_KEY_ID="ACCESID"
AWS_SECRET_ACCESS_KEY="SECRET"
```

-   Add the following YML configuration to a file under `app/_config`.

```yml
---
Only:
envvarset: AWS_BUCKET_NAME
After:
    - "#silverstripes3-flysystem"
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

-   Run this command to build your sandbox project. `vendor/bin/sake dev/build`

Your site should be functional by this point.
