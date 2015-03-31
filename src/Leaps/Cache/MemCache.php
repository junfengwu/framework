<?php
// +----------------------------------------------------------------------
// | Leaps Framework [ WE CAN DO IT JUST THINK IT ]
// +----------------------------------------------------------------------
// | Copyright (c) 2011-2014 Leaps Team (http://www.tintsoft.com)
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author XuTongle <xutongle@gmail.com>
// +----------------------------------------------------------------------
namespace Leaps\Cache;

use Leaps\Core\InvalidConfigException;

class MemCache extends Adapter
{
	public $useMemcached = false;
	public $persistentId;
	public $options;
	public $username;
	public $password;
	private $_cache = null;
	private $_servers = [ ];
	public function init()
	{
		parent::init ();
		$this->addServers ( $this->getMemcache (), $this->getServers () );
	}

	protected function addServers($cache, $servers)
	{
		if (empty ( $servers )) {
			$servers = [
					new MemCacheServer ( [
							'host' => '127.0.0.1',
							'port' => 11211
					] )
			];
		} else {
			foreach ( $servers as $server ) {
				if ($server->host === null) {
					throw new InvalidConfigException ( "The 'host' property must be specified for every memcache server." );
				}
			}
		}
		if ($this->useMemcached) {
			$this->addMemcachedServers ( $cache, $servers );
		} else {
			$this->addMemcacheServers ( $cache, $servers );
		}
	}

	/**
	 *
	 * @param \Memcached $cache
	 * @param array $servers
	 */
	protected function addMemcachedServers($cache, $servers)
	{
		$existingServers = [ ];
		if ($this->persistentId !== null) {
			foreach ( $cache->getServerList () as $s ) {
				$existingServers [$s ['host'] . ':' . $s ['port']] = true;
			}
		}
		foreach ( $servers as $server ) {
			if (empty ( $existingServers ) || ! isset ( $existingServers [$server->host . ':' . $server->port] )) {
				$cache->addServer ( $server->host, $server->port, $server->weight );
			}
		}
	}

	/**
	 *
	 * @param \Memcache $cache
	 * @param array $servers
	 */
	protected function addMemcacheServers($cache, $servers)
	{
		$class = new \ReflectionClass ( $cache );
		$paramCount = $class->getMethod ( 'addServer' )->getNumberOfParameters ();
		foreach ( $servers as $server ) {
			$timeout = ( int ) ($server->timeout / 1000) + (($server->timeout % 1000 > 0) ? 1 : 0);
			if ($paramCount === 9) {
				$cache->addServer ( $server->host, $server->port, $server->persistent, $server->weight, $timeout, $server->retryInterval, $server->status, $server->failureCallback, $server->timeout );
			} else {
				$cache->addServer ( $server->host, $server->port, $server->persistent, $server->weight, $timeout, $server->retryInterval, $server->status, $server->failureCallback );
			}
		}
	}

	/**
	 * Returns the underlying memcache (or memcached) object.
	 *
	 * @return \Memcache|\Memcached the memcache (or memcached) object used by this cache component.
	 * @throws InvalidConfigException if memcache or memcached extension is not loaded
	 */
	public function getMemcache()
	{
		if ($this->_cache === null) {
			$extension = $this->useMemcached ? 'memcached' : 'memcache';
			if (! extension_loaded ( $extension )) {
				throw new InvalidConfigException ( "MemCache requires PHP $extension extension to be loaded." );
			}

			if ($this->useMemcached) {
				$this->_cache = $this->persistentId !== null ? new \Memcached ( $this->persistentId ) : new \Memcached ();
				if ($this->username !== null || $this->password !== null) {
					$this->_cache->setOption ( \Memcached::OPT_BINARY_PROTOCOL, true );
					$this->_cache->setSaslAuthData ( $this->username, $this->password );
				}
				if (! empty ( $this->options )) {
					$this->_cache->setOptions ( $this->options );
				}
			} else {
				$this->_cache = new \Memcache ();
			}
		}

		return $this->_cache;
	}

