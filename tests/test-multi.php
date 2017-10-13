<?php

$n = 2;

/** @var WP_Object_Cache_Shm[] $caches */
$caches = array();

$c1 = new WP_Object_Cache_Shm();
$c2 = new WP_Object_Cache_Shm();


for($i = 0; $i < $n; $i++) {
	$caches[ $i ] = new WP_Object_Cache_Shm();
}

$key = md5(rand());
$val = md5($key);


// test deferred add
$c1->add($key, $val); // true
$c2->add($key, $val); // true

// test delete
$c1->_delete($key);


// reopen (writeback)
$c1->close();
$c2->close();
$c1 = new WP_Object_Cache_Shm();
$c2 = new WP_Object_Cache_Shm();

$c1->add($key, $val);




foreach ($caches as $cache) {
	$cache->add($key,$val); // true
}

foreach ($caches as $cache) {
	$cache->writeBack($key,$val); // true
}

foreach ($caches as $cache) {
	$cache->add($key,$val); // false
}


$caches[0]->_delete($key,$val); // TRUE
foreach ($caches as $cache) {
	$caches[0]->_delete($key,$val); // FALSE
}


