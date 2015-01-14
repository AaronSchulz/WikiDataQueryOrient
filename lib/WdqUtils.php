<?php

class WdqUtils {
	/**
	 * @param string $code
	 * @return integer
	 */
	public static function wdcToLong( $code ) {
		if ( is_int( $code ) || ctype_digit( $code ) ) {
			return (int) $code; // already an integer
		}
		$int = substr( $code, 1 ); // strip the Q/P/L
		if ( !ctype_digit( $int ) ) {
			throw new Exception( "Invalid code '$code'." );
		}
		return (int) $int;
	}

	/**
	 * Handle dates like -20001-01-01T00:00:00Z and +20001-01-01T00:00:00Z
	 *
	 * @param string $time
	 * @param string|null $strictExp Throw exceptions of this type on error
	 * @return string|bool 64-bit UNIX timestamp or false on failure
	 */
	public static function getUnixTimeFromISO8601( $time, $strictExp = null ) {
		$result = false;

		$m = array();
		$ok = preg_match( '#^(-|\+)0*(\d+)-(\d\d)-(\d\d)T0*(\d\d):0*(\d\d):0*(\d\d)Z$#', $time, $m );
		if ( $ok ) {
			list( , $sign, $year, $month, $day, $hour, $minute, $second ) = $m;
			$year = ( $sign === '-' ) ? -(int)$year : (int)$year;

			$date = new DateTime();
			try {
				$date->setTimezone( new DateTimeZone( 'UTC' ) );
				$date->setDate( $year, (int)$month, (int)$day );
				$date->setTime( (int)$hour, (int)$minute, (int)$second );
				$result = $date->format( 'U' );
			} catch ( Exception $e ) {}
		}

		if ( $result === false ) {
			if ( $strictExp !== null ) {
				throw new $strictExp( "Unparsable timestamp: $time" );
			}
			trigger_error( "Got unparsable date '$time'." );
		}

		return $result;
	}

	/**
	 * Convert UNIX timestamps into values like +20001-01-01T00:00:00Z
	 *
	 * @param string|int $time 64-bit UNIX timestamp
	 * @return string|bool Readable date
	 */
	public static function getISO8601FromUnixTime( $time ) {
		$date = new DateTime( "@$time", new DateTimeZone( 'UTC' ) );
		if ( !$date ) {
			trigger_error( "Got unparsable date '$time'." );
			return false;
		}
		$s = $date->format( 'Y-m-d\TH:i:s\Z' );
		if ( $s === false ) {
			trigger_error( "Got unformatable date '$time'." );
			return false;
		}
		if ( $s[0] === '-' ) {
			$sign = '-';
			$s = substr( $s, 1 );
		} else {
			$sign = '+';
		}
		return $sign . str_pad( $s, 27, '0', STR_PAD_LEFT );
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
}
