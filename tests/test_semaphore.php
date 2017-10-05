<?php

$concurrency = 9; // how many "threads"?

if ( empty( $_GET['iframe'] ) ) {
	for ( $i = 0; $i < $concurrency; $i ++ ) {
		echo "<iframe src='" . $_SERVER['PHP_SELF'] . "?iframe=1'></iframe>";
	}
	exit;
}

disableOutputBuffer();
echo "<pre>";


$waitForMs = rand( 10, 100 ) * 10;
msg( "sleeping for $waitForMs ms ..." );
usleep( $waitForMs * 1e3 );


msg( "sem_acquire ..." );
$shmKey = ftok( __FILE__, 's' );
$semId  = sem_get( $shmKey );

$i = 0;
$t = microtime( true );
while ( ( $needToWait = ! sem_acquire( $semId, true ) ) ) {
	if ( $i == 0 ) {
		msg( "waitSem  ... " );
	}
	if ( ( ++ $i ) > ( 1000 * 30 ) ) {
		msg( "error: waitSem timed out! " );
		exit;
	}
	usleep( 1000 );
}
$t = round( ( microtime( true ) - $t ), 2 );

msg( "got sem after i=$i, t=$t s" );
msg( "holding for 6 s ..." );
sleep( 6 );
msg( "end!" );


function disableOutputBuffer( $dontPad = false ) {
	@ini_set( 'zlib.output_compression', 'Off' );
	header( 'X-Accel-Buffering: no' );

	ob_implicit_flush( true );
	$levels = ob_get_level();
	for ( $i = 0; $i < $levels; $i ++ ) {
		ob_end_flush();
	}
	flush();

	if ( ! $dontPad ) {
		// generate a random whitespace string with entropy to avoid gzip reduce
		static $chars = array( " ", "\r\n", "\n", "\t" );
		$stuff = '';
		$m     = count( $chars ) - 1;
		for ( $i = 0; $i < ( 1024 * 5 ); $i ++ ) { // 4KiB is minimum
			$stuff .= $chars[ rand( 0, $m ) ];
		}
		echo "$stuff\n";
	}
}

function msg( $msg ) {
	echo $msg . "\n";
}