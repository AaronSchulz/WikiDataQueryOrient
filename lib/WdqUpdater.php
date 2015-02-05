<?php

class WdqUpdater {
	/** @var MultiHttpClient */
	protected $http;

	/** @var string */
	protected $url;
	/** @var string */
	protected $user;
	/** @var string */
	protected $password;

	/** @var string */
	protected $sessionId;

	/** @var array Map of (id => #rid) */
	protected $pCache = array();
	/** @var MapCacheLRU */
	protected $iCache;
	/** @var MapCacheLRU */
	protected $iHitCache;

	const ICACHE_SIZE = 10000;

	/** @var array ENUM map for short integer field */
	protected static $rankMap = array(
		'preferred'  => 1,
		'normal'     => 0,
		'deprecated' => -1
	);

	/**
	 * @param MultiHttpClient $http
	 * @param array $auth
	 */
	public function __construct( MultiHttpClient $http, array $auth ) {
		$this->http = $http;
		$this->url = $auth['url'];
		$this->user = $auth['user'];
		$this->password = $auth['password'];

		$this->iHitCache = new MapCacheLRU( 20 );
		$this->iCache = new MapCacheLRU( self::ICACHE_SIZE );
	}

	/**
	 * See http://www.mediawiki.org/wiki/Wikibase/DataModel/Primer
	 *
	 * @param array $entities Later duplicates take precedence
	 * @param string $method (insert/upsert)
	 * @throws Exception
	 */
	public function importEntities( array $entities, $method ) {
		if ( count( $entities ) > self::ICACHE_SIZE ) {
			throw new Exception( "More than " . self::ICACHE_SIZE . " entities." );
		}

		if ( $method === 'upsert' ) {
			$iids = $pids = array();
			foreach ( $entities as $entity ) {
				if ( $entity['type'] === 'item' ) {
					$iids[] = WdqUtils::wdcToLong( $entity['id'] );
				} else {
					$pids[] = WdqUtils::wdcToLong( $entity['id'] );
				}
			}
			$this->updateItemRIDCache( $iids );
			$this->updatePropertyRIDCache( $pids );
		}

		$queries = array();
		$willExists = array(); // map of (type:id => true)
		foreach ( $entities as $entity ) {
			if ( $method === 'upsert' ) {
				$id = WdqUtils::wdcToLong( $entity['id'] );
				$key = $entity['type'] . ":$id";
				if ( isset( $willExists[$key] ) ) {
					$opMethod = 'update';
				} elseif ( $entity['type'] === 'item' && $this->iCache->has( $id ) ) {
					$opMethod = 'update';
				} elseif ( $entity['type'] === 'property' && isset( $this->pCache[$id] ) ) {
					$opMethod = 'update';
				} else {
					$opMethod = 'insert';
				}
				$willExists[$key] = true;
			} else {
				$opMethod = $method;
			}

			if ( $entity['type'] === 'item' ) {
				$queries[] = $this->importItemVertexSQL( $entity, $opMethod );
			} elseif ( $entity['type'] === 'property' ) {
				$queries[] = $this->importPropertyVertexSQL( $entity, $opMethod );
			} else {
				throw new Exception( "Unrecognized type:\n" . json_encode( $entity ) );
			}
		}

		$this->tryCommand( $queries, false, true );
	}

	/**
	 * See http://www.mediawiki.org/wiki/Wikibase/DataModel/Primer
	 *
	 * @param array $item
	 * @param string $update (update/insert/upsert)
	 * @return string
	 */
	protected function importItemVertexSQL( array $item, $update ) {
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

		$id = WdqUtils::wdcToLong( $item['id'] );
		if ( $id <= 0 ) {
			throw new Exception( "Bad entity ID: $id" );
		}

		$coreItem = array(
			'id'        => $id,
			'labels'    => $labels ? (object)$labels : (object)array(),
			'sitelinks' => $siteLinks ? (object)$siteLinks : (object)array(),
			'claims'    => isset( $item['claims'] )
				// Include simplified claims for easy filtering/selecting
				? (object)$this->getSimpliedClaims( $item['claims'] )
				: (object)array(),
			'deleted'   => null,
			'stub'      => null
		);

		if ( $update === 'insert' ) {
			return "create vertex Item content " . json_encode( $coreItem );
		} elseif ( $update === 'update' ) {
			$set = $this->sqlSet( $coreItem );
			return "update Item set $set where id={$coreItem['id']}";
		}

		throw new Exception( "Bad method '$update'." );
	}

