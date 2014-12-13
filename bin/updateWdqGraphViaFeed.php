<?php

require_once( __DIR__ . '/../lib/OrientDB-PHP-master/OrientDB/OrientDB.php' );
require_once( __DIR__ . '/../lib/WDQFunctions.php' );
require_once( __DIR__ . '/../lib/WDQManager.php' );
require_once( __DIR__ . '/../lib/MultiHttpClient.php' );

define( 'API_QUERY_URL', 'http://www.wikidata.org/w/api.php' );

error_reporting( E_ALL );

function main() {
	$options = getopt( '', array(
		"user:", "password:", "start::", "posfile::",
	) );

	$user = $options['user'];
	$password = $options['password'];
	$start = isset( $options['start'] ) ? $options['start'] : null;
	$posFile = isset( $options['posfile'] ) ? $options['posfile'] : null;

	if ( $start === null && $posFile && is_file( $posFile ) ) {
		$start = trim( file_get_contents( $posFile ) ); // TS_MW timestamp
		print( "'$posFile' last position at '$start'; resuming.\n" );
	}

	$db = new OrientDB( 'localhost', 2424 );
	$connected = $db->connect( $user, $password );
	$config = $db->DBOpen( 'WikiData', 'admin', 'admin' );
	$gmr = new WDQManager( $db );
	$http = new MultiHttpClient( array() );

	// Sanity check oldest RC entry to see if the table was pruned in the range.
	// RC is kept for 90 days, use 83 for a week of safety factor.
	if ( $start !== null && ( time() - strtotime( $start ) ) > 86400*83 ) {
		print( "$start is too close (or more) than 90 days ago." );
		exit( 1 );
	}

	// RC tables handles:
	// new pages: 'new' entry
	// page edits: 'edit' entry
	// page moves: log entry at source, null edit at target
	// page deletes: log entry at target
	// page restores: log entry at target (TODO: check these)
	$baseQuery = array(
		'action' => 'query', 'list' => 'recentchanges',
		'rcnamespace' => '0|120', 'rctype' => 'log|edit|new',
		'rcdir' => 'older', 'rclimit' => 15, 'rcprop' => 'loginfo|ids|title',
		'format' => 'json'
	);

	while ( true ) {
		$continue = null;
		if ( $continue ) {
			$baseQuery['rcontinue'] = $continue;
		}
		$req = array( 'method' => 'GET', 'url' => API_QUERY_URL, 'query' => $baseQuery );

		print( "Requesting change list from " . API_QUERY_URL . "...\n" );
		list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $http->run( $req );
		$result = json_decode( $rbody, true );
		$continue = $result['query-continue']['recentchanges'];

		$changeCount = count( $result['query']['recentchanges'] );
		$itemsDeleted = array(); // list of Item IDs
		$propertiesDeleted = array(); // list of Property IDs
		$titlesChanged = array(); // rev ID => (ns,title,is new)
		foreach ( $result['query']['recentchanges'] as $change ) {
			if ( $change['type'] === 'new' ) {
				print( "New page: {$change['title']}\n" );
				$titlesChanged[$change['revid']] = array( $change['ns'], $change['title'], true );
			} elseif ( $change['type'] === 'edit' ) {
				print( "Modified page: {$change['title']}\n" );
				$titlesChanged[$change['revid']] = array( $change['ns'], $change['title'], false );
			} elseif ( $change['type'] === 'log' ) {
				if (
				( $change['logtype'] === 'delete' && $change['logaction'] === 'delete' ) ||
				( $change['logtype'] === 'move' )
				) {
					print( "Deleted or moved page: {$change['title']}\n" );
					if ( $change['ns'] == 0 ) { // Item
						$id = WDQGraphUtils::wdcToLong( $change['title'] );
						$itemsDeleted[] = $id;
					} elseif ( $change['ns'] == 120 ) { // Property
						list( $nstext, $key ) = explode( ':', $change['title'], 2 );
						$id = WDQGraphUtils::wdcToLong( $key );
						$propertiesDeleted[] = $id;
					}
				}
			}
		}

		$req = array( 'method' => 'GET', 'url' => API_QUERY_URL, 'query' => array(
			'action' => 'query', 'revids' => implode( '|', array_keys( $titlesChanged ) ),
			'prop' => 'revisions', 'rvprop' => 'ids|content', 'format' => 'json'
		) );

		print( "Requesting corresponding revision content from " . API_QUERY_URL . "...\n" );
		list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $http->run( $req );
		$result = json_decode( $rbody, true );

		$applyChanges = array(); // map of rev ID => (class, json, is new)
		foreach ( $result['query']['pages'] as $pageId => $pageInfo ) {
			$change = $pageInfo['revisions'][0];
			list( $ns, $title, $isNew ) = $titlesChanged[$change['revid']];
			if ( $ns == 0 ) { // Item
				$applyChanges[$change['revid']] = array( 'Item', $change['*'], $isNew );
			} elseif ( $ns == 120 ) { // Property
				list( $nstext, $key ) = explode( ':', $title, 2 );
				$applyChanges[$change['revid']] = array( 'Property', $change['*'], $isNew );
			}
		}
		$applyChangesInOrder = array(); // order should match $titlesChanged
		foreach ( $titlesChanged as $revId => $change ) {
			if ( isset( $applyChanges[$revId] ) ) {
				$applyChangesInOrder[$revId] = $applyChanges[$revId];
			}
		}

		print( "Updating graph...\n" );
		foreach ( $applyChangesInOrder as $change ) {
			list( $class, $json, $isNew ) = $change;
			$item = json_decode( $json, true );
			if ( $class === 'Item' ) {
				$id = WDQGraphUtils::wdcToLong( $item['id'] );
				/*
				$gmr->importItemVertex( $item, $isNew ? 'insert' : 'update' );
				if ( !$isNew ) {
					$gmr->deleteItemPropertyEdges( $id )
				}
				$gmr->importItemPropertyEdges( $item )
				*/
			} elseif ( $class === 'Property' ) {
				// $gmr->importPropertyVertex( $item, $isNew ? 'insert' : 'update' );
			}
		}
		if ( $itemsDeleted ) {
			// $gmr->deleteItemVertexes( $itemsDeleted );
		}
		if ( $propertiesDeleted ) {
			// $gmr->deletePropertyVertexes( $itemsDeleted );
		}

		if ( $posFile && $changeCount > 0 ) {
			list( $time, $rcid ) = explode( '|', $continue );
			file_put_contents( $posFile, $time );
			print( "Dumped position $time\n" );
		}

		if ( $changeCount > 0 ) {
			usleep( 1e5 );
		} else {
			sleep( 1 );
		}
	}
}

# Begin execution
main();
