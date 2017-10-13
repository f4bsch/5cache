<?php
/*
Plugin Name: 5cache
Description: An ultra low latency cache with two layers using System V shared memory
Version: 0.1.0
Plugin URI: http://calltr.ee/5cache
Author: Fabian Schlieper

Copy this file to wp-content/object-cache.php
*/

/*
carrier plugin
usually persistent cache groups: [counts,plugins,themes]
)

// TODO
* featured image set was not working
 * allow wp_cache_add to overwrite!
 * store expiryTable with semaphore!
 * NEED to remap false and NULL, because shm_read can return false/NULL!!!
 *
 *
 * catch:
 * cache/site-transient/browser_a9db4d03969fdd98d377b682b063efe6	 355.14 ms	wpc0re/wp_dashboard_setup	     1
 *
 *
 * WARNING: [pool www] child 16589 exited on signal 11 (SIGSEGV - core dumped) after 6.754807 seconds from start
[05-Oct-2017 02:22:45] NOTICE: [pool www] child 16606 started


    for debugging
        `ipcs` to view current memory
        `ipcrm -m {shmid}` to remove
        on some systems use `ipcclean` to clean up unused memory if you
        don't want to do it by hand
*/

defined( 'FIVECACHE_DRY_RUN' ) || define( 'FIVECACHE_DRY_RUN', false );

class WP_Object_Cache_Shm {
	const MAX_CACHE_SIZE = 1024 * 1024 * 4;
	const EXPIRE_DELAY = 0;

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
		//$this->segvDetectorEnter();

		//self::dbg( __FUNCTION__ . ":" . __LINE__ . "<br>\n" );
		$this->clock = ( isset( $_SERVER['REQUEST_TIME'] ) ? $_SERVER['REQUEST_TIME'] : time() );

		if ( isset( $_GET['5cache-prime'] ) ) {
			// Background Primer
			$this->handlePrime();
		} else {
			// Foreground Prime Barrier (remove slow actions)
			if ( function_exists( 'add_action' ) ) {
				add_action( 'plugins_loaded', function () {
					if ( is_admin() ) {
						remove_action( 'load-plugins.php', 'wp_update_plugins' );
						remove_action( 'load-update-core.php', 'wp_update_plugins' );
						remove_action( 'load-update-core.php', 'wp_update_themes' );
						//remove_action( 'admin_init', '_maybe_update_plugins' );
						//remove_action( 'admin_init', '_maybe_update_themes' );
						//remove_action( 'admin_init', '_maybe_update_core' );
					}
					remove_action( 'init', 'wp_cron' ); // TODO if removed -> start primer
				} );

				// suppress ANY shutdown actions (JetPack does a sync)
				add_action( 'wp_loaded', function () {
					global $wp_filter;
					if ( isset( $wp_filter['shutdown'] ) && count( $wp_filter['shutdown']->callbacks ) > 0 ) {
						$wp_filter['shutdown']->callbacks = [ ];
						// TODO start primer
					}
				}, 1e9 );
			}
		}

		register_shutdown_function( function () {
			if ( ! $this->doingPrime ) {
				$this->maybeSpawnPrimers();
			}


			register_shutdown_function( function () {
				register_shutdown_function( array( $this, '_veryLateShutdown' ) );  // close very, very late
			} );
		} );

