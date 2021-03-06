<?php

namespace TMCms\Cache;

use Memcache;
use TMCms\Cache\Interfaces\ICache;
use TMCms\Traits\singletonInstanceTrait;

/**
 * Class MemcacheCache
 * @package TMCms\Cache
 */
class MemcacheCache implements ICache
{
    use singletonInstanceTrait;

    const HOST = 'localhost';
    const PORT = 11211;

    /** @var Memcache connected instance */
    private static $Memcache = null;

    /**
     * @return bool
     */
    public static function itWorks()
    {
        return class_exists('Memcache');
    }

    public function disconnect()
    {
        self::$Memcache = NULL;
        self::$instance = NULL;
    }

    /**
     * @param string $key
     * @return bool|string[]
     */
    public function delete($key)
    {
        $this->set($key, NULL, 0);
        return self::$Memcache->delete(CFG_DOMAIN . $key);
    }

    /**
     * Updates existing value or creates new by key
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     * @return array|bool
     */
    public function set($key, $value, $ttl = 2592000)
    {
        if (!self::$instance) {
            self::getInstance();
        }
        $res = self::$Memcache->set(CFG_DOMAIN . $key, $value, MEMCACHE_COMPRESSED, $ttl);

        return $res;
    }

    /**
     * @return MemcacheCache
     */
    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self;
            self::$Memcache = new Memcache();
            self::$Memcache->addServer(self::HOST, self::PORT);
        }

        return self::$instance;
    }

    /**
     * @param string $key
     * @return bool|string[]
     */
    public function exists($key)
    {
        return (bool)$this->get($key);
    }

    /**
     * @param string $key
     * @return mixed|null
     */
    public function get($key)
    {
        if (!self::$instance) {
            self::getInstance();
        }
        $res = self::$Memcache->get(CFG_DOMAIN . $key);
        if ($res === false) {
            $res = NULL; // Return NULL if not found
        }

        return $res;
    }

    /**
     * @return bool
     */
    public function deleteAll()
    {
        return self::$Memcache->flush();
    }
}