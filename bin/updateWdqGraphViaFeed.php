<?php

if ( PHP_SAPI !== 'cli' ) {
	die( "This script can only be run in CLI mode\n" );
}

require_once( __DIR__ . '/../lib/MultiHttpClient.php' );
require_once( __DIR__ . '/../lib/autoload.php' );

define( 'API_QUERY_URL', 'http://www.wikidata.org/w/api.php' );

error_reporting( E_ALL );
ini_set( 'memory_limit', '256M' );

function main() {
	$options = getopt( '', array( "user:", "password:", "url::" ) );

	$user = $options['user'];
	$password = $options['password'];
	$url = isset( $options['url'] ) ? $options['url'] : 'http://localhost:2480';

	$http = new MultiHttpClient( array() );
	$auth = array( 'url' => $url, 'user' => $user, 'password' => $password );
	$updater = new WdqUpdater( $http, $auth );

	// Use the DBStatus table to get the current position
	$sTimestamp = getCurrentRCPosition( $updater );
	// If not set, then get the highest Q code and it's creation timestamp.
	// XXX: grab several of the highest, in case the highest X where deleted.
	if ( $sTimestamp === null ) {
		print( "No replication timestamp found; determining.\n" );
		$sTimestamp = determineRCPosition( $updater, $http );
	}

	// Fail if no replication timestamp could be determined
	if ( $sTimestamp === null ) {
		die( "Could not find a suitable start timestamp.\n" );
	// Sanity check oldest RC entry to see if the table was pruned in the range.
	// RC is kept for 90 days, use 83 for a week of safety factor.
	} elseif ( ( time() - strtotime( $sTimestamp ) ) > 86400*87 ) {
		die( "$sTimestamp is near (or over) 90 days ago. Use a newer JSON dump." );
	}

	// Get max IDs for stub creation
	$maxItemId = getMaxEntityId( $updater, 'Item' );
	$maxPropId = getMaxEntityId( $updater, 'Property' );

	print( "Status=(timestamp:$sTimestamp,maxitem:$maxItemId,maxprop:$maxPropId)\n" );

	// RC tables handles:
	// new pages: 'new' entry
	// page edits: 'edit' entry
	// page moves: log entry at source, null edit at target
	// page deletes: log entry at target
	// page restores: log entry at target

	// @note: if "rclimit" is too high, the text query afterwards will be missing
	// items due to the wbgetentities server-size limit of 50 entities per query.
	$baseRCQuery = array(
		'action' => 'query', 'list' => 'recentchanges',
		'rcnamespace' => '0|120', 'rctype' => 'log|edit|new',
		'rcdir' => 'newer', 'rclimit' => 50, 'rcprop' => 'loginfo|ids|title|timestamp',
		'format' => 'json', 'continue' => '', 'rcstart' => $sTimestamp
	);

	$updater->buildPropertyRIDCache();

	$lastTimestamp = $sTimestamp;
	$lastRcID = 0;
	while ( true ) {
		$batchStartTime = microtime( true );

		$req = array( 'method' => 'GET', 'url' => API_QUERY_URL, 'query' => $baseRCQuery );

		print( "Requesting changes from " . API_QUERY_URL . "...($lastTimestamp)\n" );
		list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $http->run( $req );
		try {
			$result = decodeJSON( $rbody );
		} catch ( Exception $e ) {
			trigger_error( "Caught error: {$e->getMessage}" );
			sleep( 5 );
			continue;
		}
		$changeCount = count( $result['query']['recentchanges'] );

		$rccontinue = isset( $result['continue']['rccontinue'] )
			? $result['continue']['rccontinue']
			: null;

		$entitiesChanged = array(); // map of (identifier => id)
		$itemsDeleted = array(); // map of (Item ID => 1)
		$propsDeleted = array(); // map of (Property ID => 1)
		$itemsRestored = array(); // map of (Item ID => 1)
		$propsRestored = array(); // map of (Property ID => 1)
		foreach ( $result['query']['recentchanges'] as $change ) {
			if ( $change['timestamp'] === $lastTimestamp && $change['rcid'] <= $lastRcID ) {
				--$changeCount;
				continue; // this happens on final pages where the API gives no continue=
			}
			$lastTimestamp = $change['timestamp'];
			$lastRcID = $change['rcid'];

			$id = 0;
			if ( $change['ns'] == 0 ) { // Item
				$id = WdqUtils::wdcToLong( $change['title'] );
			} elseif ( $change['ns'] == 120 ) { // Property
				list( $nstext, $key ) = explode( ':', $change['title'], 2 );
				$id = WdqUtils::wdcToLong( $key );
			}

			$logTypeAction = ( $change['type'] === 'log' )
				? "{$change['logtype']}/{$change['logaction']}"
				: null;

			if ( $change['type'] === 'new' || $change['type'] === 'edit' ) {
				print( "{$change['timestamp']} {$change['type']}: {$change['title']}\n" );
				if ( $change['ns'] == 0 ) { // Item
					$entitiesChanged["Q$id"] = $id;
					unset( $itemsDeleted[$id] );
				} elseif ( $change['ns'] == 120 ) { // Property
					$entitiesChanged["P$id"] = $id;
					unset( $propsDeleted[$id] );
				}
			} elseif ( in_array( $logTypeAction,
				array( 'delete/delete', 'move/move', 'move-move_redir' ) )
			) {
				print( "{$change['timestamp']} $logTypeAction: {$change['title']}\n" );
				if ( $change['ns'] == 0 ) { // Item
					$itemsDeleted[$id] = 1;
					unset( $itemsRestored[$id] );
				} elseif ( $change['ns'] == 120 ) { // Property
					$propsDeleted[$id] = 1;
					unset( $propsRestored[$id] );
				}
			} elseif ( $logTypeAction === 'delete/restore' ) {
				print( "{$change['timestamp']} Restored page: {$change['title']}\n" );
				if ( $change['ns'] == 0 ) { // Item
					$itemsRestored[$id] = 1;
					$entitiesChanged["Q$id"] = $id;
				} elseif ( $change['ns'] == 120 ) { // Property
					$propsRestored[$id] = 1;
					$entitiesChanged["P$id"] = $id;
				}
			}
		}

		if ( $changeCount == 0 ) {
			print( "No changes found...\n" );
			sleep( 1 );
			continue;
		}

		$req = array( 'method' => 'GET', 'url' => API_QUERY_URL, 'query' => array(
			'action' => 'wbgetentities', 'ids' => implode( '|', array_keys( $entitiesChanged ) ),
			'redirects' => 'no', 'props' => 'info|sitelinks|labels|claims|datatype',
			'format' => 'json'
		) );

		print( "Requesting corresponding revision content from " . API_QUERY_URL . "...\n" );
		list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $http->run( $req );
		try {
			$result = decodeJSON( $rbody );
		} catch ( Exception $e ) {
			trigger_error( "Caught error: {$e->getMessage}" );
			sleep( 5 );
			continue;
		}

		// Track entities to be created this batch
		$bEntities = array( 'Q' => array(), 'P' => array() ); // id lists
		// Build list entities to be created/updated
		$entityStubs = array( 'Q' => array(), 'P' => array() ); // id lists
		$entitiesUpsert = array(); // list of maps
		foreach ( $entitiesChanged as $identifier => $id ) {
			if ( !isset( $result['entities'][$identifier]['missing'] ) ) {
				$entitiesUpsert[] = $result['entities'][$identifier];
			} else {
				// Use stubs for items that were in RC but not found
				$entityStubs[$identifier[0]][] = $id;
			}

			$bEntities[$identifier[0]][] = $id;
		}
		// Get IDs to make stubs for to fill the gaps.
		// Deletions remove all RC entries for the title, making RC a mutable log.
		// Although new entities never reference entities from the future, they might
		// reference entities created prior that are in the RC gaps due to later deletions.
		if ( $bEntities['Q'] && max( $bEntities['Q'] ) > $maxItemId ) {
			$gaps = array_diff( range( $maxItemId + 1, max( $bEntities['Q'] ) ), $bEntities['Q'] );
			print( "Detected " . count( $gaps ) . " item IDs in gaps\n" );
			$entityStubs['Q'] = array_merge( $entityStubs['Q'], $gaps );
		}
		if ( $bEntities['P'] && max( $bEntities['P'] ) > $maxPropId ) {
			$gaps = array_diff( range( $maxPropId + 1, max( $bEntities['P'] ) ), $bEntities['P'] );
			print( "Detected " . count( $gaps ) . " property IDs in gaps\n" );
			$entityStubs['P'] = array_merge( $entityStubs['P'], $gaps );
		}

		print( "Updating graph [" . count( $entitiesUpsert ) . " change(s)]...\n" );
		// (a) Make the stubs for missing entities first...
		$updater->createDeletedItemStubs( $entityStubs['Q'] );
		$updater->createDeletedPropertyStubs( $entityStubs['P'] );
		// (b) Create/update entities...
		$updater->importEntities( $entitiesUpsert, 'upsert' );
		// (c) Connect the entities with edges...
		foreach ( $entitiesUpsert as &$entity ) {
			// Convert claims to DB form in order to call makeItemEdges()
			if ( isset( $entity['claims'] ) ) {
				$entity['claims'] = $updater->getSimpliedClaims( $entity['claims'] );
			}
		}
		$updater->makeItemEdges( $entitiesUpsert, 'rebuild' );
		// (d) Apply any deletions/restorations (effects vertexes and edges)...
		$updater->deleteEntities( 'Item', array_keys( $itemsDeleted ) );
		$updater->deleteEntities( 'Property', array_keys( $propsDeleted ) );
		$updater->restoreEntities( 'Item', array_keys( $itemsRestored ) );
		$updater->restoreEntities( 'Property', array_keys( $propsRestored ) );

		// Safe to advance...
		$maxItemId = $bEntities['Q'] ? max( $maxItemId, max( $bEntities['Q'] ) ) : $maxItemId;
		$maxPropId = $bEntities['P'] ? max( $maxPropId, max( $bEntities['P'] ) ) : $maxPropId;
		// Update rccontinue/rcstart for next time
		if ( $rccontinue ) {
			$baseRCQuery['rccontinue'] = $rccontinue;
		}
		// Useful when caught up, since continue is not returned by the API
		$baseRCQuery['rcstart'] = $lastTimestamp;

		// Update the replication position
		$rate = round( $changeCount / ( microtime( true ) - $batchStartTime ), 3 );
		$row = array( 'rc_timestamp' => $lastTimestamp, 'name' => 'LastRCInfo' );
		$updater->tryCommand( "UPDATE DBStatus CONTENT " .
			json_encode( $row ) . " WHERE name='LastRCInfo'" );
		print( "Updated replication position to $lastTimestamp ($rate entities/sec).\n" );
	}
}

