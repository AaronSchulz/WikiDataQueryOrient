<?php

/**
 * Easy to use abstract query language helper
 *
 * Example query:
 * (
 *	id,
 *	sitelinks['en'] AS sitelink,
 *	labels['en'] AS label,
 *	claims[X] AS PX,
 *	claims[Y] AS PY
 * )
 * FROM {HPwIV[X:$SOMEITEMS]}
 * GIVEN (
 *		$SOME_ITEMS = {HPwIVWeb[X] OUT[X,Y]}
 *		$OTHER_ITEMS = {HPwIVWeb[$SOME_ITEMS] IN[X,Y]}
 *		$ITEMS_A = {HPwQV[X:A] DESC RANK(best) QUALIFY(HPwV[X:Y]) WHERE(HPwV[X:Y])}
 *		$ITEMS_B = {HPwQV[Y:B TO C, GTE D]}
 *		$BOTH_AB = UNION($ITEMS_A,$ITEMS_B)
 *		$DIFF_AB = DIFFERENCE($ITEMS_A,$ITEMS_B)
 *		$INTERSECT_AB = INTERSECT($ITEMS_A,$ITEMS_B)
 *		$SET_A = {HPwTV[X:A] ASC RANK(best) QUALIFY(HPwV[X:Y]) WHERE(HPwV[X:Y]) LIMIT(100)}
 *		$SET_B = {HPwCV[X:AROUND A B C,AROUND A B C] RANK(best) QUALIFY(HPwV[X:Y]) WHERE(HPwV[X:Y])}
 *		$SET_C = {HPwSV[X:"cat","says","meow"] RANK(best) QUALIFY(HPwV[X:Y]) WHERE(HPwV[X:Y])}
 *		$STUFF = {items[2,425,62,23]}
 *		$WLINK = {HPwIV[X:A] WHERE(link[X,Y])}
 * )
 */
class WdqQueryParser {
	const RE_FLOAT = '[-+]?[0-9]*\.?[0-9]+';
	const RE_UFLOAT = '\+?[0-9]*\.?[0-9]+';
	const RE_DATE = '(-|\+)0*(\d+)-(\d\d)-(\d\d)T0*(\d\d):0*(\d\d):0*(\d\d)Z';

	const VAR_RE = '\$[a-zA-Z_]+';

	const FLD_BASIC = '/^(id|sitelinks|labels|claims)$/';
	const FLD_MAP = '/^((?:sitelinks|labels)\[\$?\d+\])\s+AS\s+([a-zA-Z][a-zA-Z0-9_]*)$/';
	const FLD_CLAIMS = '/^(claims\[\$?\d+\])(?:\[rank\s*=\s*([a-z]+)\])?\s+AS\s+([a-zA-Z][a-zA-Z0-9_]*)$/';

	/** @var array Used for getting cross products from Edge class */
	const OUT_ITEM_FIELDS = 'out.id AS id,out.labels AS labels,out.sitelinks AS sitelinks,out.claims AS claims';

	/** @var array (comparison operator => SQL operator) */
	protected static $compareOpMap = array(
		'GT' => '>', 'GTE' => '>=', 'LT' => '<', 'LTE' => '<='
	);

	/** @var array (rank => storage value) */
	protected static $rankMap = array(
		'deprecated' => -1,
		'normal'     => 0,
		'preferred'  => 1
	);