		//self::dbg( __FUNCTION__ . ":" . __LINE__ . "<br>\n" );
	}

	public function _veryLateShutdown() {
		$this->close();
	}

	private function shmAttach() {
		$this->shm = shm_attach( $this->shmKey(), self::MAX_CACHE_SIZE );
		if ( ! is_resource( $this->shm ) ) {
			throw new \RuntimeException( 'shm_attach failed' );
		}
	}

	private function open() {
		//self::dbg( __FUNCTION__ . ":" . __LINE__ );
		$this->volatileCache = array();
		$this->missingKeys   = array();
		$this->writeBackKeys = array();

		$this->shmAttach();

		$this->readExpiryTable();
	}

	// need to remap FALSE and NULL (shm_get_var can return both on corruption)
	const FALSE = "__5cache_FALSE";
	const NULL = "__5cache_NULL";

	static function packFalse( $val ) {
		if ( $val === false ) {
			return self::FALSE;
		}
		if ( $val === null ) {
			return self::NULL;
		}

		return $val;
	}

	static function unpackFalse( $val ) {
		if ( is_string( $val ) ) {
			if ( $val === self::FALSE ) {
				return false;
			}
			if ( $val === self::NULL ) {
				return null;
			}
		}

		return $val;
	}

	private $segvDetectorEntered = false;

	const OOM = "not enough shared memory left";

	public function segvDetectorEnter() {
		$this->dbg( "segvDetectorEnter" );
		if ( $this->segvDetectorEntered ) {
			return true;
		}

		if(!$this->sem()) {
			FiveCacheLogger::logMsg("error: segvDetectorEnter(): sem() failed!");
			return false;
		}

		$gKey = "_5_seg_flag";
		$ki   = self::intHash( $gKey );

		if ( shm_has_var( $this->shm, $ki ) ) {
			$tFlag  = $this->safeShmRead( $ki, $gKey );
			$tSince = round( $_SERVER['REQUEST_TIME_FLOAT'] - $tFlag, 3 );
			FiveCacheLogger::logMsg( "error: SEGV or unexpected shutdown $tSince s ago (req @$tFlag)!" );
			$this->shmClear();
		}


		if ( ! @shm_put_var( $this->shm, $ki, $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			$oom = self::checkLastError( self::OOM  );

			FiveCacheLogger::logMsg( "error: setting $gKey (OOM=$oom), complete flush..." );
			$this->shmClear();
			if ( ! shm_put_var( $this->shm, $ki, $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
				FiveCacheLogger::logMsg( "critical error: setting $gKey even failed after flush!" );
			} else {
				$this->segvDetectorEntered = true;
			}
		} else {
			$this->segvDetectorEntered = true;
		}

		return $this->segvDetectorEntered;
	}

	public function segvDetectorLeave() {
		$this->dbg( "segvDetectorLeave" );
		if ( ! $this->segvDetectorEntered ) {
			FiveCacheLogger::logMsg( "warning: called segvDetectorLeave() but did not enter before!" );

			return;
		}

		$gKey = "_5_seg_flag";
		$ki   = self::intHash( $gKey );

		if ( ! shm_remove_var( $this->shm, $ki ) ) {
			FiveCacheLogger::logMsg( "error: removing $gKey failed!" );
		} else {
			$this->segvDetectorEntered = false;
		}
	}

	private $semId = null;

	private function shmKey() {
		return ftok( __FILE__, chr( 32 + ( $this->blog_id % 94 ) ) );
	}

	private function sem() {
		static $acquired = false;
		if ( $acquired ) {
			return true;
		}

		self::dbg( __FUNCTION__ . ":" . __LINE__ . ' sem_acquire ...' );

		$this->semId = sem_get( $this->shmKey() );
		$i           = 0;
		$t           = microtime( true ); // TODO debug
		/** @noinspection PhpMethodParametersCountMismatchInspection */
		while ( ( $needToWait = ! sem_acquire( $this->semId, true ) ) ) { // 2nd parameter $nowait added PHP5.6.1
			if ( $i == 0 ) {
				FiveCacheLogger::logMsg( "waitSem  ... " );
			}
			if ( ( ++ $i ) > 1000 ) {
				FiveCacheLogger::logMsg( "error: waitSem timed out after 1 second! " );

				return false;
			}
			usleep( 1000 );
		}

		$acquired = true;

		if ( $i > 0 ) {
			FiveCacheLogger::logMsg( "waitSem OK after $i!" );
		}

		$t = round( ( microtime( true ) - $t ) * 1e6 );

		self::dbg( __FUNCTION__ . ":" . __LINE__ . " sem_acquired {$this->shmKey()} after i=$i, t=$t us ..." );

		return $acquired;
	}

	private static function dbg( $msg ) {
		//echo "$msg\n";
		FiveCacheLogger::logMsg( $msg );
	}

	public function close() {
		self::dbg( "close(), writing " . count( $this->writeBackKeys ) . " values, had $this->cache_hits hits and $this->cache_misses misses ... " );
		//return false;

		if(!$this->shm )
			return false;

		$t = microtime( true ); // TODO debug

		// because PHP shm is buggy, this function is quite dangerous
		// it can cause the PHP process to SEGV !
		// so we want to send all content to the peer, just in case
		ignore_user_abort( true );
		$levels = ob_get_level();
		for ( $i = 0; $i < $levels; $i ++ ) {
			ob_end_flush();
		}
		flush();
		session_write_close();

		// in case of write failed, keep track of keys written after the flush
		$writtenAfterFlush = array();
		$flushed           = false;

		// lazy write back before close
		$writeOk = true;
		foreach ( $this->writeBackKeys as $gKey => $expire ) {
			//self::dbg( "close(), writing $gKey, expire=$expire ... " );

			$ki   = self::intHash( $gKey );
			$data = self::packFalse( $this->volatileCache[ $gKey ] );

			// shm_put_var can cause SEGV and an instant PHP process kill
			if ( ! $this->sem() ) {
				// without a safe sem access we should not write!
				FiveCacheLogger::logMsg( "error: sem() failed while writing $gKey, will drop all volatile data!" );
				shm_detach( $this->shm );

				return false;
			}

			$this->segvDetectorEnter();

			/** @see https://github.com/php/php-src/blob/master/ext/sysvshm/sysvshm.c#L246 */
			if ( ! @shm_put_var( $this->shm, $ki, $data ) ) {
				$oom = self::checkLastError( "not enough shared memory left" );
				self::dbg( __FUNCTION__ . ":" . __LINE__ . "shm_put_var fail! (oom=$oom)" );
				FiveCacheLogger::logMsg( "error: put $gKey failed (OOM=$oom), complete flush..." );
				$this->shmClear(); // delete all other keys, sorry :/
				$writtenAfterFlush = array();
				$flushed           = true;

				if ( ! shm_put_var( $this->shm, $ki, $data ) ) {
					$writeOk = false;
					continue; // don't add to $writtenAfterFlush
				}
			}

			if ( $flushed ) {
				$writtenAfterFlush[ $ki ] = 1;
			}
		}
		self::dbg( __FUNCTION__ . ":" . __LINE__ );

		// if flushed during above loop, update expiry table
		// only keep keys written after the flush
		if ( $flushed ) {
			self::dbg( __FUNCTION__ . ":" . __LINE__ );
			$this->expiryTable        = array_intersect_key( $this->expiryTable, $writtenAfterFlush );
			$this->expiryTableUpdated = true;
		}

		if ( $this->expiryTableUpdated ) {
			$this->segvDetectorEnter();
			if ( ! $this->sem() ) {
				// this should never happen, because any change to the expiry table requires a write
				// and we already sem() above // TODO remove
				FiveCacheLogger::logMsg( "error: sem() failed while writing expiry table, will drop all volatile data!" );
				shm_detach( $this->shm );

				return false;
			}
			$writeOk &= $this->writeExpiryTable();
		}


		if ( ! $writeOk ) {
			FiveCacheLogger::logMsg( "close(): error writing shm!" );
		}

		$this->writeBackKeys = array();

		$this->segvDetectorLeave();
		$ok        = shm_detach( $this->shm );
		$this->shm = null;

		if ( $this->semId ) {
			self::dbg( "close(): sem release" );
			// release the semaphore by deleting any reference
			$this->semId = null;
		}


		$t = round( ( microtime( true ) - $t ) * 1e3 );
		self::dbg( "close function took $t ms" );

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
				/** @noinspection PhpIncludeInspection */
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
					/** @noinspection PhpIncludeInspection */
					! function_exists( 'get_plugins' ) && require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
					get_plugins();
				}
			),
			'site-transient:update_plugins' => array(
				'admin_screens' => [ '*' => 300, 'update-core' => 1 ],
				'func'          => function () {
					wp_update_plugins();

					// ... prime poptags
					/** @noinspection PhpIncludeInspection */
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
		if ( $this->shm === null ) {
			FiveCacheLogger::logMsg( "error: set() after close $key group=$group, ignoring!" );

			return false;
		}

		self::dbg( "set(): $key, group=$group, expire=$expire, len=" . strlen( serialize( $data ) ) );
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
			$this->expiryTable[ $ki ] = $this->clock + $expire + self::EXPIRE_DELAY;
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
		//$found = false;
		//return false;

		//self::dbg( __FUNCTION__ . ":" . __LINE__  );
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


		self::dbg("get(): shm_has_var"  );


		$found = shm_has_var( $this->shm, $ki );
		if ( ! $found ) {
			$this->missingKeys[ $gKey ] = 1;
			++ $this->cache_misses;

			return false;
		}

		self::dbg("get(): safeShmRead"  );

		$value = $this->safeShmRead( $ki, $gKey );

		if ( $value === false ) {
			// error during read
			$this->missingKeys[ $gKey ] = 1;
			unset( $this->volatileCache[ $gKey ], $this->expiryTable[ $ki ] );
			++ $this->cache_misses;
			$found = false;

			return false;
		}

		++ $this->cache_hits;

		//$this->volatileCache[ $gKey ] = $value;

		return ( $this->volatileCache[ $gKey ] = self::unpackFalse( $value ) );
	}

	private static function checkLastError( $forMessage ) {
		$err = error_get_last();

		return ( $err && stripos( $err['message'], $forMessage ) !== false );
	}

	private function safeShmRead( $ki, $gKey ) {
		//self::dbg( __FUNCTION__ . ":" . __LINE__ );

		$value = @shm_get_var( $this->shm, $ki );
		// TODO: sometimes returns NULL, needs investigation
		if ( $value === false || is_null( $value ) ) {
			/** @see https://github.com/php/php-src/blob/master/ext/sysvshm/sysvshm.c#L313 */
			$shmCorrupt = self::checkLastError( "variable data in shared memory is corrupted" );
			shm_remove_var( $this->shm, $ki );
			// Warning: shm_get_var(): variable data in shared memory is corrupted ...
			if ( $shmCorrupt ) {
				FiveCacheLogger::logMsg( "error: memory corruption of $gKey!" );
				$this->flush();
			}

			$vs = ( $value === false ) ? "FALSE" : "NULL";
			FiveCacheLogger::logMsg( "error: $gKey reads $vs!" );

			return false;
		}

		return $value;
	}

	private function readExpiryTable() {
		self::dbg( __FUNCTION__ . ":" . __LINE__ );
		$ki = self::intHash( '_expiry' );
		if ( ! shm_has_var( $this->shm, $ki ) ) {
			return;
		}
		$res = $this->safeShmRead( $ki, '_expiry' );
		if ( ! is_array( $res ) ) {
			FiveCacheLogger::logMsg( "error reading expiry table, complete flush..." );
			$this->flush();

			return;
		}
		$this->expiryTable        = $res;
		$this->expiryTableUpdated = false;
	}

	private function writeExpiryTable() {
		self::dbg( __FUNCTION__ . ":" . __LINE__ );
		$ki = self::intHash( '_expiry' );
		if ( ! @shm_put_var( $this->shm, $ki, $this->expiryTable ) ) {
			$oom = self::checkLastError( "not enough shared memory left" );
			FiveCacheLogger::logMsg( "error writing expiry table (OOM=$oom), complete flush..." );
			$this->shmClear(); // delete all data if we can't write expiry table

			return false;
		}
		$this->expiryTableUpdated = false;

		return true;
	}


	public function _delete(
		$key, $group = 'default', /** @noinspection PhpUnusedParameterInspection */
		$deprecated = false
	) {
		//return true;
		$gKey = $this->gKey( $key, $group );

		self::dbg( "_delete(): $gKey" );

		$ok = isset( $this->volatileCache[ $gKey ] );

		unset( $this->volatileCache[ $gKey ], $this->writeBackKeys[ $gKey ] );
		$this->missingKeys[ $gKey ] = 1;

		$ki = self::intHash( $gKey );
		if ( ! shm_has_var( $this->shm, $ki ) ) {
			FiveCacheLogger::logMsg( "delete($gKey), but not found in shm (shm_has_var), found in volatile='$ok'" );

			return $ok;
		}

		unset( $this->expiryTable[ $ki ] );
		$this->expiryTableUpdated = true;

		self::dbg( "_delete(): $gKey shm_remove_var ..." );
		if ( ! shm_remove_var( $this->shm, $ki ) ) {
			FiveCacheLogger::logMsg( "error: delete($gKey), supposed to be in shm, but shm_remove_var returned false, volatile='$ok'" );

			return $ok;
		}

		return true;
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
			if ( ! FIVECACHE_DRY_RUN ) {
				self::dbg( "warning: add() $group:$key exists (expire=$expire)!" );
			}

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
		self::dbg( __FUNCTION__ . ":" . __LINE__ );
		// log sth before the actual flush to retrieve the logDir from cache!
		FiveCacheLogger::logMsg( "flushing ... ", true );

		$this->volatileCache      = array();
		$this->writeBackKeys      = array();
		$this->expiryTable        = array();
		$this->expiryTableUpdated = false;

		$this->shmClear();

		FiveCacheLogger::logMsg( "cache flushed! " . json_encode( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) ) );

		return true;
	}

	private function shmClear() {
		$sh = $this->shm;
		shm_remove( $this->shm );
		shm_detach( $this->shm );
		$this->shmAttach();

		FiveCacheLogger::logMsg( "shared memory $sh cleared!" );
		if ( FIVECACHE_DRY_RUN ) {
			FiveCacheLogger::logMsg( "Note that 5cache currently dry runs (FIVECACHE_DRY_RUN set)!" );
		}
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


class FiveCacheLogger {
	private static function getSecret( $salt ) {
		return hash( 'sha256', $salt . '|' . ( defined( 'NONCE_SALT' ) ? NONCE_SALT : filemtime( __FILE__ ) ) . dirname( __FILE__ ) );
	}

	private static function getLogDir() {
		$dir = dirname( __FILE__ );
		if ( is_writable( $dir ) ) {
			return $dir;
		}

		if ( defined( UPLOADBLOGSDIR ) && is_dir( UPLOADBLOGSDIR ) && is_writable( UPLOADBLOGSDIR ) ) {
			return UPLOADBLOGSDIR;
		}

		$uploadDir = $dir . '/uploads';
		if ( is_dir( $uploadDir ) && is_writable( $uploadDir ) ) {
			return $uploadDir;
		}

		// fallback
		return $dir;
	}

	public static function logMsg( $str, $noLBR = false ) {
		static $logFile = null;
		static $wasNoLBR = false;
		if ( $logFile === null ) {
			$suffix  = self::getSecret( 'log' );
			$dir     = self::getLogDir();
			$logFile = $dir . "/._5cache-$suffix.log";
			if ( ! ( file_exists( $logFile ) ? is_writable( $logFile ) : is_writable( $dir ) ) ) {
				$logFile = false;
			}
		}

		if ( ! is_string( $str ) ) {
			$str = json_encode( $str );
		}

		$msg = $wasNoLBR ? $str : ( strtok( date( 'c' ), '+' )
		                            . strtok( substr( microtime( false ), 1 ), ' ' )
		                            . ' #' . str_pad( $_SERVER['REQUEST_URI'], 30 )
		                            . "@" . str_pad( @$_SERVER['REQUEST_TIME_FLOAT'], 16 ) . ": $str" );
		if ( ! $noLBR ) {
			$msg .= "\n";
		}
		$wasNoLBR = $noLBR;

		$logFile && @error_log( $msg, 3, $logFile );
		@error_log( $msg ); // pass to default PHP log file too
	}
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;

	return $wp_object_cache->add( $key, $data, $group, (int) $expire );
}

function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;

	return $wp_object_cache->incr( $key, $offset, $group );
}

function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	global $wp_object_cache;

	return $wp_object_cache->decr( $key, $offset, $group );
}

