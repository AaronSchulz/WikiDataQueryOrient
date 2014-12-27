<?php

require_once( __DIR__ . '/../lib/autoload.php' );

function main() {
	$options = getopt( '', array( "user:", "password:", "url:" ) );

	$user = isset( $options['user'] ) ? $options['user'] : 'guest';
	$password = isset( $options['password'] ) ? $options['password'] : 'guest';
	$url = isset( $options['url'] ) ? $options['url'] : 'http://localhost:2480';

	$http = new MultiHttpClient( array() );
	list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $http->run( array(
		'method'  => 'GET',
		'url'     => "$url/connect/WikiData",
		'headers' => array(
			'Authorization' => "Basic " . base64_encode( "$user:$password" )
		)
	) );
	$m = array();
	if ( preg_match( '/(?:^|;)OSESSIONID=([^;]+);/', $rhdrs['set-cookie'], $m ) ) {
		$sessionId = $m[1];
		print( "Using session ID '$sessionId'\n" );
	} else {
		die( "Invalid authorization credentials ($rcode).\n" );
	}

	while ( true ) {
		print( "Enter query:\n" );
		$line = trim( stream_get_line( STDIN, 1024, PHP_EOL ) );
		$query = $line;
		while ( true ) {
			$line = trim( stream_get_line( STDIN, 1024, PHP_EOL ) );
			if ( $line === "" ) {
				break;
			}
			$query .= $line;
		}
		try {
			$sql = WdqQueryParser::parse( $query );
		} catch ( ParseException $e ) {
			print( "Caught parser error: {$e->getMessage()}\n" );
			continue;
		}
		print( "WDQ -> OrientSQL:\n$sql\n\n" );

		$limit = 1000; // sanity
		print( "Querying $url...\n" );
		$start = microtime( true );
		list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $http->run( array(
			'method'  => 'GET',
			'url'     => "$url/query/WikiData/sql/" . rawurlencode( $sql ) . "/$limit",
			'headers' => array( 'Cookie' => "OSESSIONID=$sessionId" )
		) );
		$elapsed = ( microtime( true ) - $start );
		print( "Done in $elapsed seconds...\n" );

		if ( $rcode == 401 ) {
			die( "Got HTTP 401: authentication expired.\n" );
		}

		$response = json_decode( $rbody, true );
		if ( $response === null ) {
			print( "HTTP error ($rcode): could not decode response ($rerr).\n\n" );
		} else {
			$count = 0;
			print( "Fetching results...\n" );
			foreach ( $response['result'] as $record ) {
				++$count;
				$obj = array();
				foreach ( $record as $key => $value ) {
					if ( $key === '*depth' ) {
						$obj[$key] = $value / 2; // only count vertex steps
					} elseif ( $key[0] !== '@' ) {
						$obj[$key] = $value;
					}
				}
				$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
				print( json_encode( $obj, $flags ) . "\n" );
			}
			print "Query had $count results\n\n";
		}
	}
}

main();
