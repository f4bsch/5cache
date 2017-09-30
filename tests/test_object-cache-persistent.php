<?php

require_once 'object-cache.php';

wp_cache_init();



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
