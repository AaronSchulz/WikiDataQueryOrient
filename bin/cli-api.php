<?php

require_once( __DIR__ . '/../lib/OrientDB-PHP-master/OrientDB/OrientDB.php' );
require_once( __DIR__ . '/../lib/autoload.php' );

$user = 'guest';
$password = 'guest';

$db = new OrientDB( 'localhost', 2424 );
$db->connect( $user, $password );
$db->DBOpen( 'WikiData', 'admin', 'admin' );

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
	$count = 0;
	print( "Running...\n" );
	$start = microtime( true );
	$res = $db->command( OrientDB::COMMAND_SELECT_ASYNC, $sql );
	$elapsed = ( microtime( true ) - $start );
	print( "Done in $elapsed seconds...\n" );
	if ( $res === false ) {
		// no results?
	} else {
		print( "Fetching results...\n" );
		foreach ( $res as $record ) {
			++$count;
			$obj = array();
			foreach ( $record->data as $key => $value ) {
				$obj[$key] = $value;
			}
			print( json_encode( $obj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n" );
		}
		print( "Done.\n" );
	}
	print "Query had $count results\n\n";
}