	/**
	 * Returns the memcache or memcached server configurations.
	 *
	 * @return MemCacheServer[] list of memcache server configurations.
	 */
	public function getServers()
	{
		return $this->_servers;
	}

	/**
	 *
	 * @param array $config list of memcache or memcached server configurations. Each element must be an array
	 *        with the following keys: host, port, persistent, weight, timeout, retryInterval, status.
	 * @see http://php.net/manual/en/memcache.addserver.php
	 * @see http://php.net/manual/en/memcached.addserver.php
	 */
	public function setServers($config)
	{
		foreach ( $config as $c ) {
			$this->_servers [] = new MemCacheServer ( $c );
		}
	}

	/**
	 * Retrieves a value from cache with a specified key.
	 * This is the implementation of the method declared in the parent class.
	 *
	 * @param string $key a unique key identifying the cached value
	 * @return string|boolean the value stored in cache, false if the value is not in the cache or expired.
	 */
	protected function getValue($key)
	{
		return $this->_cache->get ( $key );
	}

	/**
	 * Retrieves multiple values from cache with the specified keys.
	 *
	 * @param array $keys a list of keys identifying the cached values
	 * @return array a list of cached values indexed by the keys
	 */
	protected function getValues($keys)
	{
		return $this->useMemcached ? $this->_cache->getMulti ( $keys ) : $this->_cache->get ( $keys );
	}

	/**
	 * Stores a value identified by a key in cache.
	 * This is the implementation of the method declared in the parent class.
	 *
	 * @param string $key the key identifying the value to be cached
	 * @param string $value the value to be cached
	 * @param integer $duration the number of seconds in which the cached value will expire. 0 means never expire.
	 * @return boolean true if the value is successfully stored into cache, false otherwise
	 */
	protected function setValue($key, $value, $duration)
	{
		$expire = $duration > 0 ? $duration + time () : 0;

		return $this->useMemcached ? $this->_cache->set ( $key, $value, $expire ) : $this->_cache->set ( $key, $value, 0, $expire );
	}

	/**
	 * Stores multiple key-value pairs in cache.
	 *
	 * @param array $data array where key corresponds to cache key while value is the value stored
	 * @param integer $duration the number of seconds in which the cached values will expire. 0 means never expire.
	 * @return array array of failed keys. Always empty in case of using memcached.
	 */
	protected function setValues($data, $duration)
	{
		if ($this->useMemcached) {
			$this->_cache->setMulti ( $data, $duration > 0 ? $duration + time () : 0 );

			return [ ];
		} else {
			return parent::setValues ( $data, $duration );
		}
	}

	/**
	 * Stores a value identified by a key into cache if the cache does not contain this key.
	 * This is the implementation of the method declared in the parent class.
	 *
	 * @param string $key the key identifying the value to be cached
	 * @param string $value the value to be cached
	 * @param integer $duration the number of seconds in which the cached value will expire. 0 means never expire.
	 * @return boolean true if the value is successfully stored into cache, false otherwise
	 */
	protected function addValue($key, $value, $duration)
	{
		$expire = $duration > 0 ? $duration + time () : 0;

		return $this->useMemcached ? $this->_cache->add ( $key, $value, $expire ) : $this->_cache->add ( $key, $value, 0, $expire );
	}

	/**
	 * Deletes a value with the specified key from cache
	 * This is the implementation of the method declared in the parent class.
	 *
	 * @param string $key the key of the value to be deleted
	 * @return boolean if no error happens during deletion
	 */
	protected function deleteValue($key)
	{
		return $this->_cache->delete ( $key, 0 );
	}

	/**
	 * Deletes all values from cache.
	 * This is the implementation of the method declared in the parent class.
	 *
	 * @return boolean whether the flush operation was successful.
	 */
	protected function flushValues()
	{
		return $this->_cache->flush ();
	}
}