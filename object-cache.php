<?php
/*
Plugin Name: 5cache
Description: Two layer cache using System V shared memory
Version: 0.1.0
Plugin URI: http://fabi.me/
Author: Fabian Schlieper

Copy this file to wp-content/object-cache.php
*/

/*
Todo: expiry
non_persistent_groups
make plugins contidionally persistent
*/

class WP_Object_Cache_Shm {
	const MAX_CACHE_SIZE = 1024 * 1024 * 4;

	private $multisite;
	private $blog_id;

	// stats must be public
	public $cache_misses = 0;
	public $cache_hits = 0;


	public $global_groups = array( 'WP_Object_Cache_global' );
	public $non_persistent_groups = array();

	private $shm = null;

	private $volatileCache = array();
	private $writeBackKeys = array();
	private $missingKeys = array();


	public function __construct() {
		$this->multisite = function_exists( 'is_multisite' ) && is_multisite();
		$this->blog_id   = $this->multisite ? ( 1 + get_current_blog_id() ) : 0;

		$this->open();

		register_shutdown_function( function () {
			register_shutdown_function( array( $this, 'close' ) );
		} );
	}

	private function open() {
		$this->volatileCache = array();
		$this->missingKeys   = array();
		$this->writeBackKeys = array();

		$shmKey    = ftok( __FILE__, chr( 32 + ( $this->blog_id % 94 ) ) );
		$this->shm = shm_attach( $shmKey, self::MAX_CACHE_SIZE );
		if ( ! is_resource( $this->shm ) ) {
			throw new \RuntimeException( 'shm_attach failed' );
		}
	}

	public function close() {
		// lazy write back before close
		foreach ( $this->writeBackKeys as $gKey => $one ) {
			shm_put_var( $this->shm, self::intHash( $gKey ), $this->volatileCache[ $gKey ] );
		}
		$this->writeBackKeys = array();

		$ok        = shm_detach( $this->shm );
		$this->shm = null;

		return $ok;
	}


	/**
	 * @param string $key
	 * @param mixed $data
	 * @param string $group
	 * @param int $expire
	 *
	 * @return bool
	 */
	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		$gKey = $this->gKey( $key, $group );

		if ( is_object( $data ) ) {
			$data = clone $data;
		}

		$this->volatileCache[ $gKey ] = $data;
		$this->writeBackKeys[ $gKey ] = 1;
		unset( $this->missingKeys[ $gKey ] );