	/**
	 * @param string $s
	 * @param integer $timeout
	 * @param integer $limit
	 * @return string Orient SQL
	 */
	public static function parse( $s, $timeout = 5000, $limit = 1000 ) {
		$s = trim( $s );

		// Remove any single-line comments
		$s = preg_replace( '/^\s*#.*$/m', '', $s );
		// Amour all quoted string values for easy parsing
		list( $s, $map ) = self::stripQuotedStrings( $s );
		$rest = $s;

		// Get the properties selecteed
		$props = self::consumePair( $rest, '()' );

		// Get the FROM query
		$token = self::consumeWord( $rest );
		if ( $token !== 'FROM' ) {
			throw new ParseException( "Expected FROM: $s" );
		} elseif ( $rest === '' ) {
			throw new ParseException( "Missing FROM query '$s'" );
		} elseif ( $rest[0] === '{' ) {
			$setQuery = '{' . self::consumePair( $rest, '{}' ) . '}';
		} else {
			// UNION/INTERSECT/DIFFERENCE
			$token = self::consumeWord( $rest );
			$setQuery = "$token(" . self::consumePair( $rest, '()' ) . ")";
		}

		$givenMap = array(); // (wdq variable => orient varable)
		// Given any GIVEN assignments
		if ( strlen( $rest ) ) {
			$token = self::consumeWord( $rest );
			if ( $token === 'GIVEN' ) {
				$given = self::consumePair( $rest, '()' );
				$givenMap = self::parseGiven( $given );
			}
		}

		if ( strlen( $rest ) ) {
			throw new ParseException( "Excess query statements found: $rest" );
		}

		// Validate the properties selected.
		// Enforce that [] fields use aliases (they otherwise get called out1, out2...)
		// @note: propagate certain * fields from subqueries that could be useful
		$proj = array( '*depth', '*distance', '*time', '*value' );
		foreach ( explode( ',', $props ) as $prop ) {
			$prop = trim( $prop );
			$m = array();
			if ( preg_match( self::FLD_BASIC, $prop ) ) {
				$proj[] = "{$prop} AS {$prop}";
			} elseif ( preg_match( self::FLD_MAP, $prop, $m ) ) {
				$proj[] = "{$m[1]} AS {$m[2]}";
			} elseif ( preg_match( self::FLD_CLAIMS, $prop, $m ) ) {
				$field = "{$m[1]}";
				// Per https://github.com/orientechnologies/orientdb/issues/3284
				// we only get one filter, so make it on rank
				// https://bugs.php.net/bug.php?id=51881
				if ( empty( $m[2] ) ) {
					// no rank filter
				} elseif ( $m[2] === 'best' ) {
					$field .= "[best=1]";
				} elseif ( isset( self::$rankMap[$m[2]] ) ) {
					$field .= "[rank=" . self::$rankMap[$m[2]] . "]";
				} else {
					throw new ParseException( "Bad rank: '{$m[2]}'" );
				}
				$proj[] = "$field AS {$m[3]}";
			} else {
				throw new ParseException( "Invalid field: $prop" );
			}
		}
		$proj = implode( ',', $proj );

		// Get the main query conditions
		$query = self::consumeSetOp( $setQuery, $givenMap );
		if ( strlen( $setQuery ) ) {
			throw new ParseException( "Excess query statements found: $rest" );
		}

		$sql = "SELECT $proj FROM $query LIMIT $limit TIMEOUT $timeout";

		return self::unstripQuotedStrings( $sql, $map );
	}

	/**
	 * @param string $s
	 * @return array Map of variables to Orient SQL
	 */
	protected static function parseGiven( $s ) {
		$map = array();

		$rest = $s;
		while ( strlen( $rest ) ) {
			$variable = self::consumeWord( $rest );
			if ( preg_match( "/^" . self::VAR_RE . "$/", $variable ) ) {
				if ( isset( $map[$variable] ) ) {
					throw new ParseException( "Cannot mutate variable '$variable'." );
				}
				$token = self::consumeWord( $rest );
				if ( $token !== '=' ) {
					throw new ParseException( "Expected =: $rest" );
				}
				$map[$variable] = self::consumeSetOp( $rest, $map, '' );
			} else {
				throw new ParseException( "Bad variable: $variable" );
			}
		}

		return $map;
	}

	/**
	 * @param string $rest
	 * @param array $givenMap
	 * @return string Orient SQL
	 */
	protected static function consumeSetOp( &$rest, array $givenMap ) {
		$orig = $rest;
		if ( $rest[0] === '{' ) {
			$set = self::consumePair( $rest, "{}" );
			return '(' . self::parseSet( $set, $givenMap ) . ')';
		}

		static $ops = array( 'UNION', 'INTERSECT', 'DIFFERENCE' );

		$operation = self::consumeWord( $rest );
		if ( in_array( $operation, $ops ) ) {
			$variables = self::consumePair( $rest, "()" );

			$i = 0;
			$lets = array();
			foreach ( preg_split( '/\s*,\s*/', $variables ) as $var ) {
				if ( $var[0] !== '$' ) {
					throw new Exception( "$operation expects variable arguments." );
				} elseif ( !isset( $givenMap[$var] ) ) {
					throw new Exception( "$operation given undefined variable '$var'" );
				}
				$lets["\$t{$i}"] = "\$t{$i} = {$givenMap[$var]}";
				++$i;
			}
			if ( !$lets ) {
				throw new ParseException( "$operation missing arguments: $rest" );
			}
			$args = implode( ',', array_keys( $lets ) );

			// Orient only has UNIONALL, so we also use DINSTINCT
			if ( $operation === 'UNION' ) {
				$lets[] = "\$tf = DISTINCT(UNIONALL($args))";
			} else {
				$lets[] = "\$tf = $operation($args)";
			}

			return "(SELECT expand( \$tf ) LET " . implode( ', ', $lets ) . ')';
		}

		throw new ParseException( "Unparsable set: $orig" );
	}

