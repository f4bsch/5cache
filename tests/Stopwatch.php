<?php


class Stopwatch {
	var $t;
	var $timings = array();

	public function __construct() {
		$this->start();
	}

	public static function registerSwFunc( $printStatsOnShutdown = false ) {
		global $stopwatch_printStatsOnShutdown;
		$stopwatch_printStatsOnShutdown = $printStatsOnShutdown;

		static $registered = false;

		if(!$registered) {
			/**
			 * @return Stopwatch
			 */
			function sw() {
				static $sw = null;
				if ( $sw == null ) {
					global $stopwatch_printStatsOnShutdown;
					global $stopwatch;
					$sw = new Stopwatch();
					if ( $stopwatch_printStatsOnShutdown ) {
						register_shutdown_function( function () use ( $sw ) {
							$stats = $sw->getStats();
							echo "Stopatch results:";
							print_r( $stats );
						} );
					}
					$stopwatch = $sw;
				}

				return $sw;
			}
			sw(); // init
			$registered = true;
		}
	}

	/**
	 * @return Stopwatch
	 */
	public static function getGlobal() {
		global $stopwatch;
		return $stopwatch;
	}

	public function start() {
		$this->t = microtime( true );
	}

	public function measure( $what ) {
		$t = microtime( true );
		if ( !isset( $this->timings[ $what ] ) ) {
			$this->timings[ $what ] = array();
		}
		$this->timings[ $what ][] = ( $t - $this->t ) * 1000;
		$this->t                  = microtime( true );
	}


	public function getStats( $sort = true, $simplifyN1 = true ) {
		$res = [];
		foreach ( $this->timings as $what => $measures ) {
			$med          = self::median( $measures );
			$n            = count( $measures );
			$sum          = array_sum( $measures );
			$res[ $what ] = ( $simplifyN1 && $n <= 1 ) ? round($sum,3) : [
				'sum' => $sum,
				'avg' => $sum / $n,
				'med' => $med,
				'min' => $measures[0],
				'max' => $measures[ $n - 1 ],
				'n'   => $n
			];
		}

		if ( $sort ) {
			uasort( $res, function ( $a, $b ) {
				$va = is_array( $a ) ? $a['sum'] : $a;
				$vb = is_array( $b ) ? $b['sum'] : $b;

				return $va === $vb ? 0 : ( ( $va < $vb ) ? 1 : - 1 );
			} );
		}

		return $res;
	}

	public static function median( &$arr ) {
		$n   = count( $arr );
		$mid = (int) ( $n / 2 );
		sort( $arr, SORT_NUMERIC );
		if ( $mid != 0 && $mid % 2 == 0 ) {
			return ( $arr[ $mid ] + $arr[ $mid - 1 ] ) / 2;
		} else {
			return $arr[ $mid ];
		}
	}

}