	/**
	 * Get a streamlined version of $claims
	 *
	 * @param array $claims
	 * @return array
	 */
	public function getSimpliedClaims( array $claims ) {
		$sClaims = array();

		foreach ( $claims as $propertyId => $statements ) {
			$pId = WdqUtils::wdcToLong( $propertyId );

			$sClaims[$pId] = array();

			// http://www.wikidata.org/wiki/Help:Ranking
			$maxRank = -1; // highest statement rank for property
			foreach ( $statements as $statement ) {
				$maxRank = max( $maxRank, self::$rankMap[$statement['rank']] );
			}

			foreach ( $statements as $statement ) {
				$sClaim = $this->getSimpleSnak( $statement['mainsnak'] );
				$sClaim['rank'] = self::$rankMap[$statement['rank']];
				$sClaim['best'] = self::$rankMap[$statement['rank']] >= $maxRank ? 1 : 0;
				$sClaim['sid'] = $statement['id'];
				if ( isset( $statement['qualifiers'] ) ) {
					$sClaim['qlfrs'] = $this->getSimpleQualifiers( $statement['qualifiers'] );
				}
				if ( isset( $statement['references'] ) ) {
					$sClaim['refs'] = $this->getSimpleReferences( $statement['references'] );
				}

				$sClaims[$pId][] = $sClaim;
			}

			// Sort the statements by descending rank
			usort( $sClaims[$pId], function( $a, $b ) {
				if ( $a['rank'] == $b['rank'] ) {
					return 0;
				}
				return ( $a['rank'] > $b['rank'] ) ? -1 : 1;
			} );
		}

		return $sClaims;
	}

	/**
	 * Get a streamlined snak array
	 *
	 * @param array $snak
	 * @return array
	 */
	protected function getSimpleSnak( array $snak ) {
		$simpleSnak = array( 'snaktype' => $snak['snaktype'] );

		if ( $snak['snaktype'] === 'value' ) {
			$valueType = $snak['datavalue']['type'];

			$dataValue = null;
			if ( $valueType === 'wikibase-entityid' ) {
				$dataValue = (int) $snak['datavalue']['value']['numeric-id'];
			} elseif ( $valueType === 'time' ) {
				$dataValue = $snak['datavalue']['value']['time'];
			} elseif ( $valueType === 'quantity' ) {
				$dataValue = (float) $snak['datavalue']['value']['amount'];
			} elseif ( $valueType === 'globecoordinate' ) {
				$dataValue = array(
					'lat' => $snak['datavalue']['value']['latitude'],
					'lon' => $snak['datavalue']['value']['longitude']
				);
			} elseif ( $valueType === 'url' || $valueType === 'string' ) {
				$dataValue = (string) $snak['datavalue']['value'];
			}

			$simpleSnak['datavalue'] = $dataValue;
			if ( $valueType === 'wikibase-entityid' ) { // simplify
				$simpleSnak['valuetype'] = 'wikibase-' . $snak['datavalue']['value']['entity-type'];
			} else {
				$simpleSnak['valuetype'] = $valueType;
			}
		}

		return $simpleSnak;
	}

	/**
	 * Get a streamlined qualifier array
	 *
	 * @param array $qualifiers
	 * @return array
	 */
	protected function getSimpleQualifiers( array $qualifiers ) {
		$simpeQlfrs = array();

		foreach ( $qualifiers as $propertyId => $snaks ) {
			$pId = WdqUtils::wdcToLong( $propertyId );
			$simpeQlfrs[$pId] = array();
			foreach ( $snaks as $snak ) {
				$simpeQlfrs[$pId][] = $this->getSimpleSnak( $snak );
			}
		}

		return $simpeQlfrs;
	}

