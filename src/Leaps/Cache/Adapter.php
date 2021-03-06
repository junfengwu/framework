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

use Leaps\Utility\Str;
use Leaps\Di\Injectable;

abstract class Adapter extends Injectable
{
	/**
	 *
	 * @var string 缓存前置
	 */
	public $keyPrefix;

	/**
	 * 编译缓存的Key
	 *
	 * @param mixed $key the key to be normalized
	 * @return string the generated cache key
	 */
	public function buildKey($key)
	{
		$key = ctype_alnum ( $key ) && Str::byteLength ( $key ) <= 32 ? $key : md5 ( $key );
		return $this->keyPrefix . $key;
	}

	/**
	 * Checks whether a specified key exists in the cache.
	 * This can be faster than getting the value from the cache if the data is big.
	 * In case a cache does not support this feature natively, this method will try to simulate it
	 * but has no performance improvement over getting it.
	 * Note that this method does not check whether the dependency associated
	 * with the cached data, if there is any, has changed. So a call to [[get]]
	 * may return false while exists returns true.
	 *
	 * @param mixed $key a key identifying the cached value. This can be a simple string or
	 *        a complex data structure consisting of factors representing the key.
	 * @return boolean true if a value exists in cache, false if the value is not in the cache or expired.
	 */
	public function exists($key)
	{
		$key = $this->buildKey ( $key );
		$value = $this->getValue ( $key );
		return $value !== false;
	}

	/**
	 * Retrieves a value from cache with a specified key.
	 *
	 * @param mixed $key a key identifying the cached value. This can be a simple string or
	 *        a complex data structure consisting of factors representing the key.
	 * @return mixed the value stored in cache, false if the value is not in the cache, expired,
	 *         or the dependency associated with the cached data has changed.
	 */
	public function get($key)
	{
		$key = $this->buildKey ( $key );
		$value = $this->getValue ( $key );
		if ($value === false) {
			return $value;
		} else {
			return unserialize ( $value );
		}
		return false;
	}

	/**
	 * Stores a value identified by a key into cache.
	 * If the cache already contains such a key, the existing value and
	 * expiration time will be replaced with the new ones, respectively.
	 *
	 * @param mixed $key a key identifying the value to be cached. This can be a simple string or
	 *        a complex data structure consisting of factors representing the key.
	 * @param mixed $value the value to be cached
	 * @param integer $duration the number of seconds in which the cached value will expire. 0 means never expire.
	 * @return boolean whether the value is successfully stored into cache
	 */
	public function set($key, $value, $duration = 0)
	{
		$key = $this->buildKey ( $key );
		$value = serialize ( $value );
		return $this->setValue ( $key, $value, $duration );
	}

	/**
	 * Stores a value identified by a key into cache if the cache does not contain this key.
	 * Nothing will be done if the cache already contains the key.
	 *
	 * @param mixed $key a key identifying the value to be cached. This can be a simple string or
	 *        a complex data structure consisting of factors representing the key.
	 * @param mixed $value the value to be cached
	 * @param integer $duration the number of seconds in which the cached value will expire. 0 means never expire.
	 * @return boolean whether the value is successfully stored into cache
	 */
	public function add($key, $value, $duration = 0)
	{
		$value = serialize ( $value );
		$key = $this->buildKey ( $key );
		return $this->addValue ( $key, $value, $duration );
	}

	/**
	 * Deletes a value with the specified key from cache
	 *
	 * @param mixed $key a key identifying the value to be deleted from cache. This can be a simple string or
	 *        a complex data structure consisting of factors representing the key.
	 * @return boolean if no error happens during deletion
	 */
	public function delete($key)
	{
		$key = $this->buildKey ( $key );
		return $this->deleteValue ( $key );
	}

	/**
	 * Deletes all values from cache.
	 * Be careful of performing this operation if the cache is shared among multiple applications.
	 *
	 * @return boolean whether the flush operation was successful.
	 */
	public function flush()
	{
		return $this->flushValues ();
	}

	/**
	 * Retrieves a value from cache with a specified key.
	 * This method should be implemented by child classes to retrieve the data
	 * from specific cache storage.
	 *
	 * @param string $key a unique key identifying the cached value
	 * @return string|boolean stored in cache, false if the value is not in the cache or expired.
	 */
	abstract protected function getValue($key);

