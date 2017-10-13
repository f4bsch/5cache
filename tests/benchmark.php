<?php

$maxBlockSizeOrder = 20; // 2^20 = 1Mi
include 'Stopwatch.php';

/** @noinspection PhpIncludeInspection */
(@include '../object-cache.php') || (@include 'object-cache.php');

$sw = new Stopwatch();
disableOutputBuffer();


for($i = 0; $i < $maxBlockSizeOrder; $i++) {
	$bs = pow(2,$i);
	$bn = min(pow(2,$maxBlockSizeOrder-$i), 20000);


	msg("bs=$bs, bn=$bn");

	$sw->start();
	wp_cache_init();
	$sw->measure('init');

	//global $wp_object_cache;
	//$c1 = $wp_object_cache;
	//$c2 = clone $c1;

	for($j = 0; $j < $bn; $j++) {
		$data = substr(str_repeat(md5(rand()).md5(rand()), floor($bs/64)), $bs);
		if(strlen($data) != $bs)
			die('data len error');
		$key = md5(rand());

		$sw->start();
		wp_cache_set($key, $data);
		$sw->measure("set$bs");
	}

	$sw->start();
	wp_cache_close();
	$sw->measure('close');

	//$wp_object_cache = $c2;
	//wp_cache_close(); // 2nd
}











function disableOutputBuffer($dontPad = false)
{
	@ini_set('zlib.output_compression', 'Off');
	header('X-Accel-Buffering: no');

	ob_implicit_flush(true);
	$levels = ob_get_level();
	for ( $i = 0; $i < $levels; $i ++ ) {
		ob_end_flush();
	}
	flush();
	if (!$dontPad) {
		// generate a random whitespace string with entropy to avoid gzip reduce
		static $chars = array(" ", "\r\n", "\n", "\t");
		$stuff = '';
		$m = count($chars) - 1;
		for ($i = 0; $i < (1024 * 5); $i++) { // 4KiB is minimum
			$stuff .= $chars[rand(0, $m)];
		}
		echo "$stuff\n";
	}
}
function msg($msg) {
	echo $msg."\n";
}