	/**
	 * Get a streamlined references array
	 *
	 * @param array $references
	 * @return array
	 */
	protected function getSimpleReferences( array $references ) {
		$simpeRefs = array();

		foreach ( $references as $reference ) {
			$refEntry = array();
			foreach ( $reference['snaks'] as $propertyId => $snaks ) {
				$pId = WdqUtils::wdcToLong( $propertyId );
				$refEntry[$pId] = array();
				foreach ( $snaks as $snak ) {
					$refEntry[$pId][] = $this->getSimpleSnak( $snak );
				}
			}
			$simpeRefs[] = $refEntry;
		}

		return $simpeRefs;
	}

	/**
	 * @param array $property
	 * @param string $update (insert/update/upsert)
	 * @return string
	 */
	protected function importPropertyVertexSQL( array $property, $update ) {
		$labels = array(); // map of (<language> => <label>)
		// Flatten labels to a 1-level list for querying
		if ( isset( $property['labels'] ) ) {
			foreach ( $property['labels'] as $lang => $label ) {
				$labels[$lang] = $label['value'];
			}
		}

		$id = WdqUtils::wdcToLong( $property['id'] );
		if ( $id <= 0 ) {
			throw new Exception( "Bad entity ID: $id" );
		}

		$coreProperty = array(
			'id'       => $id,
			'datatype' => $property['datatype'],
			'labels'   => $labels ? (object)$labels : (object)array(),
			'claims'   => isset( $property['claims'] )
				// Include simplified claims for easy filtering/selecting
				? (object)$this->getSimpliedClaims( $property['claims'] )
				: (object)array(),
			'deleted'  => null,
			'stub'     => null
		);

		if ( $update === 'insert' ) {
			return "create vertex Property content " . json_encode( $coreProperty );
		} elseif ( $update === 'update' ) {
			$set = $this->sqlSet( $coreProperty );
			return "update Property set $set where id={$coreProperty['id']}";
		}

		throw new Exception( "Bad method '$update'." );
	}

