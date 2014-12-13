<?php

require_once( __DIR__ . '/../lib/OrientDB-PHP-master/OrientDB/OrientDB.php' );
require_once( __DIR__ . '/../lib/autoload.php' );

error_reporting( E_ALL );
ini_set( 'memory_limit', '256M' );

function iterateJsonDump( $dump, $modulo, $posFile, callable $callback ) {
	# Pick up from any prior position
	if ( $posFile && is_file( $posFile ) ) {
		$content = trim( file_get_contents( $posFile ) );
		list( $after, $offset ) = explode( "|", $content, 2 );
		$after = (float) $after; // line
		$offset = (float) $offset; // byte offset
		print( "'$posFile' last position was '$after' (at $offset); resuming.\n" );
	} else {
		$after = 0.0;
		$offset = 0.0;
	}

	$pos = -1;
	$started = ( $after == 0 );
	$handle = fopen( $dump, "rb+" );
	if ( $handle ) {
		// XXX: still not working right on 32bit
		if ( $offset > 0 && PHP_INT_SIZE == 8 ) {
			// fseek()/ftell() give garbage on big files in 32-bit (Windows)
			for ( $i=1; $i <= floor( $offset / 2e9 ); ++$i ) {
				fseek( $handle, 2e9, SEEK_CUR );
				print( "Seeking ahead by 2e9\n" );
			}
			fseek( $handle, fmod( $offset, 2e9 ), SEEK_CUR );
			print( "Seeking ahead by " . fmod( $offset, 2e9 ) . "\n" );
			$started = true;
			$pos = $after;
		}
		$itemCount = 0;
		while ( ( $line = fgets( $handle ) ) !== false ) {
			++$pos;
			$offset += (float) strlen( $line );
			if ( !$started ) {
				$started = ( $after == $pos );
				continue;
			}
			$line = trim( $line, " \t\n\r," );
			if ( $line === '[' || $line === ',' || $line === ']' ) {
				continue;
			}
			if ( $line % $modulo == 0 ) {
				$item = json_decode( $line, true );
				if ( $item === null ) {
					throw new Exception( "Got bad JSON line:\n$line\n" );
				}
				++$itemCount;
				$callback( $item, $itemCount );
			}
			if ( $posFile ) {
				// Dump each line so method=bulk_init is restartable
				$bytes = file_put_contents( $posFile, $pos . '|' . $offset );
				if ( $bytes === false ) {
					throw new Exception( "Could not write to '$posFile'." );
				}
			}
		}
		fclose( $handle );
	}
}

function main() {
	$options = getopt( '', array(
		"dump:", "phase:", "user:", "password:",
		"posdir::", "method::", "modulo::",
	) );

	$dump = $options['dump'];
	$phase = $options['phase']; // vertexes/edges
	$user = $options['user'];
	$password = $options['password'];
	$method = isset( $options['method'] )
		? $options['method']
		: ( $phase === 'vertexes' ? 'upsert' : 'rebuild' );
	$modulo = isset( $options['modulo'] ) ? $options['modulo'] : 1;
	$posFile = isset( $options['posdir'] )
		? "{$options['posdir']}/$phase-$method-$modulo.pos"
		: null;

	$db = new OrientDB( 'localhost', 2424 );
	$db->connect( $user, $password );
	$db->DBOpen( 'WikiData', 'admin', 'admin' );
	$updater = new WdqUpdater( $db );

	# Pass 1; load in all vertexes
	if ( $phase === 'vertexes' ) {
		iterateJsonDump( $dump, $modulo, $posFile,
			function( $item ) use ( $updater, $method ) {
				if ( $item['type'] === 'item' ) {
					print( 'Importing vertex for Item ' . $item['id'] . " ($method)\n" );
					$updater->importItemVertex( $item, $method );
				} elseif ( $item['type'] === 'property' ) {
					print( 'Importing vertex for Property ' . $item['id'] . " ($method)\n" );
					$updater->importPropertyVertex( $item, $method );
				}
			}
		);
	# Pass 2: establish all edges between vertexes
	} elseif ( $phase === 'edges' ) {
		iterateJsonDump( $dump, $modulo, $posFile,
			function( $item, $count ) use ( $updater, $method ) {
				if ( $item['type'] === 'item' ) {
					// Restarting might redo the first item; preserve idempotence
					$safeMethod = ( $count == 1 ) ? 'rebuild' : $method;
					print( 'Importing edges for Item ' . $item['id'] . " ($safeMethod)\n" );
					$updater->importItemPropertyEdges( $item, $safeMethod );
				}
			}
		);
	}
}

# Begin execution
main();
