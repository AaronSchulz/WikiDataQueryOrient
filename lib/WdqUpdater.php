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
	 * See http://www.mediawiki.org/wiki/Wikibase/DataModel/Primer
	 *
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
		$labels = array(); // map of (<language> => <label>)
		// Flatten labels to a 1-level list for querying
		if ( isset( $item['labels'] ) ) {
			foreach ( $item['labels'] as $lang => $label ) {
				$labels[$lang] = $label['value'];
			}
		}
		// Include the claims for JSON document query filtering
		$claims = array();
		if ( isset( $item['claims'] ) ) {
			foreach ( $item['claims'] as $propertyId => $statements ) {
				foreach ( $statements as $statement ) {
					$id = $statement['id'];
					// Remove redundant or useless field to save space
					unset( $statement['id'] ); // used as key
					unset( $statement['type'] ); // always "statement"
					unset( $statement['references'] ); // unused
					unset( $statement['mainsnak']['property'] );
					unset( $statement['mainsnak']['hash'] );
					// Use the cleaned up statement
					$claims[$propertyId][$id] = $statement;
				}
			}
		}
		// Include the property IDs (pids) referenced for tracking
		$coreItem = array(
			'id'        => WdqUtils::wdcToLong( $item['id'] ),
			'labels'    => $labels ? $labels : (object)array(),
			'sitelinks' => $siteLinks ? $siteLinks : (object)array(),
			'claims'    => $claims,
		) + $this->getReferenceIdSet( $claims );

		if ( $update === 'update' || $update === 'upsert' ) {
			// Don't use CONTENT; https://github.com/orientechnologies/orientdb/issues/3176
			$set = array();
			foreach ( $coreItem as $key => $value ) {
				if ( $key === 'id' ) { // PK
					continue;
				} elseif ( is_array( $value ) ) {
					$set[] = "$key=" . WdqUtils::toJSON( $value );
				} else {
					$set[] = "$key='" . addcslashes( $value, "'" ) . "'";
				}
			}
			$set = implode( ',', $set );
			$this->tryCommand( "update Item set $set where id={$coreItem['id']}" );
		}

		if ( $update === 'insert' || $update === 'upsert' ) {
			$this->tryCommand( 'create vertex Item content ' . WdqUtils::toJSON( $coreItem ) );
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
			$refs['pids'][] = (float)$pid;
			foreach ( $statements as $statement ) {
				$mainSnak = $statement['mainsnak'];
				if ( $mainSnak['snaktype'] === 'value' &&
					$mainSnak['datavalue']['type'] === 'wikibase-entityid'
				) {
					$refs['iids'][] = (float)$mainSnak['datavalue']['value']['numeric-id'];
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
			// Don't use CONTENT; https://github.com/orientechnologies/orientdb/issues/3176
			$set = array();
			foreach ( $coreItem as $key => $value ) {
				if ( $key === 'id' ) { // PK
					continue;
				} elseif ( is_array( $value ) ) {
					$set[] = "$key=" . WdqUtils::toJSON( $value );
				} else {
					$set[] = "$key='" . addcslashes( $value, "'" ) . "'";
				}
			}
			$set = implode( ',', $set );
			$this->tryCommand( "update Property set $set where id='{$coreItem['id']}'" );
		}

		if ( $update === 'insert' || $update === 'upsert' ) {
			$this->tryCommand( "create vertex Property content " . WdqUtils::toJSON( $coreItem ) );
		}
	}

	/**
	 * See http://www.mediawiki.org/wiki/Wikibase/DataModel
	 * See https://www.wikidata.org/wiki/Wikidata:Glossary
	 *
	 * @param array $item
	 * @param string $method (rebuild/bulk)
	 * @param array|null $classes Only do certain edge classes
	 */
	public function importItemPropertyEdges( array $item, $method, array $classes = null ) {
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
				$mainSnak = $statement['mainsnak'];
				if ( $mainSnak['snaktype'] === 'value' ) {
					$edges = $this->getValueStatementEdges( $qId, $pId, $mainSnak );
				} elseif ( $mainSnak['snaktype'] === 'somevalue' ) {
					$edges[] = array(
						'class'   => 'HPwSomeV',
						'oid'     => $qId,
						'iid'     => $pId,
						'toClass' => 'Property'
					);
				} elseif ( $mainSnak['snaktype'] === 'novalue' ) {
					$edges[] = array(
						'class'   => 'HPwNoV',
						'oid'     => $qId,
						'iid'     => $pId,
						'toClass' => 'Property'
					);
				} else {
					$edges = array();
				}

				// https://www.wikidata.org/wiki/Help:Ranking
				foreach ( $edges as $edge ) {
					$edge['rank'] = $rankMap[$statement['rank']];
					$edge['best'] = $edge['rank'] >= $maxRankByPid[$pId] ? 1 : 0;
					$edge['sid'] = $statement['id'];
				}

				$dvEdges = array_merge( $dvEdges, $edges );
			}
		}

		// Destroy all prior outgoing edges
		if ( $method !== 'bulk_init' ) {
			$this->deleteItemPropertyEdges( $qId, $classes );
		}

		$sqlQueries = array();
		// Create all of the new outgoing edges
		foreach ( $dvEdges as $dvEdge ) {
			if ( $classes && !in_array( $dvEdge['class'], $classes ) ) {
				continue; // skip this edge class
			}
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
	protected function getValueStatementEdges( $qId, $pId, array $mainSnak ) {
		$dvEdges = array();

		$type = $mainSnak['datavalue']['type'];
		if ( $type === 'wikibase-entityid' ) {
			$otherId = $mainSnak['datavalue']['value']['numeric-id'];
			$dvEdges[] = array(
				'class'   => 'HPwIV',
				'val'     => $otherId,
				'oid'     => $qId,
				'iid'     => $pId,
				'toClass' => 'Property'
			);
			$dvEdges[] = array(
				'class'   => 'HIaPV',
				'pid'     => $pId,
				'oid'     => $qId,
				'iid'     => $otherId,
				'toClass' => 'Item'
			);
		} elseif ( $type === 'time' ) {
			$time = $mainSnak['datavalue']['value']['time'];
			$tsUnix = WdqUtils::getUnixTimeFromISO8601( $time ); // for range queries
			if ( $tsUnix !== false ) {
				$dvEdges[] = array(
					'class'   => 'HPwTV',
					'val'     => $tsUnix,
					'oid'     => $qId,
					'iid'     => $pId,
					'toClass' => 'Property'
				);
			}
		} elseif ( $type === 'quantity' ) {
			$amount = $mainSnak['datavalue']['value']['amount']; // decimals
			$dvEdges[] = array(
				'class'   => 'HPwQV',
				'val'     => (float) $amount,
				'oid'     => $qId,
				'iid'     => $pId,
				'toClass' => 'Property'
			);
		} elseif ( $type === 'globecoordinate' ) {
			$dvEdges[] = WdqUtils::normalizeGeoCoordinates( array(
				'class'   => 'HPwCV',
				'lat'     => (float) $mainSnak['datavalue']['value']['latitude'],
				'lon'     => (float) $mainSnak['datavalue']['value']['longitude'],
				'oid'     => $qId,
				'iid'     => $pId,
				'toClass' => 'Property'
			) );
		} elseif ( $type === 'url' || $type === 'string' ) {
			$dvEdges[] = array(
				'class'   => 'HPwSV',
				'val'     => (string) $mainSnak['datavalue']['value'],
				'oid'     => $qId,
				'iid'     => $pId,
				'toClass' => 'Property'
			);
		}

		return $dvEdges;
	}

	/**
	 * @param string|int|array $ids 64-bit integers
	 */
	public function deleteItemVertexes( $ids ) {
		// https://github.com/orientechnologies/orientdb/issues/3150
		$orClause = array();
		foreach ( (array)$ids as $id ) {
			$orClause[] = "id='$id'";
		}
		$orClause = implode( ' OR ', $orClause );
		$this->tryCommand( "delete vertex Item where ($orClause)" );
	}

	/**
	 * @param string|int|array $ids 64-bit integers
	 */
	public function deletePropertyVertexes( $ids ) {
		// https://github.com/orientechnologies/orientdb/issues/3150
		$orClause = array();
		foreach ( (array)$ids as $id ) {
			$orClause[] = "id='$id'";
		}
		$orClause = implode( ' OR ', $orClause );
		$this->tryCommand( "delete vertex Property where ($orClause)" );
	}

	/**
	 * Delete all outgound edges from an Item
	 *
	 * @param string|in $id 64-bit integer
	 * @param array|null $classes Only delete classes of these types if set
	 */
	public function deleteItemPropertyEdges( $id, array $classes = null ) {
		if ( is_array( $classes ) && !$classes ) {
			return; // nothing to do
		}
		$sql = "delete edge from (select from Item where id=$id)";
		if ( $classes ) {
			$sql .= ' where @class in [' . implode( ',', $classes ) . ']';
		}
		$this->tryCommand( $sql );
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