	/**
	 * See http://www.mediawiki.org/wiki/Wikibase/DataModel
	 * See https://www.wikidata.org/wiki/Wikidata:Glossary
	 *
	 * @param array $entities List of unique items from the DB (simplified form)
	 * @param string $method (rebuild/bulk_init)
	 * @param array|null $classes Only do certain edge classes
	 */
	public function makeEntityEdges( array $entities, $method = 'rebuild', array $classes = null ) {
		if ( !$entities ) {
			return; // nothing to do
		}

		$queries = array();

		// Load #RIDs into process caches...
		$iIds = $pIds = array();
		foreach ( $entities as $entity ) {
			if ( !is_int( $entity['id'] ) ) {
				throw new Exception( "Got non-integer ID '{$entity['id']}'." );
			}
			if ( $entity['type'] === 'item' ) {
				$iIds[] = $entity['id'];
				if ( isset( $entity['rid'] ) ) { // performance
					$this->iCache->set( $entity['id'], $entity['rid'] );
				}
			} elseif ( $entity['type'] === 'property' ) {
				$pIds[] = $entity['id'];
				if ( isset( $entity['rid'] ) ) { // performance
					$this->pCache[$entity['id']] = $entity['rid'];
				}
			} else {
				throw new Exception( "Unrecognized entity type '{$entity['type']}'." );
			}
		}

		$this->updateItemRIDCache( $iIds );
		$this->updatePropertyRIDCache( $pIds );

		// Get all of the vertex #RIDs (which should exist)...
		$rids = array();
		foreach ( $entities as $entity ) {
			if ( $entity['type'] === 'item' ) {
				$rids[] = $this->iCache->get( $entity['id'] );
			} elseif ( $entity['type'] === 'property' ) {
				$rids[] = $this->pCache[$entity['id']];
			}
		}

		$curEdgeSids = array(); // map of (class:sid => #RID)
		if ( $method !== 'bulk_init' ) {
			// Get all existing edges while removing duplicates...
			$from = '[' . implode( ',', $rids ) . ']';
			$res = $this->tryQuery(
				"select sid,@class,@RID from " .
				"(select expand(outE()) from $from)", 1e9 );
			foreach ( $res as $record ) {
				$key = $record['class'] . ':' . $record['sid'];
				if ( isset( $curEdgeSids[$key] ) ) {
					$queries[] = "delete edge {$record['RID']}"; // redundant
				} else {
					$curEdgeSids[$key] = $record['RID'];
				}
			}
		}

		// Update/create edges as needed...
		$newEdgeSids = array(); // map of (class:sid => 1)
		foreach ( $entities as $entity ) {
			if ( $entity['type'] === 'item' ) {
				$queries = array_merge(
					$queries,
					$this->updateItemEdgesSQL( $entity, $curEdgeSids, $newEdgeSids, $classes )
				);
			} elseif ( $entity['type'] === 'property' ) {
				$queries = array_merge(
					$queries,
					$this->updatePropEdgesSQL( $entity, $curEdgeSids, $newEdgeSids, $classes )
				);
			}
		}

		if ( $method !== 'bulk_init' ) {
			// Destroy any prior outgoing edges with obsolete SIDs...
			$deleteSids = array_diff_key( $curEdgeSids, $newEdgeSids );
			foreach ( $deleteSids as $rid ) {
				$sql = "delete edge $rid";
				if ( $classes ) {
					$sql .= ' where @class in [' . implode( ',', $classes ) . ']';
				}
				$queries[] = $sql;
			}
		}

		$this->tryCommand( $queries, false, true );
	}

