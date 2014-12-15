<?php

if ( !class_exists( 'OrientDB' ) ) {
	die( "Missing PHP OrientDB library." );
}

class WdqUpdater {
	/** @var OrientDB */
	protected $db;

	/**
	 * @param OrientDB $client
	 */
	public function __construct( OrientDB $client ) {
		$this->db = $client;
	}

	/**
	 * @param array $item
	 * @param string $update (update/insert/upsert)
	 */
	public function importItemVertex( array $item, $update ) {
		$siteLinks = array(); // map of (<site> => <site>#<title>)
		// Flatten site links to a 1-level list for indexing
		if ( isset( $item['sitelinks'] ) ) {
			foreach ( $item['sitelinks'] as $site => $link ) {
				$siteLinks[$site] = $link['site'] . '#' . $link['title'];
			}
		}
		// Include the claims for JSON document query filtering
		$claims = array();
		if ( isset( $item['claims'] ) ) {
			foreach ( $item['claims'] as $propertyId => $statements ) {
				foreach ( $statements as $statement ) {
					$claims[$propertyId][$statement['id']] = $statement;
				}
			}
		}
		// Include the property IDs (pids) referenced for tracking
		$coreItem = array(
			'id'        => WdqUtils::wdcToLong( $item['id'] ),
			'claims'    => $claims,
			'sitelinks' => $siteLinks ? $siteLinks : (object)array()
		) + $this->getReferenceIdSet( $claims );

		if ( $update === 'update' || $update === 'upsert' ) {
			$this->tryCommand( "update Item content " .
				WdqUtils::toJSON( $coreItem ) . " where id='{$coreItem['id']}'" );
		}

		if ( $update === 'insert' || $update === 'upsert' ) {
			$this->tryCommand( 'create vertex Item content ' .
				WdqUtils::toJSON( $coreItem ) );
		}
	}

	/**
	 * Get IDs of items and properties refered to by $claims
	 *
	 * @param array $claims
	 * @return array
	 */
	protected function getReferenceIdSet( array $claims ) {
		$refs = array( 'pids' => array(), 'iids' => array() );

		foreach ( $claims as $propertyId => $statements ) {
			$pid = WdqUtils::wdcToLong( $propertyId );
			$refs['pids'][] = $pid;
			foreach ( $statements as $statement ) {
				$mainSnak = $statement['mainsnak'];
				if ( $mainSnak['snaktype'] === 'value' &&
					$mainSnak['datavalue']['type'] === 'wikibase-entityid'
				) {
					$refs['iids'][] = $mainSnak['datavalue']['value']['numeric-id'];
				}
			}
		}

		// Embedded sets do not allow duplicates
		$refs['iids'] = array_values( array_unique( $refs['iids'] ) );

		return $refs;
	}

	/**
	 * @param array $item
	 * @param string $update (insert/update/upsert)
	 */
	public function importPropertyVertex( array $item, $update ) {
		$coreItem = array(
			'id'       => WdqUtils::wdcToLong( $item['id'] ),
			'datatype' => $item['datatype']
		);

		if ( $update === 'update' || $update === 'upsert' ) {
			$this->tryCommand( "update Property content " .
				WdqUtils::toJSON( $coreItem ) . " where id='{$coreItem['id']}'" );
		}

		if ( $update === 'insert' || $update === 'upsert' ) {
			$this->tryCommand( "create vertex Property content " .
				WdqUtils::toJSON( $coreItem ) );
		}
	}

