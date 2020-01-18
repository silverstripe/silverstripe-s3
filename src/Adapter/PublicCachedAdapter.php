<?php

namespace SilverStripe\S3\Adapter;

use League\Flysystem\Cached\CachedAdapter;
use SilverStripe\Assets\Flysystem\PublicAdapter as SilverStripePublicAdapter;

class PublicCachedAdapter extends CachedAdapter implements SilverStripePublicAdapter
{
    public function getPublicUrl($path)
    {
        return $this->getAdapter()->getPublicUrl($path);
    }
}