	/**
	 * See http://www.mediawiki.org/wiki/Wikibase/DataModel
	 * See https://www.wikidata.org/wiki/Wikidata:Glossary
	 *
	 * @param array $entity Simplified Item entity
	 * @param array $curEdgeSids Map of (class:sid => #RID) for all sids of at least $item
	 * @param array $newEdgeSids Empty array
	 * @param array|null $classes Only do certain edge classes
	 * @return array
	 */
	public function updateItemEdgesSQL(
		array $entity, array $curEdgeSids, array &$newEdgeSids, array $classes = null
	) {
		if ( !isset( $entity['claims'] ) ) {
			return array(); // nothing to do
		} elseif ( $classes !== null && !count( $classes ) ) {
			return array(); // nothing to do
		}

		$thisId = (int) $entity['id'];

		$dvEdges = array(); // list of data value statements (maps with class/val/rank)
		foreach ( $entity['claims'] as $propertyId => $statements ) {
			$pId = (int) $propertyId;
			foreach ( $statements as $mainSnak ) {
				$edges = array();
				if ( $mainSnak['snaktype'] === 'value' ) {
					$edges = $this->getValueStatementEdges( $thisId, $pId, $mainSnak );
				} elseif ( $mainSnak['snaktype'] === 'somevalue' ) {
					$edges[] = array(
						'class'   => 'HPwSomeV',
						'oid'     => $thisId,
						'iid'     => $pId,
						'toClass' => 'Property'
					);
				} elseif ( $mainSnak['snaktype'] === 'novalue' ) {
					$edges[] = array(
						'class'   => 'HPwNoV',
						'oid'     => $thisId,
						'iid'     => $pId,
						'toClass' => 'Property'
					);
				}

				// https://www.wikidata.org/wiki/Help:Ranking
				foreach ( $edges as &$edge ) {
					$edge['rank'] = $mainSnak['rank'];
					$edge['best'] = $mainSnak['best'];
					$edge['sid'] = $mainSnak['sid'];
					$edge['qlfrs'] = isset( $mainSnak['qlfrs'] )
						? (object)$mainSnak['qlfrs']
						: (object)array();
					$edge['refs'] = isset( $mainSnak['refs'] )
						? (object)$this->collapseReferences( $mainSnak['refs'] )
						: (object)array();
					$edge['odeleted'] = !empty( $entity['deleted'] ) ? true : null;

					$newEdgeSids[$edge['class'] . ':' . $edge['sid']] = 1;
				}
				unset( $edge );

				$dvEdges = array_merge( $dvEdges, $edges );
			}
		}

		$queries = array();

		// Create/update all of the new outgoing edges...
		foreach ( $dvEdges as $dvEdge ) {
			if ( $classes && !in_array( $dvEdge['class'], $classes ) ) {
				continue; // skip this edge class
			}
			$class = $dvEdge['class'];
			unset( $dvEdge['class'] );
			$toClass = $dvEdge['toClass'];
			unset( $dvEdge['toClass'] );

			$key = $class . ":" . $dvEdge['sid'];
			// If an edge was found with the SID, then update it...
			if ( isset( $curEdgeSids[$key] ) ) {
				$rid = $curEdgeSids[$key];
				$set = $this->sqlSet( $dvEdge );
				$queries[] = "update $rid set $set";
			// If no edge was found with the SID, then make a new one...
			} else {
				if ( $this->iCache->has( $thisId ) ) {
					$from = $this->iCache->get( $thisId );
				} else {
					$from = "(select from Item where id=$thisId)";
				}

				if ( $toClass === 'Property' && isset( $this->pCache[$dvEdge['iid']] ) ) {
					$to = $this->pCache[$dvEdge['iid']];
				} elseif ( $toClass === 'Item' && $this->iCache->has( $dvEdge['iid'] ) ) {
					$to = $this->iCache->get( $dvEdge['iid'] );
				} else {
					$to = "(select from $toClass where id={$dvEdge['iid']})";
					if ( $toClass === 'Item' ) {
						$this->iHitCache->set( $dvEdge['iid'], 1 );
					}
				}

				if ( !$dvEdge['odeleted'] ) {
					// https://github.com/orientechnologies/orientdb/issues/3365
					unset( $dvEdge['odeleted'] );
				}

				$sql = "create edge $class from $from to $to content " . json_encode( $dvEdge );
				$queries[] = $sql;
			}
		}

		if ( mt_rand( 0, 99 ) == 0 ) {
			$this->updateItemRIDCache( $this->iHitCache->getAllKeys() );
		}

		return $queries;
	}