	/**
	 * Parse things like
	 * "HPwIV[X:A,B,C] QUALIFY(HPwQV[X:Y]) WHERE(~HPwIV[X:A,C-D] AND (HPwQV[X:Y] OR HPwQV[X:Y]))"
	 *
	 * @param string $s
	 * @param array $givenMap
	 * @return string Orient SQL
	 */
	protected static function parseSet( $s, array $givenMap ) {
		$rest = $s;
		$sql = null;

		// Qualifier conditions normally applied in edge class WHERE
		$qualiferPrefix = 'qlfrs';

		$m = array();
		$dlist = '(?:\d+,?)+';
		$gvar = self::VAR_RE; // item IDs generators via GIVEN
		// Get the primary select condition (using some index)
		if ( preg_match( "/^HPwSomeV\[($dlist)\]\s*(ASC|DESC)?\s*/", $rest, $m ) ) {
			$inRanges = self::parseRangeDive( 'iid', $m[1] );
			$order = isset( $m[2] ) ? $m[2] : 'ASC';
			$orderBy = "ORDER BY iid $order,oid $order";
			$fields = self::OUT_ITEM_FIELDS;
			$sql = "SELECT $fields FROM HPwSomeV WHERE $inRanges AND @ECOND@ GROUP BY out $orderBy";
		} elseif ( preg_match( "/^HPwIV\[(\d+):($dlist)\]\s*(ASC|DESC)?\s*/", $rest, $m ) ) {
			$pId = $m[1];
			$inRanges = self::parseRangeDive( 'iid', $m[2], "pid=$pId" );
			$order = isset( $m[3] ) ? $m[3] : 'ASC';
			$orderBy = "ORDER BY iid $order,pid $order,oid $order";
			$fields = self::OUT_ITEM_FIELDS;
			$sql = "SELECT $fields FROM HIaPV WHERE $inRanges AND @ECOND@ GROUP BY out $orderBy";
		} elseif ( preg_match( "/^HPwSV\[(\d+):((?:\\$\d+,?)+)\]\s*/", $rest, $m ) ) {
			$pId = $m[1];
			// Avoid IN[] per https://github.com/orientechnologies/orientdb/issues/3204
			$or = array();
			foreach ( explode( ',', $m[2] ) as $valId ) {
				$or[] = "iid=$pId AND val=$valId";
			}
			$or = self::sqlOR( $or );
			$fields = self::OUT_ITEM_FIELDS . ",val as *value";
			$sql = "SELECT $fields FROM HPwSV WHERE ($or) AND @ECOND@ GROUP BY out";
		} elseif ( preg_match( "/^(HPwQV|HPwTV)\[(\d+):([^]]+)\]\s*(ASC|DESC)?\s*/", $rest, $m ) ) {
			$class = $m[1];
			$pId = $m[2];
			if ( $class === 'HPwTV' ) {
				$valField = '*time';
				$inRanges = self::parsePeriodDive( 'val', $m[3], "iid=$pId" );
			} else {
				$valField = '*value';
				$inRanges = self::parseRangeDive( 'val', $m[3], "iid=$pId" );
			}
			$order = isset( $m[4] ) ? $m[4] : 'ASC';
			// @note: could be several claims...use the closest one (good with rank=best)
			// @note: with several claims, *value may change depending on ASC vs DESC
			$orderBy = "ORDER BY iid $order,val $order,oid $order";
			$fields = self::OUT_ITEM_FIELDS . ",val AS $valField";
			$sql = "SELECT $fields FROM $class WHERE $inRanges AND @ECOND@ GROUP BY out $orderBy";
		} elseif ( preg_match( "/^HPwCV\[(\d+):([^]]+)\]\s*/", $rest, $m ) ) {
			$pId = $m[1];
			$around = self::parseAroundDive( $m[2] );
			$fields = self::OUT_ITEM_FIELDS . ',$distance AS *distance';
			// @note: could be several claims...use the closest one (good with rank=best)
			$sql = "SELECT $fields FROM HPwCV WHERE $around AND iid=$pId AND @ECOND@ GROUP BY out";
		} elseif ( preg_match( "/^items\[($dlist)\]\s*/", $rest, $m ) ) {
			$iIds = explode( ',', $m[1] );
			$inClause = self::sqlIN( 'id', $iIds );
			$sql = "SELECT FROM Item WHERE $inClause AND @ICOND@";
			$qualiferPrefix = null; // makes no sense
		} elseif ( preg_match( "/^linkedto\[((?:\\$\d+,?)+)\]\s*/", $rest, $m ) ) {
			$valIds = explode( ',', $m[1] );
			$or = array();
			foreach ( $valIds as $valId ) {
				$or[] = "sitelinks CONTAINSVALUE $valId";
			}
			$or = self::sqlOR( $or );
			$sql = "SELECT FROM Item WHERE $or AND @ICOND@";
			$qualiferPrefix = null; // makes no sense
		} elseif ( preg_match(
			"/^HPwIVWeb\[($dlist|$gvar)\](?:\s+OUT\[($dlist)\])?(?:\s+IN\[($dlist)\])?(?:\s+MAXDEPTH\((\d+)\))?\s*/", $rest, $m )
		) {
			if ( preg_match( "/^$dlist$/", $m[1] ) ) {
				$iIds = explode( ',', $m[1] );
				$from = "(SELECT FROM Item WHERE " . self::sqlIN( 'id', $iIds ) . " AND @IDCOND@)";
			} else {
				$variable = $m[1];
				if ( !isset( $givenMap[$variable] ) ) {
					throw new ParseException( "No '$variable' entry in GIVEN clause: $s" );
				}
				$from = $givenMap[$variable];
			}

			// https://bugs.php.net/bug.php?id=51881
			$pIdsFD = empty( $m[2] ) ? array() : explode( ',', $m[2] );
			$pIdsRV = empty( $m[3] ) ? array() : explode( ',', $m[3] );
			// We traverse I->E->I->..., so on each item $depth is 2X the vertex depth
			$maxDepth = empty( $m[4] ) ? null : 2 * $m[4];

			// As we inspect an edge, one side is the vertex we came from, and the
			// other is the one we want to go to. We can follow both out_ and in_ for
			// all edges, since we only inspect edges of the right type/direction.
			$tfields = array();
			foreach ( $pIdsFD as $pId ) {
				// Edges followed in forwards direction are filtered on certain PIDs
				$tfields[] = "Item.out_HIaPV[pid=$pId]";
				$tfields[] = "HIaPV.in";
			}
			foreach ( $pIdsRV as $pId ) {
				// Edges followed in reverse direction are filtered on certain PIDs
				$tfields[] = "Item.in_HIaPV[pid=$pId]";
				$tfields[] = "HIaPV.out";
			}
			$depthCond = $maxDepth
				? "\$depth <= $maxDepth"
				: "\$depth >= 0";

			$tfields = implode( ',', $tfields );

			if ( $tfields ) {
				$sql =
					"SELECT *,\$depth AS *depth FROM (" .
						"TRAVERSE $tfields FROM $from " .
						"WHILE ($depthCond AND (@class='Item' OR (@ETCOND@)))" .
					") WHERE @class='Item' AND @ICOND@";
			} else {
				// Just grab the root items
				$sql = "$from AND @ICOND@";
			}
		} else {
			throw new ParseException( "Invalid index query: $s" );
		}

		// Skip past the stuff handled above
		$rest = substr( $rest, strlen( $m[0] ) );

		// Default conditions
		$rankCond = 'rank >= 0';
		$qualifyCond = '';

		$limit = 0;
		$eClaimCond = '';
		$iClaimCond = '';
		while ( strlen( $rest ) ) {
			$token = self::consumeWord( $rest );
			// Check if there is a RANK condition
			if ( $token === 'RANK' ) {
				$rank = self::consumePair( $rest, '()' );
				if ( $rank === 'best' ) {
					$rankCond = "best=1";
				} elseif ( $rank === 'deprecated' ) { // performance
					throw new ParseException( "rank=deprecated filter is not supported" );
				} elseif ( isset( self::$rankMap[$rank] ) ) {
					$rankCond = "rank=" . self::$rankMap[$rank];
				} else {
					throw new ParseException( "Bad rank: '$rank'" );
				}
			// Check if there is a QUALIFY condition
			} elseif ( $token === 'QUALIFY' ) {
				if ( $qualiferPrefix === null ) {
					throw new ParseException( "Index query does not support qualifiers: $s" );
				}
				$statement = self::consumePair( $rest, '()' );
				$qualifyCond = '(' . self::parseFilters( $statement, $qualiferPrefix ) . ')';
			// Check if there is a WHERE condition
			} elseif ( $token === 'WHERE' ) {
				$statement = self::consumePair( $rest, '()' );
				$iClaimCond = self::parseFilters( $statement, 'claims' );
				$eClaimCond = self::parseFilters( $statement, 'out.claims' );
			// Check if there is a LIMI condition
			} elseif ( $token === 'LIMIT' ) {
				$limit = (int)self::consumePair( $rest, '()' );
			} else {
				throw new ParseException( "Unexpected token: $token" );
			}
		}

		if ( $limit > 0 ) {
			$sql .= " LIMIT $limit";
		}

		// Apply item vertex filtering conditions
		$itemCond = 'deleted IS NULL';
		if ( $iClaimCond ) {
			$itemCond .= " AND $iClaimCond";
		}
		$sql = str_replace( '@ICOND@', $itemCond, $sql );
		$sql = str_replace( '@IDCOND@', 'deleted IS NULL', $sql );

		// Apply edge filtering conditions for non-recursive queries
		$edgeCond = implode( ' AND ', array_filter(
			array( $rankCond, $qualifyCond, 'out.deleted IS NULL', $eClaimCond ),
			'strlen'
		) );
		$sql = str_replace( '@ECOND@', $edgeCond, $sql );
		// Apply edge filtering conditions for recursive queries (defer claim conditions)
		$edgeCondTraverse = implode( ' AND ', array_filter(
			array( $rankCond, $qualifyCond, 'out.deleted IS NULL' ),
			'strlen'
		) );
		$sql = str_replace( '@ETCOND@', $edgeCondTraverse, $sql );

		if ( strlen( $rest ) ) {
			throw new ParseException( "Excess set statements: $rest" );
		}

		return $sql;
	}

