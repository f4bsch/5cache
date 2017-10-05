<?php

require_once 'test-lib.php';
require_once 'object-cache.php';


wp_cache_init();


echo "scalar tests...\n";
testAssert( wp_cache_set( 'isTrue', true ) === true );
testAssert( wp_cache_set( 'isFalse', false ) === true );
testAssert( wp_cache_set( 'isNull', null ) === true );
testAssert( wp_cache_set( 'is1', 1 ) === true );
testAssert( wp_cache_set( 'is0', 0 ) === true );
testAssert( wp_cache_set( 'is0Str', '0' ) === true );
testAssert( wp_cache_set( 'isEmpty', '' ) === true );
testAssert( wp_cache_set( 'isInfPos', INF ) === true );
testAssert( wp_cache_set( 'isInfNeg', - INF ) === true );
testAssert( wp_cache_set( 'isNAN', NAN ) === true );
testAssert( wp_cache_set( 'isMaxPos', PHP_INT_MAX ) === true );
testAssert( wp_cache_set( 'isMaxNeg', - PHP_INT_MAX ) === true );




$found = false;
testAssert( wp_cache_get( 'isTrue', '', false, $found ) === true && $found === true );
$found = false;
testAssert( wp_cache_get( 'isFalse', '', false, $found ) === false && $found=== true );
$found = false;
testAssert( wp_cache_get( 'isNull', '', false, $found ) === null && $found=== true );
$found = false;
testAssert( wp_cache_get( 'is1', '', false, $found ) === 1 && $found=== true );
$found = false;
testAssert( wp_cache_get( 'is0', '', false, $found ) === 0 && $found=== true );
$found = false;
testAssert( wp_cache_get( 'is0Str', '', false, $found ) === '0' && $found=== true );
$found = false;
testAssert( wp_cache_get( 'isEmpty', '', false, $found ) === '' && $found=== true );
$found = false;
testAssert( wp_cache_get( 'isInfPos', '', false, $found ) === INF && $found=== true );
$found = false;
testAssert( wp_cache_get( 'isInfNeg', '', false, $found ) === - INF && $found=== true );
$found = false;
testAssert( is_nan(wp_cache_get( 'isNAN', '', false, $found ) ) && $found=== true );
$found = false;
testAssert( wp_cache_get( 'isMaxPos', '', false, $found ) === PHP_INT_MAX && $found=== true );
$found = false;
testAssert( wp_cache_get( 'isMaxNeg', '', false, $found ) === - PHP_INT_MAX && $found === true);

unset($found);
testAssert( wp_cache_get( '_notfound', '', false, $found ) === false && $found=== false );


// empty array
testAssert( wp_cache_set( 'isArrayEmpty', array() ) === true );
$found = false;
testAssert( wp_cache_get( 'isArrayEmpty', '', false, $found ) === array() && $found === true );



$found = false;
testAssert( wp_cache_get( 'isArrayEmpty', '', false, $found ) === array() && $found === true );



// text complex data structures
$testData = [
	'num'       => rand(),
	'str'       => uniqid() . md5( __FILE__ . rand() ),
	'obj'       => (object) array( 'key' => 'value' . rand() ),
	'str_long'  => str_repeat( md5( rand() ), 8000 ), //32*8000=256000byte
	'str_long2' => str_repeat( md5( rand() ), 9000 ),
	'arr'       => [ 1, 2, 3, 'foo', 'bar' => 'val', rand() ]
];

