<?php

namespace SilverStripe\S3\Adapter;

use League\Flysystem\Cached\CachedAdapter;
use SilverStripe\Assets\Flysystem\ProtectedAdapter as SilverStripeProtectedAdapter;

class ProtectedCachedAdapter extends CachedAdapter implements SilverStripeProtectedAdapter
{
    public function getProtectedUrl($path)
    {
        return $this->getAdapter()->getProtectedUrl($path);
    }
}
