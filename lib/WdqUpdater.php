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

	/**
	 * @param MultiHttpClient $http
	 * @param array $auth
	 */
	public function __construct( MultiHttpClient $http, array $auth ) {
		$this->http = $http;
		$this->url = $auth['url'];
		$this->user = $auth['user'];
		$this->password = $auth['password'];
	}

	/**
	 * See http://www.mediawiki.org/wiki/Wikibase/DataModel/Primer
	 *
	 * @param array $entities
	 * @param string $update (update/insert/upsert)
	 * @throws Exception
	 */
	public function importEntities( array $entities, $update ) {
		$sqlQueries = array();
		foreach ( $entities as $entity ) {
			if ( $entity['type'] === 'item' ) {
				$sqlQueries[] = $this->importItemVertexSQL( $entity, $update );
			} elseif ( $entity['type'] === 'property' ) {
				$sqlQueries[] = $this->importPropertyVertexSQL( $entity, $update );
			}
		}

		$this->tryCommand( $sqlQueries, false, true );
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
					// Use the cleaned up statement
					$claims[$propertyId][$id] = $statement;
				}
			}
		}
		// Include the property IDs (pids) referenced for tracking
		$coreItem = array(
			'id'        => (float) WdqUtils::wdcToLong( $item['id'] ),
			'labels'    => $labels ? $labels : (object)array(),
			'claims'    => $claims ? $claims : (object)array(),
			'sitelinks' => $siteLinks ? $siteLinks : (object)array(),
		) + $this->getReferenceIdSet( $claims );

		if ( $update === 'update' || $update === 'upsert' ) {
			// Don't use CONTENT; https://github.com/orientechnologies/orientdb/issues/3176
			$set = array();
			foreach ( $coreItem as $key => $value ) {
				if ( is_float( $value ) ) {
					$set[] = "$key=$value";
				} elseif ( is_scalar( $value ) ) {
					$set[] = "$key='" . addcslashes( $value, "'" ) . "'";
				} else {
					$set[] = "$key=" . WdqUtils::toJSON( $value );
				}
			}
			$set = implode( ', ', $set );

			return "update Item set $set where id={$coreItem['id']}";
		}

		if ( $update === 'insert' || $update === 'upsert' ) {
			return "create vertex Item content " . WdqUtils::toJSON( $coreItem );
		}

		throw new Exception( "Bad method '$update'." );
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
	 * @return string
	 */
	protected function importPropertyVertexSQL( array $item, $update ) {
		$coreItem = array(
			'id'       => (float) WdqUtils::wdcToLong( $item['id'] ),
			'datatype' => $item['datatype']
		);

		if ( $update === 'update' || $update === 'upsert' ) {
			// Don't use CONTENT; https://github.com/orientechnologies/orientdb/issues/3176
			$set = array();
			foreach ( $coreItem as $key => $value ) {
				if ( $key === 'id' ) { // PK
					continue;
				} elseif ( is_scalar( $value ) ) {
					$set[] = "$key='" . addcslashes( $value, "'" ) . "'";
				} else {
					$set[] = "$key=" . WdqUtils::toJSON( $value );
				}
			}
			$set = implode( ',', $set );
			return "update Property set $set where id={$coreItem['id']}";
		}

		if ( $update === 'insert' || $update === 'upsert' ) {
			return "create vertex Property content " . WdqUtils::toJSON( $coreItem );
		}

		throw new Exception( "Bad method '$update'." );
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

				$edges = array();
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
				}

				// https://www.wikidata.org/wiki/Help:Ranking
				foreach ( $edges as &$edge ) {
					$edge['rank'] = $rankMap[$statement['rank']];
					$edge['best'] = $edge['rank'] >= $maxRankByPid[$pId] ? 1 : 0;
					$edge['sid'] = $statement['id'];
				}
				unset( $edge );

				$dvEdges = array_merge( $dvEdges, $edges );
			}
		}

		$sqlQueries = array();
		// Destroy all prior outgoing edges
		if ( $method !== 'bulk_init' ) {
			if ( $classes === null || count( $classes ) ) {
				$sql = "delete edge from (select from Item where id=$qId)";
				if ( $classes ) {
					$sql .= ' where @class in [' . implode( ',', $classes ) . ']';
				}
				$sqlQueries[] = $sql;
			}
		}
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

		$this->tryCommand( $sqlQueries );
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
			$dvEdge = WdqUtils::normalizeGeoCoordinates( array(
				'class'   => 'HPwCV',
				'lat'     => (float) $mainSnak['datavalue']['value']['latitude'],
				'lon'     => (float) $mainSnak['datavalue']['value']['longitude'],
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
	 * @param string|array $sql
	 * @param bool $atomic
	 * @param bool $ignore_dups
	 * @return array|null
	 * @throws Exception
	 */
	public function tryCommand( $sql, $atomic = true, $ignore_dups = true ) {
		if ( is_array( $sql ) && $atomic ) {
			$sqlBatch = array_merge( array( 'begin' ), $sql, array( 'commit retry 100' ) );
		} else {
			$sqlBatch = $sql;
		}

		$req = array(
			'method'  => 'POST',
			'url'     => "{$this->url}/batch/WikiData",
			'headers' => array(
				'Content-Type' => "application/json",
				'Cookie'       => "OSESSIONID={$this->getSessionId()}" ),
			'body'    => json_encode( array(
				'transaction' => true,
				'operations'  => array(
					array(
						'type'     => 'script',
						'language' => 'sql',
						'script'   => $sqlBatch
					)
				)
			) )
		);

		list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $this->http->run( $req );
		// Retry once for random failures (or when the payload is too big)...
		if ( $rcode != 200 && is_array( $sqlBatch ) ) {
			if ( $atomic ) {
				print( "Retrying batch command.\n" );
				list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $this->http->run( $req );
			} else {
				print( "Retrying each batch command.\n" );
				// Break down the commands if possible, which gets past some failures
				foreach ( $sqlBatch as $sqlCmd ) {
					$this->tryCommand( $sqlCmd, false, $ignore_dups );
				}
				return null;
			}
		}

		if ( $rcode != 200 ) {
			if ( $ignore_dups && strpos( $rbody, 'ORecordDuplicatedException' ) !== false ) {
				return null;
			}
			$errSql = is_array( $sql ) ? implode( "\n", $sql ) : $sql;
			print( "Error on command:\n$errSql\n\n" );
			throw new Exception( "Command failed ($rcode). Got:\n$rbody" );
		}

		$response = json_decode( $rbody, true );

		return $response['result'];
	}

	/**
	 * @param string|array $sql
	 * @return array
	 * @throws Exception
	 */
	public function tryQuery( $sql ) {
		list( $rcode, $rdesc, $rhdrs, $rbody, $rerr ) = $this->http->run( array(
			'method'  => 'GET',
			'url'     => "{$this->url}/query/WikiData/sql/" . rawurlencode( $sql ),
			'headers' => array( 'Cookie' => "OSESSIONID={$this->getSessionId()}" )
		) );

		if ( $rcode != 200 ) {
			$tsql = substr( $sql, 0, 255 );
			throw new Exception( "Command failed ($rcode).\n\nSent:\n$tsql...\n\nGot:\n$rbody" );
		}

		$response = json_decode( $rbody, true );

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
		if ( preg_match( '/(?:^|;)OSESSIONID=([^;]+);/', $rhdrs['set-cookie'], $m ) ) {
			$this->sessionId = $m[1];
		} else {
			throw new Exception( "Invalid authorization credentials ($rcode).\n" );
		}

		return $this->sessionId;
	}
}