	/**
	 * Parse things like "HPwIV[X:A,C-D] AND (HPwQV[X:Y] OR HPwQV[X:Y])"
	 *
	 * @param string $s
	 * @param string $claimPrefix
	 * @return string Orient SQL
	 */
	protected static function parseFilters( $s, $claimPrefix ) {
		$rest = trim( $s );

		$junction = null; // AND/OR

		$m = array();
		$where = array();
		while ( strlen( $rest ) ) {
			if ( $rest[0] === '(' ) {
				$statement = self::consumePair( $rest , '()' );
				$where[] = self::parseFilters( $statement, $claimPrefix );
			} elseif ( preg_match( '/^(NOT)\s+\(/', $rest, $m ) ) {
				$operator = $m[1];
				$rest = substr( $rest, strlen( $m[0] ) - 1 );
				$statement = self::consumePair( $rest , '()' );
				$where[] = 'NOT (' . self::parseFilter( $statement, $claimPrefix ) . ')';
			} elseif ( preg_match( '/^(AND|OR)\s/', $rest, $m ) ) {
				if ( $junction && $m[1] !== $junction ) {
					// "(A AND B OR C)" is confusing and requires precendence order
					throw new ParseException( "Unparsable: $s" );
				}
				$junction = $m[1];
				$rest = substr( $rest, strlen( $m[0] ) );
			} else {
				$token = self::consumeWord( $rest );
				$args = self::consumePair( $rest, '[]' );
				$where[] = self::parseFilter( "{$token}[{$args}]", $claimPrefix );
			}
			$rest = ltrim( $rest );
		}

		if ( !$where ) {
			throw new ParseException( "Unparsable: $s" );
		} elseif ( strlen( $rest ) ) {
			throw new ParseException( "Excess filter statements found: $rest" );
		}

		return $junction ? implode( " $junction ", $where ) : $where[0];
	}

