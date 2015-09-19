<?php

namespace neTpyceB\TMCms\Cache;

use neTpyceB\TMCms\Log\Usage;
use neTpyceB\TMCms\Traits\singletonInstanceTrait;

/**
 * Class Cacher
 */
class Cacher
{
    use singletonInstanceTrait;

    /**
     * @var string
     */
    private $default_cache_classname = 'FileCache';

    /**
     * @return iCache
     */
    public function getDefaultCacher()
    {
        return call_user_func([__NAMESPACE__ . '\\' . $this->default_cache_classname, 'getInstance']);
    }

    /**
     * @param string $classname
     * @return bool
     */
    public function setDefaultCacher($classname)
    {
        $this->default_cache_classname = $classname;

        return true;
    }

    /**
     * Clears all caches in all available places
     */
    public function clearAllCaches()
    {
        // Save usage for stats
        Usage::getInstance()->add(__CLASS__, __FUNCTION__);

        if (FileCache::itWorks()) {
            $this->getFileCacher()->deleteAll();
        }

        if (MemcachedCache::itWorks()) {
            $this->getMemcachedCacher()->deleteAll();
        }

        if (APCCache::itWorks()) {
            $this->getApcCacher()->deleteAll();
        }

        if (FakeCache::itWorks()) {
            $this->getFakeCacher()->deleteAll();
        }
    }

    /**
     * @return FileCache
     */
    public function getFileCacher()
    {
        return call_user_func([__NAMESPACE__ . '\FileCache', 'getInstance']);
    }

    /**
     * @return MemcachedCache
     */
    public function getMemcachedCacher()
    {
        return call_user_func([__NAMESPACE__ . '\MemcachedCache', 'getInstance']);
    }

    /**
     * @return APCCache
     */
    public function getApcCacher()
    {
        return call_user_func([__NAMESPACE__ . '\APCCache', 'getInstance']);
    }

    /**
     * @return MemcachedCache
     */
    public function getFakeCacher()
    {
        return call_user_func([__NAMESPACE__ . '\FakeCache', 'getInstance']);
    }
}