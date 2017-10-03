<?php
/*
Plugin Name: 5cache
Description: An ultra low latency cache with two layers using System V shared memory
Version: 0.1.0
Plugin URI: http://calltr.ee/
Author: Fabian Schlieper

Copy this file to wp-content/object-cache.php
*/

/*
Todo: expiry
non_persistent_groups
make plugins conditionally persistent
Benchmark WP_Object_Cache_Shm with write back disabled (compared to WP builtin)
test 2D cache array for volatileCache
carrier plugin

global groups are currently ignored!
TODO triggers to flush non persistent groups (such as `plugins`)

these should be made persistent by lazy update (in some background task)
Array
(
    [counts] => 1
    [plugins] => 1
    [themes] => 1
)
*/

class WP_Object_Cache_Shm {
	const MAX_CACHE_SIZE = 1024 * 1024 * 4;

	private $multisite;
	private $blog_id;

	// stats are public
	public $cache_misses = 0;
	public $cache_hits = 0;

	public $global_groups = array(); // TODO
	public $non_persistent_groups = array();

	private $shm = null;

	private $volatileCache = array();
	private $writeBackKeys = array();
	private $missingKeys = array();
	private $expiryTable = array();

	private $expiryTableUpdated = false;

	private $clock = 0;

	private $doingPrime = false;