		return true;
	}


	/**
	 * @param $key
	 * @param string $group
	 * @param bool $force_unused
	 * @param null $found
	 *
	 * @return bool|mixed
	 */
	public function get(
		$key, $group = 'default', /** @noinspection PhpUnusedParameterInspection */
		$force_unused = false, &$found = null
	) {
		$gKey = $this->gKey( $key, $group );

		if ( isset( $this->volatileCache[ $gKey ] ) ) {
			$found = true;
			++ $this->cache_hits;

			if(is_object($this->volatileCache[ $gKey ]))
				return clone $this->volatileCache[ $gKey ];
			else
				return $this->volatileCache[ $gKey ];
		}

		if ( isset( $this->missingKeys[ $gKey ] ) ) {
			$found = false;
			++ $this->cache_misses;

			return false;
		}

		$ki    = self::intHash( $gKey );
		$found = shm_has_var( $this->shm, $ki );
		if ( ! $found ) {
			$this->missingKeys[ $gKey ] = 1;
			++ $this->cache_misses;

			return false;
		}

		$value                        = shm_get_var( $this->shm, $ki );
		$this->volatileCache[ $gKey ] = $value;
		++ $this->cache_hits;

		return $value;
	}


	public function _delete(
		$key, $group = 'default', /** @noinspection PhpUnusedParameterInspection */
		$deprecated = false
	) {
		$gKey = $this->gKey( $key, $group );

		$ok = isset( $this->volatileCache[ $gKey ] );

		unset( $this->volatileCache[ $gKey ], $this->writeBackKeys[ $gKey ] );
		$this->missingKeys[ $gKey ] = 1;

		$ki = self::intHash( $key );
		if ( ! shm_has_var( $this->shm, $ki ) ) {

			return $ok;
		}

		return shm_remove_var( $this->shm, $ki ) || $ok;
	}


	/**
	 * Generates a prefixed unique key across all groups
	 *
	 * @param $key
	 * @param $group
	 *
	 * @return string the global key
	 */
	private function gKey( $key, &$group ) {
		if ( empty( $group ) ) {
			$group = 'default';
		}

		return "$group:$key";
	}

	private static function intHash( $str ) {
		$key = unpack( 'q', hash( 'md4', $str, true ) );

		return abs( $key[1] % PHP_INT_MAX );
	}


	/**
	 * Adds data to the cache, if the cache key doesn't already exist.
	 *
	 * @since 2.0.0
	 *
	 * @see WP_Object_Cache::add()
	 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
	 *
	 * @param int|string $key The cache key to use for retrieval later.
	 * @param mixed $data The data to add to the cache.
	 * @param string $group Optional. The group to add the cache to. Enables the same key
	 *                           to be used across groups. Default empty.
	 * @param int $expire Optional. When the cache data should expire, in seconds.
	 *                           Default 0 (no expiration).
	 *
	 * @return bool False if cache key and group already exist, true on success.
	 */
	function add( $key, $data, $group = 'default', $expire = 0 ) {
		if ( $this->_exist( $key, $group ) ) {
			return false;
		}

		return $this->set( $key, $data, $group, $expire );
	}


	public function add_global_groups( $groups ) {
		if ( ! is_array( $groups ) ) {
			$groups = (array) $groups;
		}

		$groups              = array_fill_keys( $groups, true );
		$this->global_groups = array_merge( $this->global_groups, $groups );
	}

	public function add_non_persistent_groups( $groups ) {
		if ( ! is_array( $groups ) ) {
			$groups = (array) $groups;
		}

		$groups                = array_fill_keys( $groups, true );
		$this->non_persistent_groups = array_merge( $this->non_persistent_groups, $groups );
	}

	public function decr( $key, $n = 1, $group = 'default' ) {
		return $this->incr( $key, - $n, $group );
	}


	/**
	 * Clears the object cache of all data.
	 *
	 * @since 2.0.0
	 * @access public
	 *
	 * @return true Always returns true.
	 */
	public function flush() {
		$this->volatileCache = array();
		$this->writeBackKeys = array();
		shm_remove( $this->shm );
		$this->close();
		$this->open();

		return true;
	}


	private function _exist( $key, $group ) {
		$gKey = $this->gKey( $key, $group );

		if ( isset( $this->volatileCache[ $gKey ] ) ) {
			return true;
		}

		if ( isset( $this->missingKeys[ $gKey ] ) ) {
			return false;
		}

		if ( shm_has_var( $this->shm, self::intHash( $gKey ) ) ) {
			return true;
		} else {
			$this->missingKeys[ $gKey ] = true;

			return false;
		}
	}

	public function incr( $key, $n = 1, $group = 'default' ) {
		$v = $this->get( $key, $group, false, $found );
		if ( ! $found ) {
			return false;
		}
		$v += $n;
		$this->set( $key, (int) $v, $group );

		return $v;
	}

	public function replace( $key, $data, $group = 'default', $expire = 0 ) {
		if ( ! $this->_exist( $key, $group ) ) {
			return false;
		}

		return $this->set( $key, $data, $group, $expire );
	}


	public function stats() {
		echo "<p>";
		echo "<strong>Cache Hits:</strong> {$this->cache_hits}<br />";
		echo "<strong>Cache Misses:</strong> {$this->cache_misses}<br />";
		echo "</p>";
	}

	public function switch_to_blog( $blog_id ) {
		$newBlogId = $this->multisite ? ( 1 + (int) $blog_id ) : 0;

		if ( $newBlogId == $this->blog_id ) {
			return;
		}

		$this->close();
		$this->blog_id = $newBlogId;
		$this->open();
	}
}


/**
 * Adds data to the cache, if the cache key doesn't already exist.
 *
 * @since 2.0.0
 *
 * @see WP_Object_Cache::add()
 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
 *
 * @param int|string $key The cache key to use for retrieval later.
 * @param mixed $data The data to add to the cache.
 * @param string $group Optional. The group to add the cache to. Enables the same key
 *                           to be used across groups. Default empty.
 * @param int $expire Optional. When the cache data should expire, in seconds.
 *                           Default 0 (no expiration).
 *
 * @return bool False if cache key and group already exist, true on success.
 */
function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;

	return $wp_object_cache->add( $key, $data, $group, (int) $expire );
}


/**
 * Increment numeric cache item's value
 *
 * @since 3.3.0
 *
 * @see WP_Object_Cache::incr()
 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
 *
 * @param int|string $key The key for the cache contents that should be incremented.
 * @param int $offset Optional. The amount by which to increment the item's value. Default 1.
 * @param string $group Optional. The group the key is in. Default empty.
 *
 * @return false|int False on failure, the item's new value on success.
 */
function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;

	return $wp_object_cache->incr( $key, $offset, $group );
}


/**
 * Decrements numeric cache item's value.
 *
 * @since 3.3.0
 *
 * @see WP_Object_Cache::decr()
 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
 *
 * @param int|string $key The cache key to decrement.
 * @param int $offset Optional. The amount by which to decrement the item's value. Default 1.
 * @param string $group Optional. The group the key is in. Default empty.
 *
 * @return false|int False on failure, the item's new value on success.
 */
