<?php

class WdqUtils {
	/**
	 * @param string $code
	 * @return string integer
	 */
	public static function wdcToLong( $code ) {
		$int = substr( $code, 1 ); // strip the Q/P/L
		if ( !preg_match( '/^\d+$/', $int ) ) {
			throw new Exception( "Invalid code '$code'." );
		}
		return $int;
	}

	/**
	 * Handle dates like -20001-01-01T00:00:00Z and +20001-01-01T00:00:00Z
	 *
	 * @param string $time
	 * @return string|bool 64-bit UNIX timestamp or false on failure
	 */
	public static function getUnixTimeFromISO8601( $time ) {
		$m = array();
		$ok = preg_match( '#^(-|\+)0*(\d+)-(\d\d)-(\d\d)T0*(\d\d):0*(\d\d):0*(\d\d)Z#', $time, $m );
		if ( !$ok ) {
			trigger_error( "Got unparsable date '$time'." );
			return false;
		}

		list( , $sign, $year, $month, $day, $hour, $minute, $second ) = $m;
		$year = ( $sign === '-' ) ? -(int)$year : (int)$year;

		$date = new DateTime();
		try {
			$date->setDate( $year, (int)$month, (int)$day );
			$date->setTime( (int)$hour, (int)$minute, (int)$second );
		} catch ( Exception $e ) {
			trigger_error( "Got unparsable date '$time'." );
			return false;
		}

		return $date->format( 'U' );
	}

	/**
	 * @see http://www.satsig.net/lat_long.htm
	 * @param array $coords Map of (lat,lon)
	 * @return array|null
	 */
	public static function normalizeGeoCoordinates( array $coords ) {
		// We could wrap around huge values, but they might be broken anyway
		if ( $coords['lat'] > 180 || $coords['lat'] < -180 ) {
			return null;
		} elseif ( $coords['lon'] > 360 || $coords['lon'] < -360 ) {
			return null;
		}
		// Wrap around lat coordinates over 90deg or under -90deg
		if ( $coords['lat'] > 90 || $coords['lat'] < -90 ) {
			$sign = ( $coords['lat'] >= 0 ) ? 1 : -1; // +/1 = N/S
			$coords['lat'] = $sign * ( 90 - abs( $coords['lat'] ) % 90 );
		}
		// Wrap around lon coordinates over 180deg or under -180deg
		if ( $coords['lon'] > 180 || $coords['lon'] < -180 ) {
			$sign = ( $coords['lon'] >= 0 ) ? 1 : -1; // +/- = E/W
			$coords['lon'] = -$sign * ( 180 - abs( $coords['lon'] ) % 180 );
		}

		return $coords;
	}

	/**
	 * Take an arbitrarily nested array and turn it into JSON
	 *
	 * @param array $array
	 * @return string
	 */
	public static function toJSON( array $array ) {
		$json = json_encode( $array );
		if ( strpos( $json, '\\\\' ) !== false ) {
			// https://github.com/orientechnologies/orientdb/issues/2424
			$json = json_encode( self::mangleBacklashes( $array ) );
		}
		return $json;
	}

	/**
	 * @param array $array
	 * @return array
	 */
	protected static function mangleBacklashes( array $array ) {
		foreach ( $array as $key => &$value ) {
			if ( is_array( $value ) ) {
				$value = self::mangleBacklashes( $value );
			} elseif ( is_string( $value ) ) {
				$ovalue = $value;
				// XXX: https://github.com/orientechnologies/orientdb/issues/2424
				$value = rtrim( $value, '\\' ); // avoid exceptions
				if ( $value !== $ovalue ) {
					print( "JSON: converted value '$ovalue' => '$value'.\n" );
				}
			}
		}
		unset( $value );
		return $array;
	}
}
