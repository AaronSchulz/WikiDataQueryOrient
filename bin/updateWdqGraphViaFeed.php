<?php

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
	// items due to the hard-coded server-size limit of 20 revisions per query.
	$baseQuery = array(
		'action' => 'query', 'list' => 'recentchanges',
		'rcnamespace' => '0|120', 'rctype' => 'log|edit|new',
		'rcdir' => 'newer', 'rclimit' => 15, 'rcprop' => 'loginfo|ids|title|timestamp',
		'format' => 'json', 'continue' => '', 'rcstart' => $sTimestamp
	);

	$rccontinue = null;
	$lastTimestamp = $sTimestamp;
	while ( true ) {
		if ( $rccontinue ) {
			$baseQuery['rccontinue'] = $rccontinue;
		}
		$req = array( 'method' => 'GET', 'url' => API_QUERY_URL, 'query' => $baseQuery );

		print( "Requesting changes from " . API_QUERY_URL . "...($rccontinue)\n" );
		list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $http->run( $req );
		$result = json_decode( $rbody, true );

		$rccontinue = $result['continue']['rccontinue'];

		$changeCount = count( $result['query']['recentchanges'] );
		$itemsDeleted = array(); // list of Item IDs
		$propertiesDeleted = array(); // list of Property IDs
		$titlesChanged = array(); // rev ID => (ns,title,is new)
		foreach ( $result['query']['recentchanges'] as $change ) {
			$lastTimestamp = $change['timestamp'];
			$logTypeAction = ( $change['type'] === 'log' )
				? "{$change['logtype']}/{$change['logaction']}"
				: null;
			if ( $change['type'] === 'new' ) {
				print( "{$change['timestamp']} New page: {$change['title']}\n" );
				$titlesChanged[$change['revid']] = array( $change['ns'], $change['title'], true );
			} elseif ( $change['type'] === 'edit' ) {
				print( "{$change['timestamp']} Modified page: {$change['title']}\n" );
				$titlesChanged[$change['revid']] = array( $change['ns'], $change['title'], false );
			} elseif ( $logTypeAction === 'delete/delete' ) {
				print( "{$change['timestamp']} Deleted page: {$change['title']}\n" );
				if ( $change['ns'] == 0 ) { // Item
					$id = WdqUtils::wdcToLong( $change['title'] );
					$itemsDeleted[] = $id;
				} elseif ( $change['ns'] == 120 ) { // Property
					list( $nstext, $key ) = explode( ':', $change['title'], 2 );
					$id = WdqUtils::wdcToLong( $key );
					$propertiesDeleted[] = $id;
				}
			} elseif ( $logTypeAction === 'delete/restore' ) {
				print( "{$change['timestamp']} Restored page: {$change['title']}\n" );
				$revId = getRevIdAtTimestamp( $change['title'], $change['timestamp'], $http );
				if ( $revId ) { // not deleted/moved again?
					$titlesChanged[$change['revid']] =
						array( $change['ns'], $change['title'], true );
				}
			} elseif ( in_array( $logTypeAction, array( 'move/move', 'move-move_redir' ) ) ) {
				print( "{$change['timestamp']} Moved page: {$change['title']}\n" );
				if ( $change['ns'] == 0 ) { // Item
					$id = WdqUtils::wdcToLong( $change['title'] );
					$itemsDeleted[] = $id;
				} elseif ( $change['ns'] == 120 ) { // Property
					list( $nstext, $key ) = explode( ':', $change['title'], 2 );
					$id = WdqUtils::wdcToLong( $key );
					$propertiesDeleted[] = $id;
				}
			}
		}

		$req = array(
			'method' => 'GET', 'url' => API_QUERY_URL, 'query' => array(
			'action' => 'query', 'revids' => implode( '|', array_keys( $titlesChanged ) ),
			'prop' => 'revisions', 'rvprop' => 'ids|content', 'format' => 'json'
		) );

		print( "Requesting corresponding revision content from " . API_QUERY_URL . "...\n" );
		list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $http->run( $req );
		$result = json_decode( $rbody, true );

		$applyChanges = array(); // map of rev ID => (json, is new)
		foreach ( $result['query']['pages'] as $pageId => $pageInfo ) {
			$change = $pageInfo['revisions'][0];
			list( $ns, $title, $isNew ) = $titlesChanged[$change['revid']];
			if ( $ns == 0 ) { // Item
				$applyChanges[$change['revid']] = array( $change['*'], $isNew );
			} elseif ( $ns == 120 ) { // Property
				list( $nstext, $key ) = explode( ':', $title, 2 );
				$applyChanges[$change['revid']] = array( $change['*'], $isNew );
			}
		}
		$applyChangesInOrder = array(); // order should match $titlesChanged
		foreach ( $titlesChanged as $revId => $change ) {
			if ( isset( $applyChanges[$revId] ) ) {
				$applyChangesInOrder[$revId] = $applyChanges[$revId];
			}
		}

		$n = count( $applyChangesInOrder );
		print( "Updating graph [$n change(s)]...\n" );
		foreach ( $applyChangesInOrder as $change ) {
			list( $json, $isNew ) = $change;
			$entity = json_decode( $json, true );
			if ( isset( $entity['entity'] ) && isset( $entity['redirect'] ) ) {
				print( "Ignored entity redirect: $json\n" );
			} elseif ( $entity['type'] === 'item' ) {
				$updater->importEntities( array( $entity ), $isNew ? 'insert' : 'update' );
				$updater->makeEntityEdges( array( $entity ), 'rebuild' );
			} elseif ( $entity['type'] === 'property' ) {
				$updater->importEntities( array( $entity ), $isNew ? 'insert' : 'update' );
			} else {
				throw new Exception( "Got unkown item '$json'" );
			}
		}
		if ( $itemsDeleted ) {
			$updater->deleteItemVertexes( $itemsDeleted );
		}
		if ( $propertiesDeleted ) {
			$updater->deletePropertyVertexes( $itemsDeleted );
		}

		// Update the replication position
		if ( $changeCount > 0 ) {
			$row = array( 'rc_timestamp' => $lastTimestamp, 'name' => 'LastRCInfo' );
			$updater->tryCommand(
				"UPDATE DBStatus CONTENT " . json_encode( $row ) . " WHERE name='LastRCInfo'" );
			print( "Updated replication position to $lastTimestamp.\n" );
		} else {
			print( "No changes found...\n" );
			sleep( 1 );
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

	$res = $updater->tryQuery( "SELECT id FROM Item ORDER BY id DESC LIMIT 100" );

	foreach ( $res as $record ) {
		$query = array(
			'action' => 'query', 'prop' => 'revisions', 'titles' => "Q{$record['id']}",
			'rvdir'  => 'newer', 'rvlimit' => 1, 'rvprop' => 'timestamp',
			'format' => 'json'
		);
		$req = array( 'method' => 'GET', 'url' => API_QUERY_URL, 'query' => $query );

		print( "Getting creation timestamp of 'Q{$record['id']}' via API.\n" );
		list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $http->run( $req );
		$result = json_decode( $rbody, true );

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

function getRevIdAtTimestamp( $title, $timestamp, MultiHttpClient $http ) {
	$revId = null;

	$query = array(
		'action' => 'query', 'prop' => 'revisions', 'titles' => $title,
		'rvdir'  => 'older', 'rvprop' => 'ids', 'rvstart' => $timestamp, 'rvlimit' => 1,
		'format' => 'json'
	);
	$req = array( 'method' => 'GET', 'url' => API_QUERY_URL, 'query' => $query );

	print( "Getting newest revision before '$timestamp' for '$title' via API.\n" );
	list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $http->run( $req );
	$result = json_decode( $rbody, true );

	foreach ( $result['query']['pages'] as $pageId => $pageInfo ) {
		if ( isset( $pageInfo['revisions'] ) ) { // wasn't deleted
			if ( !isset( $pageInfo['revisions'][0]['revid'] ) ) {
				die( "Expected 'revid' field from API response!\n" );
			}
			$revId = $pageInfo['revisions'][0]['revid'];
		}
	}

	return $revId;
}

# Begin execution
main();
