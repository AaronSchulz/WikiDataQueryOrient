<?php

if ( PHP_SAPI !== 'cli' ) {
	die( "This script can only be run in CLI mode\n" );
}

require_once( __DIR__ . '/../lib/autoload.php' );

error_reporting( E_ALL );
ini_set( 'memory_limit', '512M' );

function iterateJsonDump( $dump, array $modParams, $posFile, callable $callback ) {
	list( $mDiv, $mRem ) = $modParams;
	$mDiv = (int)$mDiv;
	$mRem = (int)$mRem;

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
	$dHandle = fopen( $dump, "rb+" );
	if ( $dHandle ) {
		if ( $offset > 0 && PHP_INT_SIZE == 8 ) {
			// fseek()/ftell() give garbage on big files in 32-bit (Windows)
			fseek( $dHandle, $offset );
			$started = true;
			$pos = $after;
		}
		$lastTime = microtime( true );
		$itemCount = 0; // items processed this run
		while ( ( $line = fgets( $dHandle ) ) !== false ) {
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
			if ( ( $pos % $mDiv ) == $mRem ) {
				$item = json_decode( $line, true );
				if ( $item === null ) {
					throw new Exception( "Got bad JSON line:\n$line\n" );
				}
				++$itemCount;
				$hasAdvanced = $callback( $item, $itemCount );
			} else {
				$hasAdvanced = false;
			}
			if ( $posFile && $hasAdvanced ) {
				// Dump the position when it's safe to do so ($hasAdvanced)
				if ( !file_put_contents( $posFile, $pos . '|' . $offset ) ) {
					throw new Exception( "Could not write to '$posFile'." );
				}
			}
			if ( $itemCount > 0 && $itemCount % 1000 == 0 ) {
				$rate = 1000 / ( microtime( true ) - $lastTime );
				print( "Doing $rate items/sec (at $pos)\n" );
				$lastTime = microtime( true );
			}
		}
		fclose( $dHandle );
	}
}

function main() {
	$options = getopt( '', array(
		"dump:", "user:", "password:",
		"url::", "posdir::", "method::", "modulo::", "classes::"
	) );

	$dump = $options['dump'];
	$user = $options['user'];
	$password = $options['password'];
	$url = isset( $options['url'] ) ? $options['url'] : 'http://localhost:2480';
	$method = isset( $options['method'] ) ? $options['method'] : 'upsert';
	$modulo = isset( $options['modulo'] ) // 4,1 means (object# % 4 = 1)
		? $options['modulo']
		: '1,0';
	$posFile = isset( $options['posdir'] )
		? "{$options['posdir']}/vertexes-$method-$modulo.pos"
		: null;
	$modParams = explode( ',', $modulo );
	if ( count( $modParams ) != 2 ) {
		die( "Bad --modulo parameter '$modulo'.\n" );
	}
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

	$batch = array();
	$batchSize = 100;
	if ( $classes ) {
		$classes = array_map( 'strtolower', $classes );
	}
	iterateJsonDump( $dump, $modParams, $posFile,
		function( $entity ) use ( $updater, $method, $classes, &$batch, $batchSize ) {
			if ( $classes !== null && !in_array( $entity['type'], $classes ) ) {
				return false; // nothing to do
			}
			if ( $entity['type'] === 'item' ) {
				print( 'Importing vertex for Item ' . $entity['id'] . " ($method)\n" );
				$batch[] = $entity;
			} elseif ( $entity['type'] === 'property' ) {
				print( 'Importing vertex for Property ' . $entity['id'] . " ($method)\n" );
				$batch[] = $entity;
			}
			if ( count( $batch ) >= $batchSize ) {
				print( "Comitting..." );
				$updater->importEntities( $batch, $method );
				print( "done\n" );
				$batch = array();
				return true;
			}
			return false;
		}
	);
	if ( count( $batch ) ) {
		print( "Comitting..." );
		$updater->importEntities( $batch, $method );
		print( "done\n" );
		$batch = array();
	}
}

main();
