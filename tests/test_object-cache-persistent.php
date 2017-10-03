<?php

require_once 'object-cache.php';

wp_cache_init();


echo "<pre>";

$i = wp_cache_get('i');
if($i === false) {
	if(!wp_cache_add('i', 0))
		die('add failed!');
	echo "first cache access, reload the page!\n";
	exit;
}

echo "cach acccess i=$i\n";
$i = wp_cache_incr('i');

$num = ftok(__FILE__, 'a') * round($i/2);
$testData = [
    'num' => $num,
    'str' => md5(__FILE__ . $num),
    'obj' => (object)array('key' => 'value' . $num),
    'str_long' => str_repeat(md5($num), 8000), //32*8000=256000byte
    'str_long2' => str_repeat(md5($num.$num), 9000),
    'arr' => [1, 2, 3, 'foo', 'bar' => 'val', $num]
];


if($i % 2) {
	echo "write...\n";
	foreach($testData as $key => $data) {
		wp_cache_set($key, $data);
	}
} else {
	echo "read...\n";
	foreach($testData as $key => $data) {
		if(serialize(wp_cache_get($key)) != serialize($data)) {
			echo "\n". serialize($data)."\n";
			die("read error $key!");
		}
	}
	echo "ok! $num";
}


echo "\nexpiry test:\n";

$expireAt = wp_cache_get('expire_at1');
$expire = 4;

if(!$expireAt) {
	wp_cache_set('expire_at1', time()+$expire, '', $expire );

	wp_cache_set('expire_in1', time()+1, '', 1 );
	wp_cache_set('expire_in2', time()+2, '', 2 );
	echo "set expire in $expire seconds";
} else {
	$s = $expireAt - time();
	echo "$expireAt should expire in $s seconds (expiry is $expire)\n";

	$expireIn1 = wp_cache_get('expire_in1');
	echo "expireIn1: $expireIn1 \n";

	$expireIn2 = wp_cache_get('expire_in2');
	echo "expireIn2: $expireIn2 \n";
}