	/**
	 * See http://www.mediawiki.org/wiki/Wikibase/DataModel
	 * See https://www.wikidata.org/wiki/Wikidata:Glossary
	 *
	 * @param array $entity Simplified Property entity
	 * @param array $curEdgeSids Map of (class:sid => #RID) for all sids of at least $item
	 * @param array $newEdgeSids Empty array
	 * @param array|null $classes Only do certain edge classes
	 * @return array
	 */
	public function updatePropEdgesSQL(
		array $entity, array $curEdgeSids, array &$newEdgeSids, array $classes = null
	) {
		if ( !isset( $entity['claims'] ) ) {
			return array(); // nothing to do
		} elseif ( $classes !== null && !count( $classes ) ) {
			return array(); // nothing to do
		}

		$thisId = (int) $entity['id'];

		$dvEdges = array(); // list of data value statements (maps with class/val/rank)
		foreach ( $entity['claims'] as $propertyId => $statements ) {
			$pId = (int) $propertyId;
			foreach ( $statements as $mainSnak ) {
				if ( $mainSnak['snaktype'] === 'value'
					&& $mainSnak['valuetype'] === 'wikibase-property'
				) {
					$otherId = (int) $mainSnak['datavalue'];
					$dvEdges[] = array(
						'class'     => 'HPaPV',
						'pid'       => $pId,
						'oid'       => $thisId,
						'iid'       => $otherId,
						'sid'       => $mainSnak['sid'],
						'odeleted'  => !empty( $entity['deleted'] ) ? true : null
					);
					$newEdgeSids['HPaPV:' . $mainSnak['sid']] = 1;
				}
			}
		}

		$queries = array();

		// Create/update all of the new outgoing edges...
		foreach ( $dvEdges as $dvEdge ) {
			if ( $classes && !in_array( $dvEdge['class'], $classes ) ) {
				continue; // skip this edge class
			}
			$class = $dvEdge['class'];
			unset( $dvEdge['class'] );

			$key = $class . ":" . $dvEdge['sid'];
			// If an edge was found with the SID, then update it...
			if ( isset( $curEdgeSids[$key] ) ) {
				$rid = $curEdgeSids[$key];
				$set = $this->sqlSet( $dvEdge );
				$queries[] = "update $rid set $set";
			// If no edge was found with the SID, then make a new one...
			} else {
				if ( isset( $this->pCache[$thisId] ) ) {
					$from = $this->pCache[$thisId];
				} else {
					$from = "(select from Property where id=$thisId)";
				}

				if ( isset( $this->pCache[$dvEdge['iid']] ) ) {
					$to = $this->pCache[$dvEdge['iid']];
				} else {
					$to = "(select from Property where id={$dvEdge['iid']})";
				}

				if ( !$dvEdge['odeleted'] ) {
					// https://github.com/orientechnologies/orientdb/issues/3365
					unset( $dvEdge['odeleted'] );
				}

				$sql = "create edge $class from $from to $to content " . json_encode( $dvEdge );
				$queries[] = $sql;
			}
		}

		return $queries;
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

		$type = $mainSnak['valuetype'];
		if ( $type === 'wikibase-item' ) {
			$otherId = (int) $mainSnak['datavalue'];
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
			$time = $mainSnak['datavalue'];
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
			$amount = $mainSnak['datavalue']; // decimals
			$dvEdges[] = array(
				'class'   => 'HPwQV',
				'val'     => (float) $amount,
				'oid'     => $qId,
				'iid'     => $pId,
				'toClass' => 'Property'
			);
		} elseif ( $type === 'globecoordinate' ) {
			$dvEdge = WdqUtils::normalizeGeoCoordinates( array(
				'class'   => 'HPwCV',
				'lat'     => (float) $mainSnak['datavalue']['lat'],
				'lon'     => (float) $mainSnak['datavalue']['lon'],
				'oid'     => $qId,
				'iid'     => $pId,
				'toClass' => 'Property'
			) );
			if ( $dvEdge ) {
				$dvEdges[] = $dvEdge;
			}
		} elseif ( $type === 'url' || $type === 'string' ) {
			$dvEdges[] = array(
				'class'   => 'HPwSV',
				'val'     => (string) $mainSnak['datavalue'],
				'oid'     => $qId,
				'iid'     => $pId,
				'toClass' => 'Property'
			);
		}

		return $dvEdges;
	}

	/**
	 * Collapse references from (index => P => statement) to (P => statement)
	 *
	 * @param array $refs
	 * @return array
	 */
	protected function collapseReferences( array $refs ) {
		$cRefs = array();

		foreach ( $refs as $ref ) {
			foreach ( $ref as $propertyId => $snaks ) {
				$cRefs[$propertyId] = isset( $cRefs[$propertyId] )
					? array_merge( $cRefs[$propertyId], $snaks )
					: $snaks;
			}
		}

		return $cRefs;
	}

	/**
	 * @param string|int|array $ids 64-bit integers
	 */
	public function createDeletedPropertyStubs( $ids ) {
		if ( !$ids ) {
			return;
		}

		$queries = array();
		foreach ( $ids as $id ) {
			$item = array(
				'id'        => (int) $id,
				'labels'    => (object)array(),
				'datatype'  => 'unknown',
				'claims'    => (object)array(),
				'deleted'   => true,
				'stub'      => true
			);
			$queries[] = "create vertex Property content " . json_encode( $item );
		}

		$this->tryCommand( $queries );
	}

