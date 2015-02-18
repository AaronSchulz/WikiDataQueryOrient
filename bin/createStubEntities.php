<?php

if ( PHP_SAPI !== 'cli' ) {
	die( "This script can only be run in CLI mode\n" );
}

require_once( __DIR__ . '/../lib/autoload.php' );

error_reporting( E_ALL );
ini_set( 'memory_limit', '128M' );

function iterateClassIds( WdqUpdater $updater, $class, $bSize, $posFile, callable $callback ) {
	# Pick up from any prior position
	if ( $posFile && is_file( $posFile ) ) {
		$after = (int) trim( file_get_contents( $posFile ) ); // entity ID
		print( "'$posFile' last position was '$after'; resuming.\n" );
	} else {
		$after = 0;
	}

	$n = 0;
	$pos = 0;
	$lastTime = microtime( true );
	do {
		$res = $updater->tryQuery( "SELECT id FROM $class where id > $pos ORDER BY id", $bSize );
		if ( !$res ) {
			break;
		}
		$ids = array();
		foreach ( $res as $record ) {
			$ids[] = $record['id'];
		}
		$pos = $res[count( $res ) - 1]['id'];
		$hasAdvanced = $callback( $ids );
		if ( $posFile && $hasAdvanced ) {
			// Dump the position when it's safe to do so ($hasAdvanced)
			if ( !file_put_contents( $posFile, $pos ) ) {
				throw new Exception( "Could not write to '$posFile'." );
			}
		}
		$n += count( $res );
		if ( $n >= 1000 ) {
			$rate = $n / ( microtime( true ) - $lastTime );
			print( "Doing $rate entities/sec (at $pos)\n" );
			$lastTime = microtime( true );
			$n = 0;
		}
	} while ( count( $res ) );
}

function main() {
	$options = getopt( '', array( "user:", "password:", "url::", "posdir::" ) );

	$user = isset( $options['user'] ) ? $options['user'] : 'admin';
	$password = isset( $options['password'] ) ? $options['password'] : 'admin';
	$url = isset( $options['url'] ) ? $options['url'] : 'http://localhost:2480';
	$posFile = isset( $options['posdir'] )
		? "{$options['posdir']}/stubvertexes.pos"
		: null;

	if ( $posFile !== null ) {
		print( "Using position file: $posFile\n" );
	}

	if ( $posFile && !file_exists( dirname( $posFile ) ) ) {
		mkdir( dirname( $posFile ), 0777 );
	}

	$auth = array( 'url' => $url, 'user' => $user, 'password' => $password );
	$updater = new WdqUpdater( new MultiHttpClient( array() ), $auth );
	$bSize = 1000;

	$lastId = 0;
	iterateClassIds( $updater, 'Property', $bSize, $posFile,
		function( $ids ) use ( $updater, &$lastId ) {
			$from = reset( $ids );
			$to = end( $ids );
			print( "Importing stub vertexes for Properties $from-$to...\n" );

			$stubIds = array();
			foreach ( $ids as $id ) {
				if ( $lastId && $id != ( $lastId + 1 ) ) {
					print( "Stubs needed in interval ($lastId,$id)\n" );
					$stubIds = array_merge( $stubIds, range( $lastId + 1, $id - 1 ) );
				}
				$lastId = $id;
			}

			$updater->createDeletedPropertyStubs( $stubIds );

			print( "Comitted\n" );
			return false; // positions are only for Items
		}
	);
	// If the highest IDs were deleted, we won't have stubs for them.
	// Stub out the next possible properties as a precaution to avoid this.
	$res = $updater->tryQuery( "SELECT id FROM Property WHERE stub IS NULL ORDER BY id DESC", 1 );
	if ( $res ) {
		print( "Pre-allocating stub properties..." );
		$finalId = $res[0]['id'];
		$updater->createDeletedPropertyStubs( range( $finalId + 1, $finalId + 100 ) );
		print( "done\n" );
	}

	$lastId = 0;
	iterateClassIds( $updater, 'Item', $bSize, $posFile,
		function( $ids ) use ( $updater, &$lastId ) {
			$from = reset( $ids );
			$to = end( $ids );
			print( "Importing stub vertexes for Items $from-$to...\n" );

			$stubIds = array();
			foreach ( $ids as $id ) {
				if ( $lastId && $id != ( $lastId + 1 ) ) {
					print( "Stubs needed in interval ($lastId,$id)\n" );
					$stubIds = array_merge( $stubIds, range( $lastId + 1, $id - 1 ) );
				}
				$lastId = $id;
			}

			$updater->createDeletedItemStubs( $stubIds );

			print( "Comitted\n" );
			return true;
		}
	);
	// If the highest IDs were deleted, we won't have stubs for them.
	// Stub out the next possible items as a precaution to avoid this.
	$res = $updater->tryQuery( "SELECT id FROM Item WHERE stub IS NULL ORDER BY id DESC", 1 );
	if ( $res ) {
		print( "Pre-allocating stub items..." );
		$finalId = $res[0]['id'];
		$updater->createDeletedItemStubs( range( $finalId + 1, $finalId + 1000 ) );
		print( "done\n" );
	}
}

main();