	public function __construct() {
		$this->multisite = function_exists( 'is_multisite' ) && is_multisite();
		$this->blog_id   = $this->multisite ? ( 1 + get_current_blog_id() ) : 0;

		$this->open();

		$this->clock = ( isset( $_SERVER['REQUEST_TIME'] ) ? $_SERVER['REQUEST_TIME'] : time() );

		if ( isset( $_GET['5cache-prime'] ) ) {
			$this->handlePrime();
		}

		// remove slow actions
		add_action( 'plugins_loaded', function () {
			is_admin()
			&& remove_action( 'load-plugins.php', 'wp_update_plugins' )
			&& remove_action( 'load-update-core.php', 'wp_update_plugins' )
			&& remove_action( 'load-update-core.php', 'wp_update_themes' );
		} );

		register_shutdown_function( function () {
			if ( ! $this->doingPrime ) {
				$this->maybeSpawnPrimers();
			}
			register_shutdown_function( array( $this, 'close' ) ); // close very late
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

		$this->readExpiryTable();
	}

	public function close() {

		// lazy write back before close
		$writeOk = true;
		foreach ( $this->writeBackKeys as $gKey => $expire ) {
			$ki = self::intHash( $gKey );
			if ( ! @shm_put_var( $this->shm, $ki, $this->volatileCache[ $gKey ] ) ) {
				$data = $this->volatileCache[ $gKey ];
				// assume fail is OOM, delete all other keys, sorry :/
				$this->flush();
				$writeOk &= shm_put_var( $this->shm, $ki, $data );
			}
		}

		if ( $this->expiryTableUpdated ) {
			$writeOk &= $this->writeExpiryTable();
		}

		if ( ! $writeOk ) {
			error_log( '5cache: error during lazy write!' );
		}

		$this->writeBackKeys = array();

		$ok        = shm_detach( $this->shm );
		$this->shm = null;

		return $ok;
	}

	private function maybeSpawnPrimers() {
		if ( function_exists( 'get_current_screen' ) && ( $screen = get_current_screen() ) ) {
			$lastPrimeTimes = $this->get( 'lastPrimeTimes', '5cache' );
			$spawned        = false;
			foreach ( self::bgPrimers() as $gKey => $primer ) {
				$interval = INF;
				$scrs     = $primer['admin_screens'];

				// pick the smallest matching inverval
				foreach ( [ '*', $screen->parent_base, $screen->id ] as $s ) {
					if ( isset( $scrs[ $s ] ) && ( $scrs[ $s ] < $interval ) ) {
						$interval = $scrs[ $s ];
					}
				}

				if ( $interval === INF || ( isset( $lastPrimeTimes[ $gKey ] ) && ( $this->clock - $lastPrimeTimes[ $gKey ] ) < $interval ) ) {
					continue;
				}

				self::spawnPrime( $gKey );

				$lastPrimeTimes[ $gKey ] = $this->clock;
				$spawned                 = true;
			}

			if ( $spawned ) {
				$this->set( 'lastPrimeTimes', $lastPrimeTimes, '5cache' );
			}
		}
	}

	private static function spawnPrime( $gKey ) {
		$pu = ( add_query_arg( array( '5cache-prime' => $gKey, '5n' => wp_create_nonce( "5cache-prime=$gKey" ) ) ) );
		echo "<script>(function(){var x=new XMLHttpRequest();x.open('GET','$pu');x.send();})();</script>";
	}

	private function handlePrime() {
		ignore_user_abort( true );

		add_action( 'plugins_loaded', function () {
			if ( ! function_exists( 'wp_verify_nonce' ) ) {
				require_once( ABSPATH . WPINC . '/pluggable.php' );
			}
			$this->doingPrime = isset( $_GET['5n'] ) && wp_verify_nonce( $_GET['5n'], "5cache-prime=" . $_GET['5cache-prime'] );
			if ( ! $this->doingPrime ) {
				wp_die( 'invalid prime ' . $_GET['5n'] . ' ' . "5cache-prime=" . $_GET['5cache-prime'] );
				exit;
			}
			register_shutdown_function( function () {
				$this->doPrime( $_GET['5cache-prime'] );
			} );
		} );
	}


	/**
	 * Primes a specific cache value
	 *
	 * @param $gKey
	 *
	 */
	private function doPrime( $gKey ) {
		$this->housekeeping();

		$primers = self::bgPrimers();

		if ( ! isset( $primers[ $gKey ] ) ) {
			return;
		}

		list( $group, $key ) = explode( ':', $gKey );
		wp_cache_delete( $key, $group );
		$t = microtime( true );
		call_user_func( $primers[ $gKey ]['func'] );

		$t = round( ( microtime( true ) - $t ) * 1000 );

		echo "<!-- 5cache primed $gKey in $t ms -->";
	}

	/**
	 * These are the deferred cache primers.
	 * '{group}:{key}' => ['admin_screens' => [ '{screen}' => {update_interval}, 'func' => {primer} ]
	 *
	 * 'admin_screens' defines on which admin screens the value should be refreshed with given interval
	 * {primer} must call wp_cache_set({key}, ..., {group})
	 *
	 * @return array
	 */
	private static function bgPrimers() {
		return array(
			'plugins:plugins'               => array(
				'admin_screens' => [ 'plugins' => 1, 'update-core' => 1 ],
				'func'          => function () {
					! function_exists( 'get_plugins' ) && require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
					get_plugins();
				}
			),
			'site-transient:update_plugins' => array(
				'admin_screens' => [ '*' => 300, 'update-core' => 1 ],
				'func'          => function () {
					wp_update_plugins();

					// ... prime poptags
					! function_exists( 'install_popular_tags' ) && require_once( ABSPATH . 'wp-admin/includes/plugin-install.php' );
					install_popular_tags();
				}
			),

			// for themes, there is wp_get_themes(), but it doesn't use the cache

			'site-transient:update_themes' => array(
				'admin_screens' => [ '*' => 300, 'update-core' => 1 ],
				'func'          => function () {
					wp_update_themes();
				}
			)
		);
	}

	/**
	 * Delete expired data from shared memory. Does not touch volatile cache layer
	 */
	private function housekeeping() {
		foreach ( $this->expiryTable as $ki => $expireAt ) {
			if ( $expireAt > $this->clock && shm_has_var( $this->shm, $ki ) ) {
				shm_remove_var( $this->shm, $ki );
				unset( $this->expiryTable [ $ki ] );
				$this->expiryTableUpdated = true;
			}
		}
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
		$this->writeBackKeys[ $gKey ] = $expire;
		unset( $this->missingKeys[ $gKey ] );


		// if no expiry is set is in a non persistent group, set expire to a fixed value (5min)
		if ( $expire <= 0 ) {
			if ( isset( $this->non_persistent_groups[ $group ] ) ) {
				$expire = 300;
			}
		}

		// update expiryTable
		$ki = self::intHash( $gKey );
		if ( $expire > 0 ) {
			$this->expiryTable[ $ki ] = $this->clock + $expire + 1;
			$this->expiryTableUpdated = true;
		} elseif ( isset( $this->expiryTable[ $ki ] ) ) {
			unset( $this->expiryTable[ $ki ] );
			$this->expiryTableUpdated = true;
		}

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

			if ( is_object( $this->volatileCache[ $gKey ] ) ) {
				return clone $this->volatileCache[ $gKey ];
			} else {
				return $this->volatileCache[ $gKey ];
			}
		}

		if ( isset( $this->missingKeys[ $gKey ] ) ) {
			$found = false;
			++ $this->cache_misses;

			return false;
		}

		$ki = self::intHash( $gKey );

		// check expiry
		if ( isset( $this->expiryTable[ $ki ] ) && $this->expiryTable[ $ki ] < $this->clock ) {
			$this->missingKeys[ $gKey ] = true;
			unset( $this->volatileCache[ $gKey ], $this->expiryTable[ $ki ] );
			$found = false;
			++ $this->cache_misses;

			return false;
		}


		$found = shm_has_var( $this->shm, $ki );
		if ( ! $found ) {
			$this->missingKeys[ $gKey ] = 1;
			++ $this->cache_misses;

			return false;
		}

		$value = shm_get_var( $this->shm, $ki );

		// TODO: shm_get_var sometimes returns NULL
		// should log this, needs investigation
		if ( is_null( $value ) ) {
			shm_remove_var( $this->shm, $ki );
			$this->missingKeys[ $gKey ] = 1;
			++ $this->cache_misses;

			return false;
		}

		$this->volatileCache[ $gKey ] = $value;
		++ $this->cache_hits;

		return $value;
	}

	private function readExpiryTable() {
		$ki = self::intHash( '_expiry' );
		if ( ! shm_has_var( $this->shm, $ki ) ) {
			return;
		}
		$res                      = shm_get_var( $this->shm, $ki );
		$this->expiryTable        = is_array( $res ) ? $res : array();
		$this->expiryTableUpdated = false;
	}

	private function writeExpiryTable() {
		$ki = self::intHash( '_expiry' );
		if ( ! @shm_put_var( $this->shm, $ki, $this->expiryTable ) ) {
			$this->flush(); // delete all data if we can't write expiry table

			return false;
		}
		$this->expiryTableUpdated = false;

		return true;
	}


	public function _delete(
		$key, $group = 'default', /** @noinspection PhpUnusedParameterInspection */
		$deprecated = false
	) {// echo "<!-- 5cache del $key -->";
		$gKey = $this->gKey( $key, $group );

		$ok = isset( $this->volatileCache[ $gKey ] );

		unset( $this->volatileCache[ $gKey ], $this->writeBackKeys[ $gKey ] );
		$this->missingKeys[ $gKey ] = 1;

		$ki = self::intHash( $key );
		if ( ! shm_has_var( $this->shm, $ki ) ) {

			return $ok;
		}

		unset( $this->expiryTable[ $ki ] );
		$this->expiryTableUpdated = true;

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

		$groups                      = array_fill_keys( $groups, true );
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
		$this->volatileCache      = array();
		$this->writeBackKeys      = array();
		$this->expiryTable        = array();
		$this->expiryTableUpdated = false;

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

		$ki = self::intHash( $gKey );
		if ( isset( $this->expiryTable[ $ki ] ) && $this->expiryTable[ $ki ] < $this->clock ) {
			$this->missingKeys[ $gKey ] = true;
			unset( $this->volatileCache[ $gKey ], $this->expiryTable[ $ki ] );

			return false;
		}

		if ( shm_has_var( $this->shm, $ki ) ) {
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
	// caches handles closing

	return true;
}


/**
 * Removes the cache contents matching key and group.
 *
 * @since 2.0.0
 *
 * @see WP_Object_Cache::delete()
 * @global WP_Object_Cache_Shm $wp_object_cache Object cache global instance.
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

/**
 * @global WP_Object_Cache_Shm $wp_object_cache
 */
global $wp_object_cache;

function wp_cache_init() {
	global $wp_object_cache;

	$wp_object_cache = new WP_Object_Cache_Shm();
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