	/**
	 * @param string|int|array $ids 64-bit integers
	 */
	public function createDeletedItemStubs( $ids ) {
		if ( !$ids ) {
			return;
		}

		$queries = array();
		foreach ( $ids as $id ) {
			$item = array(
				'id'        => (int) $id,
				'labels'    => (object)array(),
				'claims'    => (object)array(),
				'sitelinks' => (object)array(),
				'deleted'   => true,
				'stub'      => true
			);
			$queries[] = "create vertex Item content " . json_encode( $item );
		}

		$this->tryCommand( $queries );
	}

	/**
	 * @param string $class
	 * @param string|int|array $ids 64-bit integers
	 */
	public function deleteEntities( $class, $ids ) {
		if ( !$ids ) {
			return;
		}
		// https://github.com/orientechnologies/orientdb/issues/3150
		$orClause = array();
		foreach ( (array)$ids as $id ) {
			$orClause[] = "id='$id'";
		}
		$orClause = implode( ' OR ', $orClause );

		$this->tryCommand( array(
			"update $class set deleted=true where ($orClause)",
			"update (select expand(outE()) from Item where ($orClause)) set odeleted=true"
		) );
	}

	/**
	 * @param string $class
	 * @param string|int|array $ids 64-bit integers
	 */
	public function restoreEntities( $class, $ids ) {
		if ( !$ids ) {
			return;
		}
		// https://github.com/orientechnologies/orientdb/issues/3150
		$orClause = array();
		foreach ( (array)$ids as $id ) {
			$orClause[] = "id='$id'";
		}
		$orClause = implode( ' OR ', $orClause );

		$this->tryCommand( array(
			"update $class set deleted=NULL where ($orClause)",
			"update (select expand(outE()) from Item where ($orClause)) set odeleted=NULL"
		) );
	}

	/**
	 * @param string|array $sql
	 * @param bool $atomic
	 * @param bool $ignore_dups
	 * @throws Exception
	 */
	public function tryCommand( $sql, $atomic = true, $ignore_dups = true ) {
		$sql = (array)$sql;
		if ( !count( $sql ) ) {
			return; // nothing to do
		}

		$ops = array();
		foreach ( $sql as $sqlCmd ) {
			$ops[] = array( 'type' => 'cmd', 'language' => 'sql', 'command' => $sqlCmd );
		}

		$req = array(
			'method'  => 'POST',
			'url'     => "{$this->url}/batch/WikiData",
			'headers' => array(
				'Content-Type' => "application/json",
				'Cookie'       => "OSESSIONID={$this->getSessionId()}" ),
			'body'    => json_encode( array(
				'transaction' => true,
				'operations'  => $ops
			) )
		);

		list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $this->http->run( $req );
		// Retry once for random failures (or when the payload is too big)...
		if ( $rcode != 200 && count( $sql ) > 1 ) {
			if ( $atomic ) {
				list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $this->http->run( $req );
			} else {
				// Break down the commands if possible, which gets past some failures
				foreach ( $sql as $sqlCmd ) {
					$this->tryCommand( $sqlCmd, true, $ignore_dups );
				}
				return;
			}
		}

		if ( $rcode != 200 ) {
			if ( $ignore_dups && strpos( $rbody, 'ORecordDuplicatedException' ) !== false ) {
				return;
			}
			$errSql = is_array( $sql ) ? implode( "\n", $sql ) : $sql;
			print( "Error on command:\n$errSql\n\n" );
			throw new Exception( "Command failed ($rcode). Got:\n$rbody" );
		}

		return;
	}