function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;

	return $wp_object_cache->decr( $key, $offset, $group );
}


function wp_cache_close() {
	global $wp_object_cache;
	//$wp_object_cache->close();

	return true;
}


/**
 * Removes the cache contents matching key and group.
 *
 * @since 2.0.0
 *
 * @see WP_Object_Cache::delete()
 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
 *
 * @param int|string $key What the contents in the cache are called.
 * @param string $group Optional. Where the cache contents are grouped. Default empty.
 *
 * @return bool True on successful removal, false on failure.
 */
function wp_cache_delete( $key, $group = '' ) {
	global $wp_object_cache;

	return $wp_object_cache->_delete( $key, $group );
}


/**
 * Removes all cache items.
 *
 * @since 2.0.0
 *
 * @see WP_Object_Cache::flush()
 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
 *
 * @return bool False on failure, true on success
 */
function wp_cache_flush() {
	global $wp_object_cache;

	return $wp_object_cache->flush();
}


/**
 * Retrieves the cache contents from the cache by key and group.
 *
 * @since 2.0.0
 *
 * @see WP_Object_Cache::get()
 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
 *
 * @param int|string $key The key under which the cache contents are stored.
 * @param string $group Optional. Where the cache contents are grouped. Default empty.
 * @param bool $force Optional. Whether to force an update of the local cache from the persistent
 *                            cache. Default false.
 * @param bool $found Optional. Whether the key was found in the cache. Disambiguates a return of false,
 *                            a storable value. Passed by reference. Default null.
 *
 * @return bool|mixed False on failure to retrieve contents or the cache
 *                      contents on success
 */
function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	global $wp_object_cache;

	return $wp_object_cache->get( $key, $group, $force, $found );
}


function wp_cache_init() {
	global $wp_object_cache;

	$wp_object_cache = new WP_Object_Cache_Shm();

	$wrapperFile = dirname( __FILE__ ) . '/object-cache-stats-wrapper.php';
	if ( is_file( $wrapperFile ) ) {
		include_once $wrapperFile;
		$GLOBALS['wp_object_cache'] = new WP_Object_Cache_Stats_Wrapper( $GLOBALS['wp_object_cache'] );
	}
}

/**
 * Replaces the contents of the cache with new data.
 *
 * @since 2.0.0
 *
 * @see WP_Object_Cache::replace()
 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
 *
 * @param int|string $key The key for the cache data that should be replaced.
 * @param mixed $data The new data to store in the cache.
 * @param string $group Optional. The group for the cache data that should be replaced.
 *                           Default empty.
 * @param int $expire Optional. When to expire the cache contents, in seconds.
 *                           Default 0 (no expiration).
 *
 * @return bool False if original value does not exist, true if contents were replaced
 */
function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;

	return $wp_object_cache->replace( $key, $data, $group, (int) $expire );
}


/**
 * Saves the data to the cache.
 *
 * Differs from wp_cache_add() and wp_cache_replace() in that it will always write data.
 *
 * @since 2.0.0
 *
 * @see WP_Object_Cache::set()
 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
 *
 * @param int|string $key The cache key to use for retrieval later.
 * @param mixed $data The contents to store in the cache.
 * @param string $group Optional. Where to group the cache contents. Enables the same key
 *                           to be used across groups. Default empty.
 * @param int $expire Optional. When to expire the cache contents, in seconds.
 *                           Default 0 (no expiration).
 *
 * @return bool False on failure, true on success
 */
function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;

	return $wp_object_cache->set( $key, $data, $group, (int) $expire );
}

/**
 * Switches the internal blog ID.
 *
 * This changes the blog id used to create keys in blog specific groups.
 *
 * @since 3.5.0
 *
 * @see WP_Object_Cache::switch_to_blog()
 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
 *
 * @param int $blog_id Site ID.
 */
function wp_cache_switch_to_blog( $blog_id ) {
	global $wp_object_cache;

	$wp_object_cache->switch_to_blog( $blog_id );
}


/**
 * Adds a group or set of groups to the list of global groups.
 *
 * @since 2.6.0
 *
 * @see WP_Object_Cache::add_global_groups()
 * @global WP_Object_Cache $wp_object_cache Object cache global instance.
 *
 * @param string|array $groups A group or an array of groups to add.
 */
function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;

	$wp_object_cache->add_global_groups( $groups );
}


/**
 * Adds a group or set of groups to the list of non-persistent groups.
 *
 * @since 2.6.0
 *
 * @param string|array $groups A group or an array of groups to add.
 */
function wp_cache_add_non_persistent_groups( $groups ) {
	global $wp_object_cache;

	$wp_object_cache->add_non_persistent_groups( $groups );
}