function wp_cache_close() {
	// caches handles closing

	return true;
}

function wp_cache_delete( $key, $group = '' ) {
	global $wp_object_cache;

	return $wp_object_cache->_delete( $key, $group );
}

function wp_cache_flush() {
	global $wp_object_cache;

	return $wp_object_cache->flush();
}

function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	global $wp_object_cache;

	if ( FIVECACHE_DRY_RUN ) {
		$wp_object_cache->get( $key, $group, $force, $found );

		if ( $group === 'site-transient' ) {
			wp_using_ext_object_cache( false );
			$value = get_site_transient( $key );
			wp_using_ext_object_cache( true );

			return $value;
		}

		if ( $group === 'transient' ) {
			wp_using_ext_object_cache( false );
			$value = get_transient( $key );
			wp_using_ext_object_cache( true );

			return $value;
		}

		$found = false;

		return false;
	}

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

function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;

	return $wp_object_cache->replace( $key, $data, $group, (int) $expire );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;

	if ( FIVECACHE_DRY_RUN ) {
		$result = $wp_object_cache->set( $key, $data, $group, (int) $expire );

		if ( $group === 'site-transient' ) {
			wp_using_ext_object_cache( false );
			$result = set_site_transient( $key, $data, $expire );
			wp_using_ext_object_cache( true );

			return $result;
		}

		if ( $group === 'transient' ) {
			wp_using_ext_object_cache( false );
			$result = set_transient( $key, $data, $expire );
			wp_using_ext_object_cache( true );

			return $result;
		}
		return $result;
	}
	return $wp_object_cache->set( $key, $data, $group, (int) $expire );
}

function wp_cache_switch_to_blog( $blog_id ) {
	global $wp_object_cache;

	$wp_object_cache->switch_to_blog( $blog_id );
}

function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
	global $wp_object_cache;
	$wp_object_cache->add_non_persistent_groups( $groups );
}