	/**
	 * Parse things like "HPwIV[X:A,C-D]"
	 *
	 * See https://github.com/orientechnologies/orientdb/wiki/SQL-Where
	 * Lack of full "NOT" operator support means we have to be careful.
	 *
	 * @TODO: add "rank" filter support to "claim" WHERE with Orient 2.1.
	 * See https://github.com/orientechnologies/orientdb/issues/513 for
	 * better field condition piping support.
	 *
	 * @param string $s
	 * @param string $claimPrefix
	 * @return string Orient SQL
	 */
	protected static function parseFilter( $s, $claimPrefix ) {
		$where = array();

		$s = trim( $s );

		$m = array();
		$float = self::RE_FLOAT;
		$date = self::RE_DATE;
		$dlist = '(?:\d+,?)+';
		// @note: in OrientDB, if field b is an array, then a.b.c=5 scans all
		// the items in b to see if any has c=5. This applies to qualifiers.PX
		// and searches on other properties than the one the index was used for.
		if ( preg_match( "/^(HPwNoV|HPwSomeV|HPwAnyV)\[($dlist)\]$/", $s, $m ) ) {
			$class = $m[1];
			if ( $class === 'HPwNoV' ) {
				$stype = "['novalue']";
			} elseif ( $class === 'HPwSomeV' ) {
				$stype = "['somevalue']";
			} else {
				$stype = "['value','somevalue']";
			}
			$or = array();
			foreach ( explode( ',', $m[2] ) as $pId ) {
				$or[] = "{$claimPrefix}['$pId'] contains (snaktype in $stype)";
			}
			$where[] = '(' . implode( ' OR ', $or ) . ')';
		} elseif ( preg_match( "/^HPwV\[(\d+):([^]]+)\]$/", $s, $m ) ) {
			$pId = $m[1];
			$or = array();
			foreach ( explode( ',', $m[2] ) as $val ) {
				// Floats: trivial range support
				if ( preg_match( "/^($float)\s+TO\s+($float)$/", $val, $m ) ) {
					$or[] = "{$claimPrefix}['$pId'] contains (datavalue between {$m[1]} and {$m[2]})";
				} elseif ( preg_match( "/^(GT|GTE|LT|LTE)\s+($float)$/", $val, $m ) ) {
					$op = self::$compareOpMap[$m[1]];
					$or[] = "{$claimPrefix}['$pId'] contains (datavalue $op {$m[2]})";
				} elseif ( preg_match( "/^$float$/", $val ) ) {
					$or[] = "{$claimPrefix}['$pId'] contains (datavalue = $val)";
				// Dates: formatted like -20001-01-01T00:00:00Z and +20001-01-01T00:00:00Z
				// can be compared lexographically for simplicity due to padding
				} elseif ( preg_match( "/^($date)\s+TO\s+($date)/", $val, $m ) ) {
					list( , $a, $b ) = $m;
					$or[] = "{$claimPrefix}['$pId'] contains (datavalue between '$a' and '$b')";
				} elseif ( preg_match( "/^(GT|GTE|LT|LTE)\s+($date)$/", $val, $m ) ) {
					$op = self::$compareOpMap[$m[1]];
					$or[] = "{$claimPrefix}['$pId'] contains (datavalue $op '{$m[2]}')";
				} elseif ( preg_match( "/^$date$/", $val ) ) {
					$or[] = "{$claimPrefix}['$pId'] contains (datavalue = '$val')";
				// Strings: exact match only
				} elseif ( preg_match( "/^\$\d+$/", $val ) ) {
					$or[] = "{$claimPrefix}['$pId'] contains (datavalue = '$val')";
				} else {
					throw new ParseException( "Invalid quantity or range: $val" );
				}
			}
			$where[] = '(' . implode( ' OR ', $or ) . ')';
		} elseif ( preg_match( "/^haslinks\[((?:\\$\d+,?)+)\]\$/", $s, $m ) ) {
			if ( preg_match( '/(^|\.)qlfrs$/', $claimPrefix ) ) {
				throw new ParseException( "Invalid qualifier condition: $s" );
			}
			$valIds = explode( ',', $m[1] );
			$or = array();
			foreach ( $valIds as $valId ) {
				$or[] = "sitelinks CONTAINSKEY $valId";
			}
			$where[] = '(' . implode( ' OR ', $or ) . ')';
		} else {
			throw new ParseException( "Invalid filter or qualifier condition: $s" );
		}

		if ( !$where ) {
			throw new ParseException( "Bad filter or qualifier condition: $s" );
		}

		return implode( ' AND ', $where );
	}