echo "data structure tests...\n";
foreach ( [ 1, md5( 'foo-bar-cache' ) ] as $k ) {
	wp_cache_delete( $k );

	foreach ( $testData as $data ) {
		testAssert( wp_cache_add( $k, $data ) === true );
		testAssert( wp_cache_add( $k, $data ) === false );

		testAssert( serialize( wp_cache_get( $k, '', false, $found ) ) === serialize( $data ) );
		testAssert( $found === true );

		testAssert( wp_cache_add( $k, serialize( $data ) ) === false ); // add existing
		testAssert( serialize( wp_cache_get( $k, '', false, $found ) ) === serialize( $data ) );
		testAssert( $found === true );
		testAssert2( wp_cache_delete( $k ), true );

		testAssert( wp_cache_add( $k, $data ) === true );
		testAssert( wp_cache_delete( $k ) === true );
		testAssert( wp_cache_get( $k, '', false, $found ) === false );
		testAssert( $found === false );


		testAssert( wp_cache_add( $k, $data ) === true );
		testAssert( serialize( wp_cache_get( $k, '', false, $found ) ) === serialize( $data ) );
		testAssert( $found === true );

		testAssert( wp_cache_delete( $k ) === true );


		$data = serialize( $data ) . md5( serialize( $data ) ) . rand();
		testAssert( wp_cache_add( $k, $data ) === true );
		testAssert( serialize( wp_cache_get( $k, '', false, $found ) ) === serialize( $data ) );
		testAssert( $found === true );

		// overwrite after add
		$data = serialize( $data ) . md5( serialize( $data ) ) . rand();
		testAssert( wp_cache_set( $k, $data ) === true );
		testAssert( serialize( wp_cache_get( $k, '', false, $found ) ) === serialize( $data ) );

		testAssert( wp_cache_delete( $k ) === true );

		// set
		$data = serialize( $data ) . md5( serialize( $data ) ) . rand();
		testAssert( wp_cache_set( $k, $data ) === true );
		testAssert( serialize( wp_cache_get( $k, '', false, $found ) ) === serialize( $data ) );
		testAssert( $found === true );

		// add after set
		testAssert( wp_cache_add( $k, md5( serialize( $data ) ) ) === false ); // add existing
		// check that not chaned
		testAssert( serialize( wp_cache_get( $k, '', false, $found ) ) === serialize( $data ) );
		testAssert( $found === true );

		// overwrite after set
		$data = serialize( $data ) . md5( serialize( $data ) ) . rand();
		testAssert( wp_cache_set( $k, $data ) === true );
		testAssert( serialize( wp_cache_get( $k, '', false, $found ) ) === serialize( $data ) );
		testAssert( $found === true );

		$data = serialize( $data ) . md5( serialize( $data ) ) . rand();
		testAssert( wp_cache_replace( $k, $data ) === true );
		testAssert( serialize( wp_cache_get( $k, '', false, $found ) ) === serialize( $data ) );
		testAssert( $found === true );


		// final delete
		testAssert( wp_cache_delete( $k ) === true );

		// replace after delete
		testAssert( wp_cache_replace( $k, $data ) === false );
		testAssert( wp_cache_get( $k, '', false, $found ) === false );
		testAssert( $found === false );
		testAssert( wp_cache_delete( $k ) === false );
	}

	testAssert( wp_cache_incr( $k ) === false );
	testAssert( wp_cache_delete( $k ) === false );
	testAssert( wp_cache_incr( $k ) === false );

	testAssert( wp_cache_set( $k, 0 ) === true );
	testAssert( wp_cache_incr( $k ) === 1 );
	testAssert( wp_cache_get( $k ) === 1 );
	testAssert( wp_cache_incr( $k, 0 ) === 1 );
	testAssert( wp_cache_get( $k ) === 1 );
	testAssert( wp_cache_incr( $k, 1 ) === 2 );
	testAssert( wp_cache_get( $k ) === 2 );
	testAssert( wp_cache_incr( $k, 1000 ) === 1002 );
	testAssert( wp_cache_get( $k ) === 1002 );
	testAssert( wp_cache_incr( $k, - 1000 ) === 2 );
	testAssert( wp_cache_get( $k ) === 2 );
	testAssert( wp_cache_decr( $k ) === 1 );
	testAssert( wp_cache_get( $k ) === 1 );
	testAssert( wp_cache_decr( $k ) === 0 );
	testAssert( wp_cache_get( $k ) === 0 );
	testAssert( wp_cache_decr( $k, 0 ) === 0 );
	testAssert( wp_cache_get( $k ) === 0 );
	testAssert( wp_cache_decr( $k, - 3 ) === 3 );
	testAssert( wp_cache_get( $k ) === 3 );


	testAssert( wp_cache_set( $k, 'nan' ) === true );
	testAssert( wp_cache_incr( $k, 10 ) === 10 );
	testAssert( wp_cache_get( $k ) === 10 );





	// TODO object clone
	// TODO flush

}


echo "doing OOM test...\n";
flush();


// first big data should be gone (from last request)
testAssert( ! wp_cache_delete( 'big_data_0' ) );

$bs = 16 * 1024 * 32;
echo "writing 100 keys/values, each $bs bytes\n";
// OOM
for ( $i = 0; $i < 100; $i ++ ) {
	testAssert( wp_cache_set( 'big_data_' . $i, str_repeat( md5( rand() ), 16 * 1024 ) ) );
}

testAssert2( strlen( wp_cache_get( 'big_data_' . ( $i - 1 ) ) ), $bs );

// delete all but 0-16 (leaving $bs*16 = 8MB)
for ( $i = 16; $i < 100; $i ++ ) {
	wp_cache_delete( 'big_data_' . $i );
}


testEnd();