	/**
	 * Stores a value identified by a key in cache.
	 * This method should be implemented by child classes to store the data
	 * in specific cache storage.
	 *
	 * @param string $key the key identifying the value to be cached
	 * @param string $value the value to be cached
	 * @param integer $duration the number of seconds in which the cached value will expire. 0 means never expire.
	 * @return boolean true if the value is successfully stored into cache, false otherwise
	 */
	abstract protected function setValue($key, $value, $duration);

	/**
	 * Stores a value identified by a key into cache if the cache does not contain this key.
	 * This method should be implemented by child classes to store the data
	 * in specific cache storage.
	 *
	 * @param string $key the key identifying the value to be cached
	 * @param string $value the value to be cached
	 * @param integer $duration the number of seconds in which the cached value will expire. 0 means never expire.
	 * @return boolean true if the value is successfully stored into cache, false otherwise
	 */
	abstract protected function addValue($key, $value, $duration);

	/**
	 * Deletes a value with the specified key from cache
	 * This method should be implemented by child classes to delete the data from actual cache storage.
	 *
	 * @param string $key the key of the value to be deleted
	 * @return boolean if no error happens during deletion
	 */
	abstract protected function deleteValue($key);

	/**
	 * Deletes all values from cache.
	 * Child classes may implement this method to realize the flush operation.
	 *
	 * @return boolean whether the flush operation was successful.
	 */
	abstract protected function flushValues();

	/**
	 * Retrieves multiple values from cache with the specified keys.
	 * The default implementation calls [[getValue()]] multiple times to retrieve
	 * the cached values one by one. If the underlying cache storage supports multiget,
	 * this method should be overridden to exploit that feature.
	 *
	 * @param array $keys a list of keys identifying the cached values
	 * @return array a list of cached values indexed by the keys
	 */
	protected function getValues($keys)
	{
		$results = [ ];
		foreach ( $keys as $key ) {
			$results [$key] = $this->getValue ( $key );
		}
		return $results;
	}

	/**
	 * Stores multiple key-value pairs in cache.
	 * The default implementation calls [[setValue()]] multiple times store values one by one. If the underlying cache
	 * storage supports multi-set, this method should be overridden to exploit that feature.
	 *
	 * @param array $data array where key corresponds to cache key while value is the value stored
	 * @param integer $duration the number of seconds in which the cached values will expire. 0 means never expire.
	 * @return array array of failed keys
	 */
	protected function setValues($data, $duration)
	{
		$failedKeys = [ ];
		foreach ( $data as $key => $value ) {
			if ($this->setValue ( $key, $value, $duration ) === false) {
				$failedKeys [] = $key;
			}
		}
		return $failedKeys;
	}

	/**
	 * Adds multiple key-value pairs to cache.
	 * The default implementation calls [[addValue()]] multiple times add values one by one. If the underlying cache
	 * storage supports multi-add, this method should be overridden to exploit that feature.
	 *
	 * @param array $data array where key corresponds to cache key while value is the value stored
	 * @param integer $duration the number of seconds in which the cached values will expire. 0 means never expire.
	 * @return array array of failed keys
	 */
	protected function addValues($data, $duration)
	{
		$failedKeys = [ ];
		foreach ( $data as $key => $value ) {
			if ($this->addValue ( $key, $value, $duration ) === false) {
				$failedKeys [] = $key;
			}
		}
		return $failedKeys;
	}

	/**
	 * Returns whether there is a cache entry with a specified key.
	 * This method is required by the interface ArrayAccess.
	 *
	 * @param string $key a key identifying the cached value
	 * @return boolean
	 */
	public function offsetExists($key)
	{
		return $this->get ( $key ) !== false;
	}

	/**
	 * Retrieves the value from cache with a specified key.
	 * This method is required by the interface ArrayAccess.
	 *
	 * @param string $key a key identifying the cached value
	 * @return mixed the value stored in cache, false if the value is not in the cache or expired.
	 */
	public function offsetGet($key)
	{
		return $this->get ( $key );
	}

	/**
	 * Stores the value identified by a key into cache.
	 * If the cache already contains such a key, the existing value will be
	 * replaced with the new ones. To add expiration and dependencies, use the [[set()]] method.
	 * This method is required by the interface ArrayAccess.
	 *
	 * @param string $key the key identifying the value to be cached
	 * @param mixed $value the value to be cached
	 */
	public function offsetSet($key, $value)
	{
		$this->set ( $key, $value );
	}

	/**
	 * Deletes the value with the specified key from cache
	 * This method is required by the interface ArrayAccess.
	 *
	 * @param string $key the key of the value to be deleted
	 */
	public function offsetUnset($key)
	{
		$this->delete ( $key );
	}
}