	/**
	 * Parse stuff like "A,B,-C TO D" for numeric index based queries
	 *
	 * Use of parentheses is very specific for the query planner
	 *
	 * @param string $field
	 * @param string $s
	 * @param string $cond Additional condition for each range/value
	 * @return string Orient SQL
	 */
	protected static function parseRangeDive( $field, $s, $cond = '' ) {
		$where = array();

		$cond = $cond ? "$cond AND " : "";

		$m = array();
		$float = self::RE_FLOAT;
		foreach ( explode( ',', $s ) as $v ) {
			$v = trim( $v );
			if ( preg_match( "/^$float\$/", $v ) ) {
				$where[] = "{$cond}{$field}=$v";
			} elseif ( preg_match( "/^($float)\s+TO\s+($float)\$/", $v, $m ) ) {
				$where[] = "{$cond}{$field} BETWEEN {$m[1]} AND {$m[2]}";
			} elseif ( preg_match( "/^(GT|GTE|LT|LTE)\s+($float)\$/", $v, $m ) ) {
				$op = self::$compareOpMap[$m[1]];
				$where[] = "{$cond}{$field} $op {$m[2]}";
			} else {
				throw new ParseException( "Unparsable: $v" );
			}
		}

		if ( !$where ) {
			throw new ParseException( "Unparsable range: $s" );
		}

		return '(' . implode( ') OR (', $where ) . ')';
	}

