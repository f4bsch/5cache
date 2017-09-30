<?php

/*
include_once dirname(__FILE__).'/objet-cache-stats-wrapper.php';
$GLOBALS['wp_object_cache'] = new WP_Object_Cache_Stats_Wrapper($GLOBALS['wp_object_cache']);

*/

class WP_Object_Cache_Stats_Wrapper {

    /**
     * @var WP_Object_Cache
     */
    private $cache;
    private $multisite;

    //public $cache_hits = 0;
    //public $cache_misses = 0;

    private $callHist = array();
	
	private $callTimeTotal = array();
	private $callTimeMean = array();
	private $callTimeMax = array();
	
	
	private $non_persistent_groups = array();
	private $global_groups = array();
	
	private $totalCacheSize = 0;
		
	public $cache_hits = 0;
	public $cache_misses = 0;
	
	
	private $cacheLength = array();	
	private $cacheMisses = array();
	private $cacheHits= array();
	private $cacheDeletes = array();
	

	private $useInternalCache = false;
	private $internalCache = array();

	private $debug = false;
	private $destructed = false;

    public function __get( $name ) {
        return $this->cache->$name;
    }

    public function __set( $name, $value ) {
        return $this->cache->$name = $value;
    }
    public function __isset( $name ) {
        return isset( $this->cache->$name );
    }
    public function __unset( $name ) {
        unset( $this->cache->$name );
    }


    // pass all calls to cache object
    public function __call($name, $arguments)
    {
        @$this->callHist[$name]++;
		$t = microtime(true);
        $res = call_user_func_array(array($this->cache, $name), $arguments);
		$t = microtime(true) - $t;
		@$this->callTimeTotal[$name] += $t;
		if($t > @$this->callTimeMax[$name]) $this->callTimeMax[$name] = $t;
		
        return $res;
    }
	
	public function get( $key, $group = 'default', $force = false, &$found = null ) 
	{
        if($this->useInternalCache && isset($this->internalCache["$key:$group"])) {
            return $this->internalCache["$key:$group"];
        }


		@$this->callHist['get']++;		
		$t = microtime(true);
		$res = $this->cache->get($key, $group, $force, $found);
		$t = microtime(true) - $t;
		@$this->callTimeTotal['get'] += $t;
		if($t > @$this->callTimeMax['get']) $this->callTimeMax['get'] = $t;
		
		
		
		if(!$found) {
			@$this->cacheMisses[$group][$key]++;
			$this->cache_misses++; 
		} else {
			@$this->cacheHits[$group][$key]++;
			$this->cache_hits++;

			if($this->useInternalCache)
                $this->internalCache["$key:$group"] = $res;
			
			if(!isset($this->cacheLength[$group][$key]) || $this->cacheLength[$group][$key] < 32)
				$this->cacheLength[$group][$key] = strlen(serialize($res));
		}
		
		return $res;
	}

    public function delete( $key, $group = 'default', $deprecated = false ) {
        @$this->callHist['delete']++;
        $t = microtime(true);
        $res = $this->cache->delete($key, $group, $deprecated);
        $t = microtime(true) - $t;
        @$this->callTimeTotal['delete'] += $t;
        if($t > @$this->callTimeMax['delete']) $this->callTimeMax['delete'] = $t;

        @$this->cacheDeletes[$group][$key]++;
    }
	public function add_global_groups($groups)
    {
        @$this->callHist['add_global_groups']++;

		 if (!is_array($groups))
            $groups = (array)$groups;
        $groups = array_fill_keys($groups, true);
        $this->global_groups = array_merge($this->global_groups, $groups);
		
		$this->cache->add_global_groups($groups);
    }

    public function add_nonpersistent_groups($groups)
    {
		@$this->callHist['add_nonpersistent_groups']++;
        if (!is_array($groups))
            $groups = (array)$groups;

        $groups = array_fill_keys($groups, true);
        $this->non_persistent_groups = array_merge($this->non_persistent_groups, $groups);
		$this->cache->add_non_persistent_groups($groups);
    }

    public function add_non_persistent_groups($groups) {
        @$this->callHist['add_non_persistent_groups']++;
        if (!is_array($groups))
            $groups = (array)$groups;

        $groups = array_fill_keys($groups, true);
        $this->non_persistent_groups = array_merge($this->non_persistent_groups, $groups);
        $this->cache->add_non_persistent_groups($groups);
    }
	

    public function __construct($cache) {
        $this->cache = $cache;
        $this->multisite =  function_exists('is_multisite') && is_multisite();
        register_shutdown_function( array( $this, '__destruct' ) );
		
		$this->debug = !empty($_GET['debug_oc']);
    }


    public function __destruct() {
		if($this->destructed)
			return;
		
		if(!$this->debug)
			return;
		
		foreach($this->callTimeTotal as $name => $t) {
			$this->callTimeMean[$name] = round($t * 1e6 / $this->callHist[$name], 4) . " us";
			$this->callTimeMax[$name]  = round($this->callTimeMax[$name] * 1e6, 4) . " us";
			$this->callTimeTotal[$name]  = round($this->callTimeTotal[$name] * 1e6, 4) . " us";
		}
		$totalLen = 0;
		array_walk_recursive($this->cacheLength, function($item, $key) use(&$totalLen) {
			$totalLen += $item;
		});
		
		$this->totalCacheSize = $totalLen;
		
		$cache = $this->cache;
		$this->cache = null;
		$this->internalCache = array();

        echo "<!-- ";
        print_r($this);
        echo "-->";
		$this->cache = $cache;
		
		$this->destructed = true;
		
        return true;
    }
}