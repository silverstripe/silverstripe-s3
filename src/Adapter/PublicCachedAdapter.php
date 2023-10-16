<?php

namespace SilverStripe\S3\Adapter;

use SilverStripe\Assets\Flysystem\PublicAdapter;
use jgivoni\Flysystem\Cache\CacheAdapter;

class PublicCachedAdapter extends CacheAdapter implements PublicAdapter
{
    public function getPublicUrl($path)
    {
        return $this->getAdapter()->getPublicUrl($path);
    }
}
