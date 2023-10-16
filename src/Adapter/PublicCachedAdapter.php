<?php

namespace SilverStripe\S3\Adapter;

use SilverStripe\Assets\Flysystem\PublicAdapter;

class PublicCachedAdapter extends CacheAdapter implements PublicAdapter
{
    public function getPublicUrl($path)
    {
        return $this->getAdapter()->getPublicUrl($path);
    }
}