	/**
	 * Parse stuff like "A,B,-C TO D" for timestamp index based queries
	 *
	 * Use of parentheses is very specific for the query planner
	 *
	 * @param string $field
	 * @param string $s
	 * @param string $cond Additional condition for each range/value
	 * @return string Orient SQL
	 */
	protected static function parsePeriodDive( $field, $s, $cond = '' ) {
		$where = array();

		$cond = $cond ? "$cond AND " : "";

		$m = array();
		foreach ( explode( ',', $s ) as $v ) {
			$v = trim( $v );
			if ( preg_match( "/^([^\s]+)\s+TO\s+([^\s]+)$/", $v, $m ) ) {
				list( , $at, $bt ) = $m;
				$at = WdqUtils::getUnixTimeFromISO8601( $at );
				$bt = WdqUtils::getUnixTimeFromISO8601( $bt );
				if ( $at === false || $bt === false ) {
					throw new ParseException( "Unparsable timestamps: $v" );
				}
				$where[] = "{$cond}{$field} BETWEEN $at AND $bt";
			} elseif ( preg_match( "/^(GT|GTE|LT|LTE)\s+([^\s]+)$/", $v, $m ) ) {
				$op = self::$compareOpMap[$m[1]];
				$t = WdqUtils::getUnixTimeFromISO8601( $m[2] );
				if ( $t === false ) {
					throw new ParseException( "Unparsable timestamps: $v" );
				}
				$where[] = "{$cond}{$field} $op $t";
			} else {
				$t = WdqUtils::getUnixTimeFromISO8601( $v );
				if ( $t === false ) {
					throw new ParseException( "Unparsable timestamps: $v" );
				}
				$where[] = "{$cond}{$field}=$t";
			}
		}

		if ( !$where ) {
			throw new ParseException( "Unparsable period: $s" );
		}

		return '(' . implode( ') OR (', $where ) . ')';
	}