	/**
	 * @param string|array $sql
	 * @param integer $limit
	 * @return array
	 * @throws Exception
	 */
	public function tryQuery( $sql, $limit = 1e9 ) {
		$req = array(
			'method'  => 'GET',
			'url'     => "{$this->url}/query/WikiData/sql/" . rawurlencode( $sql ) . "/$limit",
			'headers' => array( 'Cookie' => "OSESSIONID={$this->getSessionId()}" )
		);
		list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $this->http->run( $req );
		if ( $rcode !== 200 ) {
			// XXX: re-auth sometimes works
			$this->sessionId = null;
			$req['headers']['Cookie'] = "OSESSIONID={$this->getSessionId()}";
			list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $this->http->run( $req );
		}

		if ( $rcode != 200 ) {
			$tsql = substr( $sql, 0, 255 );
			throw new Exception( "Query failed ($rcode).\n\nSent:\n$tsql...\n\nGot:\n$rbody" );
		}

		$response = json_decode( $rbody, true );
		if ( $response === null ) {
			$tsql = substr( $sql, 0, 255 );
			throw new Exception( "Bad JSON response.\n\nSent:\n$tsql...\n\nGot:\n$rbody" );
		}

		return $response['result'];
	}

	/**
	 * @return string
	 * @throws Exception
	 */
	protected function getSessionId() {
		if ( $this->sessionId !== null ) {
			return $this->sessionId;
		}
		$hash = base64_encode( "{$this->user}:{$this->password}" );
		list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $this->http->run( array(
			'method'  => 'GET',
			'url'     => "{$this->url}/connect/WikiData",
			'headers' => array( 'Authorization' => "Basic " . $hash )
		) );
		$m = array();
		if ( isset( $rhdrs['set-cookie'] ) &&
			preg_match( '/(?:^|;)OSESSIONID=([^;]+);/', $rhdrs['set-cookie'], $m )
		) {
			$this->sessionId = $m[1];
		} else {
			throw new Exception( "Invalid authorization credentials ($rcode).\n" );
		}

		return $this->sessionId;
	}

	/**
	 * @param array $object
	 * @return string
	 */
	protected function sqlSet( array $object ) {
		$set = array();
		foreach ( $object as $key => $value ) {
			if ( is_bool( $value ) ) {
				$set[] = "$key=" . ( $value ? 'true' : 'false' );
			} elseif ( is_float( $value ) || is_int( $value ) ) {
				$set[] = "$key=$value";
			} elseif ( is_string( $value ) ) {
				$set[] = "$key='" . addcslashes( $value, "'\\" ) . "'";
			} elseif ( $value === null ) {
				$set[] = "$key=NULL";
			} else {
				$set[] = "$key=" . json_encode( $value );
			}
		}
		return implode( ', ', $set );
	}

	/**
	 * Build the full P# => #RID cache map
	 */
	public function buildPropertyRIDCache() {
		$this->pCache = array();
		$res = $this->tryQuery( 'select from index:ProperyIdIdx', 10000 );
		foreach ( $res as $record ) {
			$this->pCache[(int)$record['key']] = $record['rid'];
		}
	}

	/**
	 * Build the P# => #RID cache map
	 *
	 * @param array $ids integers
	 */
	protected function updatePropertyRIDCache( array $ids ) {
		$orClause = array();
		foreach ( $ids as $id ) {
			if ( !isset( $this->pCache[$id] ) ) {
				$orClause[] = "id=$id";
			}
		}

		if ( $orClause ) {
			$orClause = implode( " OR ", $orClause );
			$res = $this->tryQuery( "select id,@RID from Property where $orClause" );
			foreach ( $res as $record ) {
				$this->pCache[(int)$record['id']] = $record['RID'];
			}
		}
	}

	/**
	 * Build the Q# => #RID cache map
	 *
	 * @param array $ids integers
	 */
	protected function updateItemRIDCache( array $ids ) {
		$orClause = array();
		foreach ( $ids as $id ) {
			if ( !$this->iCache->has( $id ) ) {
				$orClause[] = "id=$id";
			}
		}

		if ( $orClause ) {
			$orClause = implode( " OR ", $orClause );
			$res = $this->tryQuery( "select id,@RID from Item where $orClause" );
			foreach ( $res as $record ) {
				$this->iCache->set( (int)$record['id'], $record['RID'] );
			}
		}
	}
}
