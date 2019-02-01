<?php

namespace SilverStripe\S3\Adapter;

use League\Flysystem\Cached\CachedAdapter;
use SilverStripe\Assets\Flysystem\PublicAdapter;

class PublicCachedAdapter extends CachedAdapter implements PublicAdapter
{
    public function getPublicUrl($path)
    {
        return $this->getAdapter()->getPublicUrl($path);
    }
}