	/**
	 * Parse stuff like "(AROUND A B C),(AROUND A B C)"
	 *
	 * @param string $s
	 * @return string Orient SQL
	 */
	protected static function parseAroundDive( $s ) {
		$where = array();

		$float = self::RE_FLOAT;
		$pfloat = self::RE_UFLOAT;

		$m = array();
		foreach ( explode( ',', $s ) as $v ) {
			$v = trim( $v );
			if ( preg_match( "/^AROUND\s+($float)\s+($float)\s+($pfloat)\$/", $v, $m ) ) {
				list( , $lat, $lon, $dist ) = $m;
				$where[] = "([lat,lon,\$spatial] NEAR [$lat,$lon,{\"maxDistance\":$dist}])";
			} else {
				throw new ParseException( "Unparsable: $v" );
			}
		}

		if ( !$where ) {
			throw new ParseException( "Unparsable area: $s" );
		}

		return count( $where ) > 1 ? '(' . implode( ' OR ', $where ) . ')' : $where[0];
	}

	/**
	 * @param string $field
	 * @param array $vals Must not be empty
	 * @return string
	 */
	protected static function sqlIN( $field, array $vals ) {
		// https://github.com/orientechnologies/orientdb/issues/3150
		return count( $vals ) == 1
			? "$field=" . $vals[0]
			: "$field IN [" . implode( ',', $vals ) . "]";
	}

	/**
	 * @param array $conditions Must not be empty
	 * @return string
	 */
	protected static function sqlOR( array $conditions ) {
		return count( $conditions ) > 1
			? '(' . implode( ') OR (', $conditions ) . ')'
			: $conditions[0];
	}

	/**
	 * Get the first verb and consume it (non-whitespace, non-bracket chars)
	 *
	 * @param string $s
	 * @return string Token
	 */
	protected static function consumeWord( &$s ) {
		$orig = $s;
		$s = ltrim( $s );
		$token = substr( $s, 0, strcspn( $s, " \t\n\r({[" ) );
		if ( !strlen( $token ) ) {
			throw new ParseException( "Expected token: $orig" );
		}
		$s = ltrim( substr( $s, strlen( $token ) ) );
		return $token;
	}

	/**
	 * Take a string starting with a open bracket and find the closing bracket,
	 * returning the contents inside the brackets and consuming that part of $s.
	 *
	 * @param string $s
	 * @param string $pair e.g. "()", "[]", "{}"
	 * @return string
	 */
	protected static function consumePair( &$s, $pair ) {
		$orig = $s;
		list ( $open, $close ) = $pair;

		if ( $s[0] !== $open ) {
			throw new ParseException( "Expected $pair pair: $orig" );
		}

		$depth = 1;
		$matching = '';
		$n = strlen( $s );
		for ( $i=1; $i < $n; ++$i ) {
			if ( $s[$i] === $open ) {
				++$depth;
			} elseif ( $s[$i] === $close ) {
				if ( --$depth == 0 ) {
					break;
				}
			}
			$matching .= $s[$i];
		}

		if ( $depth !== 0 ) {
			throw new ParseException( "Unparsable: $orig" );
		}

		$s = substr( $s, $i + 1 ); // consume the matching section and brackets
		$s = ltrim( $s );

		return trim( $matching );
	}

	/**
	 * Replace all quoted values with $X symbols
	 *
	 * @param string $s
	 * @return array (new string, replacement map)
	 */
	protected static function stripQuotedStrings( $s ) {
		$pos = 0;
		$map = array();
		$s = preg_replace_callback(
			array( '/"((?:[^"\\\\]|\\\\.)*)"/m', "/'((?:[^'\\\\]|\\\\.)*)'/m" ),
			function( array $m ) use ( &$pos, &$map ) {
				++$pos;
				$val = stripcslashes( $m[1] );
				// https://github.com/orientechnologies/orientdb/issues/1275
				$map['$' . $pos] = "'" . addcslashes( $m[1], "'" ) . "'";
				return '$' . $pos;
			},
			$s
		);

		return array( $s, $map );
	}

	/**
	 * Replace all $X symbols with single quoted and escaped strings
	 *
	 * @param string $s
	 * @param array $map Replacement map
	 * @return string
	 */
	protected static function unstripQuotedStrings( $s, array $map ) {
		return str_replace( array_keys( $map ), array_values( $map ), $s );
	}
}

class ParseException extends Exception {}
