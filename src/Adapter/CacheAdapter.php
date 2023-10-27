<?php

namespace SilverStripe\S3\Adapter;

use jgivoni\Flysystem\Cache\CacheAdapter as jgivoniCacheAdapter;

class CacheAdapter extends jgivoniCacheAdapter{
    public function getAdapter(){
        return $this->adapter;
    }
}