	/**
	 * See http://www.mediawiki.org/wiki/Wikibase/DataModel
	 * See https://www.wikidata.org/wiki/Wikidata:Glossary
	 *
	 * @param array $item
	 * @param string $method (rebuild/bulk)
	 */
	public function importItemPropertyEdges( array $item, $method ) {
		if ( !isset( $item['claims'] ) ) {
			return; // nothing to do
		}

		// ENUM map for short integer field
		static $rankMap = array(
			'preferred'  => 1,
			'normal'     => 0,
			'deprecated' => -1
		);

		$qId = WdqUtils::wdcToLong( $item['id'] );

		$maxRankByPid = array(); // map of (pid => rank)
		foreach ( $item['claims'] as $propertyId => $statements ) {
			$pId = WdqUtils::wdcToLong( $propertyId );
			foreach ( $statements as $statement ) {
				$rank = $rankMap[$statement['rank']];
				$maxRankByPid[$pId] = isset( $maxRankByPid[$pId] )
					? max( $maxRankByPid[$pId], $rank )
					: $rank;
			}
		}

		$dvEdges = array(); // list of data value statements (maps with class/val/rank)
		foreach ( $item['claims'] as $propertyId => $statements ) {
			$pId = WdqUtils::wdcToLong( $propertyId );
			foreach ( $statements as $statement ) {
				$dvEdge = false;

				$mainSnak = $statement['mainsnak'];
				if ( $mainSnak['snaktype'] === 'value' ) {
					$dvEdge = $this->getValueStatementEdge( $qId, $pId, $mainSnak );
					if ( $dvEdge ) {
						// https://www.wikidata.org/wiki/Help:Ranking
						$dvEdge['rank'] = $rankMap[$statement['rank']];
						$dvEdge['best'] = $dvEdge['rank'] >= $maxRankByPid[$pId] ? 1 : 0;
						$dvEdge['sid'] = $statement['id'];
					}
				} elseif ( $mainSnak['snaktype'] === 'somevalue' ) {
					$dvEdge = array(
						'class'   => 'HPwSomeV',
						'oid'     => $qId,
						'iid'     => $pId,
						'toClass' => 'Property',
						'rank'    => $rankMap[$statement['rank']],
						'best'    => $rankMap[$statement['rank']] >= $maxRankByPid[$pId] ? 1 : 0,
						'sid'     => $statement['id']
					);
				} elseif ( $mainSnak['snaktype'] === 'novalue' ) {
					$dvEdge = array(
						'class'   => 'HPwNoV',
						'oid'     => $qId,
						'iid'     => $pId,
						'toClass' => 'Property',
						'rank'    => $rankMap[$statement['rank']],
						'best'    => $rankMap[$statement['rank']] >= $maxRankByPid[$pId] ? 1 : 0,
						'sid'     => $statement['id']
					);
				}

				if ( $dvEdge ) {
					$dvEdges[] = $dvEdge;
					$dvEdges[] = array(
						'class'   => 'HP',
						'oid'     => $qId,
						'iid'     => $pId,
						'toClass' => 'Property',
						'rank'    => $rankMap[$statement['rank']],
						'best'    => $rankMap[$statement['rank']] >= $maxRankByPid[$pId] ? 1 : 0,
						'sid'     => $statement['id']
					);
				}
			}
		}

		// Destroy all prior outgoing edges
		if ( $method !== 'bulk_init' ) {
			$this->deleteItemPropertyEdges( $qId );
		}

		$sqlQueries = array();
		// Create all of the new outgoing edges
		foreach ( $dvEdges as $dvEdge ) {
			$class = $dvEdge['class'];
			unset( $dvEdge['class'] );
			$toClass = $dvEdge['toClass'];
			unset( $dvEdge['toClass'] );
			$sqlQueries[] =
				"create edge $class " .
				"from (select from Item where id='$qId') " .
				"to (select from $toClass where id='{$dvEdge['iid']}') content " .
				WdqUtils::toJSON( $dvEdge );
		}
		// @TODO: batch?
		foreach ( $sqlQueries as $sqlQuery ) {
			$this->tryCommand( $sqlQuery );
		}
	}

