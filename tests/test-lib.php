<?php
echo "<pre>\n";
global $nAssert;
$nAssert = 0;

error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);

function testAssert( $true ) {
	global $nAssert;
	$nAssert ++;

	if ( ! $true ) {
		echo "Assertion failed!";
		debug_print_backtrace();
		exit( 1 );
	}
}


function testAssert2( $got, $expected ) {
	global $nAssert;
	$nAssert ++;

	if ( $got !== $expected ) {
		echo "Assertion failed got($got) !== expected($expected)!";
		debug_print_backtrace();
		exit( 1 );
	}
}

function testEnd() {
	global $nAssert;
	echo "\nEND. all tests PASSED ($nAssert assertions)!\n test timestamp:" . microtime( true ) . "\n test suite {$_SERVER['SCRIPT_FILENAME']} version:" . filemtime( $_SERVER['SCRIPT_FILENAME'] );
	if(error_get_last())
		var_dump(error_get_last());
}