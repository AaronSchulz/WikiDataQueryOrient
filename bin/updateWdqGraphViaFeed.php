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

	$lastTimestamp = $sTimestamp;
	while ( true ) {
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
			$lastTimestamp = $change['timestamp'];

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

		// Build list entities to be created/updated
		$newItemStubs = array(); // list of IDs
		$newPropertyStubs = array(); // list of IDs
		$entitiesUpsert = array();
		foreach ( $entitiesChanged as $identifier => $id ) {
			if ( !isset( $result['entities'][$identifier]['missing'] ) ) {
				$entitiesUpsert[] = $result['entities'][$identifier];
			} elseif ( $identifier[0] === 'Q' ) {
				$newItemStubs[] = $id;
			} elseif ( $identifier[0] === 'P' ) {
				$newPropertyStubs[] = $id;
			}
		}

		print( "Updating graph [" . count( $entitiesUpsert ) . " change(s)]...\n" );
		// (a) Make the stubs for missing entities first...
		$updater->createDeletedItemStubs( $newItemStubs );
		$updater->createDeletedPropertyStubs( $newPropertyStubs );
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

		// Safe to advance, so update rccontinue/rcstart for next time
		if ( $rccontinue ) {
			$baseRCQuery['rccontinue'] = $rccontinue;
			// Useful when caught up, since continue is not returned by the API
			$baseRCQuery['rcstart'] = $lastTimestamp;
		}

		// Update the replication position
		if ( $changeCount > 0 ) {
			$row = array( 'rc_timestamp' => $lastTimestamp, 'name' => 'LastRCInfo' );
			$updater->tryCommand(
				"UPDATE DBStatus CONTENT " . json_encode( $row ) . " WHERE name='LastRCInfo'" );
			print( "Updated replication position to $lastTimestamp.\n" );
		} else {
			print( "No changes found...\n" );
			sleep( 5 );
		}
	}
}

function getCurrentRCPosition( WdqUpdater $updater ) {
	$cTimestamp = null;

	$res = $updater->tryQuery(
		"SELECT rc_id,rc_timestamp FROM DBStatus WHERE name='LastRCInfo'" );

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
