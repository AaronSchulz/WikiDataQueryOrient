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
		$sql = WdqQueryParser::parse( $query );
		print( "WDQ -> OrientSQL:\n$sql\n\n" );

		print( "Running (requesting from $url)...\n" );
		$start = microtime( true );
		list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $http->run( array(
			'method'  => 'GET',
			'url'     => "$url/query/WikiData/sql/" . rawurlencode( $sql ),
			'headers' => array( 'Cookie' => "OSESSIONID=$sessionId" )
		) );
		$elapsed = ( microtime( true ) - $start );
		print( "Done in $elapsed seconds...\n" );

		$response = json_decode( $rbody, true );
		if ( $response === null ) {
			print( "HTTP error ($rcode): could not decode response ($rerr).\n" );
		} else {
			$count = 0;
			print( "Fetching results...\n" );
			foreach ( $response['result'] as $record ) {
				++$count;
				print( json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n" );
			}
			print( "Done.\n" );
		}
		print "Query had $count results\n\n";
	}
}

main();
