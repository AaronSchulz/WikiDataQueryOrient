<?php

require_once( __DIR__ . '/../lib/autoload.php' );

function main() {
	$options = getopt( '', array( "user:", "password:", "url:" ) );

	$user = isset( $options['user'] ) ? $options['user'] : 'guest';
	$password = isset( $options['password'] ) ? $options['password'] : 'guest';
	$url = isset( $options['url'] ) ? $options['url'] : 'http://localhost:2480';

	$http = new MultiHttpClient( array() );
	$engine = new WdqQueryEngine( $http, $url, $user, $password );

	print( "

           __  ___                                    __             ____     __
          /  |/  /  ____ _   _____   __  __  _____   / /_   __  __  / __ \   / /
         / /|_/ /  / __ `/  / ___/  / / / / / ___/  / __ \ / / / / / / / /  / /
        / /  / /  / /_/ /  / /     / /_/ / / /__   / / / // /_/ / / /_/ /  / /___
       /_/  /_/   \__,_/  /_/      \__, /  \___/  /_/ /_/ \__,_/  \___\_\ /_____/
                                  /____/

"	);

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
		print( "Converted to OrientSQL:\n$sql\n\n" );

		print( "Querying $url...\n" );
		$start = microtime( true );
		try {
			$results = $engine->query( $query, 5000, 1000 );
		} catch ( Exception $e ) {
			print( "Caught error: {$e->getMessage()}\n" );
			continue;
		}
		$elapsed = ( microtime( true ) - $start );
		print( "Done in $elapsed seconds.\n" );

		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT;
		foreach ( $results as $record ) {
			print( json_encode( $record, $flags ) . "\n" );
		}
		print "Query had " . count( $results ) . " result(s)\n\n";
	}
}

main();