function getMaxEntityId( WdqUpdater $updater, $class ) {
	$id = 0;

	$res = $updater->tryQuery( "SELECT id FROM $class ORDER BY id DESC LIMIT 1" );
	foreach ( $res as $record ) {
		$id = $record['id'];
	}

	return $id;
}

function getCurrentRCPosition( WdqUpdater $updater ) {
	$cTimestamp = null;

	$res = $updater->tryQuery( "SELECT rc_timestamp FROM DBStatus WHERE name='LastRCInfo'" );
	foreach ( $res as $record ) {
		$cTimestamp = $record['rc_timestamp'];
	}

	return $cTimestamp;
}

function determineRCPosition( WdqUpdater $updater, MultiHttpClient $http ) {
	$cTimestamp = null;

	$res = $updater->tryQuery(
		"SELECT id FROM Item WHERE stub IS NULL ORDER BY id DESC LIMIT 100" );

	foreach ( $res as $record ) {
		$query = array(
			'action' => 'query', 'prop' => 'revisions', 'titles' => "Q{$record['id']}",
			'rvdir'  => 'newer', 'rvlimit' => 1, 'rvprop' => 'timestamp',
			'format' => 'json'
		);
		$req = array( 'method' => 'GET', 'url' => API_QUERY_URL, 'query' => $query );

		print( "Getting creation timestamp of 'Q{$record['id']}' via API.\n" );
		list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $http->run( $req );
		$result = decodeJSON( $rbody );

		foreach ( $result['query']['pages'] as $pageId => $pageInfo ) {
			if ( isset( $pageInfo['revisions'] ) ) { // wasn't deleted
				$timestamp = $pageInfo['revisions'][0]['timestamp'];
				if ( strtotime( $timestamp ) > 0 ) {
					$cTimestamp = $timestamp;
					print( "Setting replication timestamp to $cTimestamp.\n" );
					// Set the initial replication timestamp
					$row = array( 'name' => 'LastRCInfo', 'rc_timestamp' => $cTimestamp );
					$updater->tryCommand(
						"INSERT INTO DBStatus CONTENT " . json_encode( $row ) );
					return $cTimestamp;
				} else {
					die( "Got invalid revision timestamp '$timestamp' from the API.\n" );
				}
			}
		}
	}

	return $cTimestamp;
}

function decodeJSON( $s ) {
	$res = json_decode( $s, true );
	if ( $res === null ) {
		throw new Exception( "Could not decode: $s" );
	}
	return $res;
}

# Begin execution
main();
