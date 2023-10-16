<?php

namespace SilverStripe\S3\Adapter;

use SilverStripe\Assets\Flysystem\ProtectedAdapter;
use jgivoni\Flysystem\Cache\CacheAdapter;

class ProtectedCachedAdapter extends CacheAdapter implements ProtectedAdapter
{
    public function getProtectedUrl($path)
    {
        return $this->getAdapter()->getProtectedUrl($path);
    }
}