	/**
	 * See http://www.wikidata.org/wiki/Special:ListDatatypes
	 *
	 * @param integer $qId 64-bit integer
	 * @param integer $pId 64-bit integer
	 * @param array $mainSnak
	 * @return array
	 */
	protected function getValueStatementEdge( $qId, $pId, array $mainSnak ) {
		$dvEdge = null;

		$type = $mainSnak['datavalue']['type'];
		if ( $type === 'wikibase-entityid' ) {
			$otherId = $mainSnak['datavalue']['value']['numeric-id'];
			$dvEdge = array(
				'class'   => 'HPwIV',
				'pid'     => $pId,
				'oid'     => $qId,
				'iid'     => $otherId,
				'toClass' => 'Item'
			);
		} elseif ( $type === 'time' ) {
			$time = $mainSnak['datavalue']['value']['time'];
			$tsUnix = WdqUtils::getUnixTimeFromISO8601( $time ); // for range queries
			if ( $tsUnix !== false ) {
				$dvEdge = array(
					'class'   => 'HPwTV',
					'val'     => $tsUnix,
					'oid'     => $qId,
					'iid'     => $pId,
					'toClass' => 'Property'
				);
			}
		} elseif ( $type === 'quantity' ) {
			$amount = $mainSnak['datavalue']['value']['amount']; // decimals
			$dvEdge = array(
				'class'   => 'HPwQV',
				'val'     => (float) $amount,
				'oid'     => $qId,
				'iid'     => $pId,
				'toClass' => 'Property'
			);
		} elseif ( $type === 'globecoordinate' ) {
			$dvEdge = WdqUtils::normalizeGeoCoordinates( array(
				'class'   => 'HPwCV',
				'lat'     => (float) $mainSnak['datavalue']['value']['latitude'],
				'lon'     => (float) $mainSnak['datavalue']['value']['longitude'],
				'oid'     => $qId,
				'iid'     => $pId,
				'toClass' => 'Property'
			) );
		} elseif ( $type === 'url' || $type === 'string' ) {
			$dvEdge = array(
				'class'   => 'HPwSV',
				'val'     => (string) $mainSnak['datavalue']['value'],
				'oid'     => $qId,
				'iid'     => $pId,
				'toClass' => 'Property'
			);
		}

		return $dvEdge;
	}

	/**
	 * @param string|int|array $ids 64-bit integers
	 */
	public function deleteItemVertexes( $ids ) {
		$ids = (array)$ids;
		$this->tryCommand( "delete vertex Item where id in(" . implode( ',', $ids ) . ")" );
	}

	/**
	 * @param string|int|array $ids 64-bit integers
	 */
	public function deletePropertyVertexes( $ids ) {
		$ids = (array)$ids;
		$this->tryCommand( "delete vertex Property where id in(" . implode( ',', $ids ) . ")" );
	}

	/**
	 * Delete all outgound edges from an Item
	 *
	 * @param string|in $id 64-bit integer
	 */
	public function deleteItemPropertyEdges( $id ) {
		// https://github.com/orientechnologies/orientdb/issues/3185
		#$this->tryCommand( "delete edge from (select from Item where id=$id)" );
		$rid = null;
		$res = $this->tryCommand( "select @RID from Item where id=$id" );
		foreach ( $res as $record ) {
			$rid = $record->data->RID->getHash();
		}
		if ( $rid !== null ) {
			$this->tryCommand( "delete edge from $rid to (select expand(out()) from $rid)" );
		}
	}

	/**
	 * @param string $command
	 * @param bool $ignore_dups
	 * @return mixed
	 */
	protected function tryCommand( $command, $ignore_dups = true ) {
		$res = null;
		for ( $attempts = 1; $attempts <= 10; ++$attempts ) {
			try {
				$res = $this->db->command( OrientDB::COMMAND_QUERY, $command );
			} catch ( OrientDBException $e ) {
				$message = $e->getMessage();
				if ( strpos( $message, 'OConcurrentModificationException' ) !== false ) {
					continue; // retry
				} elseif ( $ignore_dups
					&& strpos( $message, 'ORecordDuplicatedException' ) !== false
				) {
					// ignore the error
				} else {
					print( "Error on attempted command:\n$command\n\n" );
					throw $e;
				}
			}
			break;
		}
		return $res;
	}
}
