<?php

require_once 'object-cache.php';

function testAssert($true) {
	if(!$true) {
		echo "Assertion failed!";
		debug_print_backtrace();
		exit(1);
	}
}


function testAssert2($got, $expected) {
	if($got !== $expected) {
		echo "Assertion failed got($got) !== expected($expected)!";
		debug_print_backtrace();
		exit(1);
	}
}

wp_cache_init();

$testData = [
    'num' => rand(),
    'str' => uniqid() . md5(__FILE__ . rand()),
    'obj' => (object)array('key' => 'value' . rand()),
    'str_long' => str_repeat(md5(rand()), 8000), //32*8000=256000byte
    'str_long2' => str_repeat(md5(rand()), 9000),
    'arr' => [1, 2, 3, 'foo', 'bar' => 'val', rand()]
];


foreach ([1, md5('foo-bar-cache')] as $k) {
    wp_cache_delete($k);

    foreach ($testData as $data) {
        testAssert(wp_cache_add($k, $data) === true);
        testAssert(wp_cache_add($k, $data) === false);

        testAssert(serialize(wp_cache_get($k, '', false, $found)) === serialize($data));
        testAssert($found === true);

        testAssert(wp_cache_add($k, serialize($data)) === false); // add existing
		testAssert(serialize(wp_cache_get($k, '', false, $found)) === serialize($data));
        testAssert($found === true);
        testAssert2(wp_cache_delete($k), true);

        testAssert(wp_cache_add($k, $data) === true);
        testAssert(wp_cache_delete($k) === true);
        testAssert(wp_cache_get($k, '', false, $found) === false);
        testAssert($found === false);



        testAssert(wp_cache_add($k, $data) === true);
        testAssert(serialize(wp_cache_get($k, '', false, $found)) === serialize($data));
        testAssert($found === true);

        testAssert(wp_cache_delete($k) === true);


        $data = serialize($data).md5(serialize($data)).rand();
        testAssert(wp_cache_add($k, $data) === true);
        testAssert(serialize(wp_cache_get($k, '', false, $found)) === serialize($data));
        testAssert($found === true);

        // overwrite after add
        $data = serialize($data).md5(serialize($data)).rand();
        testAssert(wp_cache_set($k, $data) === true);
        testAssert(serialize(wp_cache_get($k, '', false, $found)) === serialize($data));

        testAssert(wp_cache_delete($k) === true);

        // set
        $data = serialize($data).md5(serialize($data)).rand();
        testAssert(wp_cache_set($k, $data) === true);
        testAssert(serialize(wp_cache_get($k, '', false, $found)) === serialize($data));
        testAssert($found === true);

        // add after set
        testAssert(wp_cache_add($k, md5(serialize($data))) === false); // add existing
        // check that not chaned
        testAssert(serialize(wp_cache_get($k, '', false, $found)) === serialize($data));
        testAssert($found === true);

        // overwrite after set
        $data = serialize($data).md5(serialize($data)).rand();
        testAssert(wp_cache_set($k, $data) === true);
        testAssert(serialize(wp_cache_get($k, '', false, $found)) === serialize($data));
        testAssert($found === true);

        $data = serialize($data).md5(serialize($data)).rand();
        testAssert(wp_cache_replace($k, $data) === true);
        testAssert(serialize(wp_cache_get($k, '', false, $found)) === serialize($data));
        testAssert($found === true);


        // final delete
        testAssert(wp_cache_delete($k) === true);

        // replace after delete
        testAssert(wp_cache_replace($k, $data) === false);
        testAssert(wp_cache_get($k, '', false, $found) === false);
        testAssert($found === false);
        testAssert(wp_cache_delete($k) === false);
    }

    testAssert(wp_cache_incr($k) === false);
    testAssert(wp_cache_delete($k) === false);
    testAssert(wp_cache_incr($k) === false);

    testAssert(wp_cache_set($k, 0) === true);
    testAssert(wp_cache_incr($k) === 1);
    testAssert(wp_cache_get($k) === 1);
    testAssert(wp_cache_incr($k, 0) === 1);
    testAssert(wp_cache_get($k) === 1);
    testAssert(wp_cache_incr($k, 1) === 2);
    testAssert(wp_cache_get($k) === 2);
    testAssert(wp_cache_incr($k, 1000) === 1002);
    testAssert(wp_cache_get($k) === 1002);
    testAssert(wp_cache_incr($k, -1000) === 2);
    testAssert(wp_cache_get($k) === 2);
    testAssert(wp_cache_decr($k) === 1);
    testAssert(wp_cache_get($k) === 1);
    testAssert(wp_cache_decr($k) === 0);
    testAssert(wp_cache_get($k) === 0);
    testAssert(wp_cache_decr($k, 0) === 0);
    testAssert(wp_cache_get($k) === 0);
    testAssert(wp_cache_decr($k,-3) === 3);
    testAssert(wp_cache_get($k) === 3);


    testAssert(wp_cache_set($k, 'nan') === true);
    testAssert(wp_cache_incr($k,10) === 10);
    testAssert(wp_cache_get($k) === 10);
	
	
	// TODO object clone
	// TODO flush
}


echo "end. all tests passed!\n".filemtime(__FILE__);