<?php

if ( PHP_SAPI !== 'cli' ) {
	die( "This script can only be run in CLI mode\n" );
}

require_once( __DIR__ . '/../lib/autoload.php' );

error_reporting( E_ALL );
ini_set( 'memory_limit', '512M' );

function iterateItems( WdqUpdater $updater, $bSize, $posFile, callable $callback ) {
	# Pick up from any prior position
	if ( $posFile && is_file( $posFile ) ) {
		$pos = (int) trim( file_get_contents( $posFile ) ); // entity ID
		print( "'$posFile' last position was '$pos'; resuming.\n" );
	} else {
		$pos = 0;
	}

	$n = 0;
	$firstBatch = true;
	$lastTime = microtime( true );
	do {
		$res = $updater->tryQuery(
			"SELECT id,claims,@rid FROM Item where id > $pos ORDER BY id", $bSize );
		if ( !$res ) {
			break;
		}
		$hasAdvanced = $callback( $res, $firstBatch );
		$firstBatch = false;
		$pos = $res[count( $res ) - 1]['id'];
		$n += count( $res );
		if ( $posFile && $hasAdvanced ) {
			// Dump the position when it's safe to do so ($hasAdvanced)
			if ( !file_put_contents( $posFile, $pos ) ) {
				throw new Exception( "Could not write to '$posFile'." );
			}
		}
		if ( $n >= 1000 ) {
			$rate = $n / ( microtime( true ) - $lastTime );
			print( "Doing $rate items/sec (at $pos)\n" );
			$lastTime = microtime( true );
			$n = 0;
		}
	} while ( count( $res ) );
}

function main() {
	$options = getopt( '', array(
		"user:", "password:", "url::", "posdir::", "method::", "modulo::", "classes::"
	) );

	$user = $options['user'];
	$password = $options['password'];
	$url = isset( $options['url'] ) ? $options['url'] : 'http://localhost:2480';
	$method = isset( $options['method'] ) ? $options['method'] : 'rebuild';
	$posFile = isset( $options['posdir'] )
		? "{$options['posdir']}/edges-$method.pos"
		: null;
	$classes = isset( $options['classes'] )
		? explode( ',', $options['classes'] )
		: null;

	if ( $posFile !== null ) {
		print( "Using position file: $posFile\n" );
	}

	if ( $posFile && !file_exists( dirname( $posFile ) ) ) {
		mkdir( dirname( $posFile ), 0777 );
	}

	$auth = array( 'url' => $url, 'user' => $user, 'password' => $password );
	$updater = new WdqUpdater( new MultiHttpClient( array() ), $auth );

	$updater->buildPropertyRIDCache();

	$bSize = 100;
	iterateItems( $updater, $bSize, $posFile,
		function( $entities, $firstBatch ) use ( $updater, $method, $classes ) {
			// Restarting might redo the first batch; preserve idempotence
			$safeMethod = $firstBatch ? 'rebuild' : $method;
			$from = $entities[0]['id'];
			$to = $entities[count( $entities ) - 1]['id'];
			print( "Importing edges for Items $from-$to ($safeMethod)..." );
			$updater->makeItemEdges( $entities, $method, $classes );
			print( "done\n" );
			return true;
		}
	);
}

main();
