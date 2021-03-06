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
 * FROM {HPwIVWeb[$SOMEITEMS] OUT[40] MAXDEPTH(3)}
 * GIVEN (
 *		$SOME_ITEMS = {HPwIVWeb[X] OUT[X,Y]}
 *		$OTHER_ITEMS = {HPwIVWeb[$SOME_ITEMS] IN[X,Y]}
 *		$ITEMS_A = {HPwQV[X:A] DESC RANK(best) QUALIFY(HPwV[X:Y]) WHERE(HPwV[X:Y])}
 *		$ITEMS_B = {HPwQV[Y:B TO C, GTE D]}
 *		$BOTH_AB = UNION($ITEMS_A,$ITEMS_B)
 *		$DIFF_AB = DIFFERENCE($ITEMS_A,$ITEMS_B)
 *		$INTERSECT_AB = INTERSECT($ITEMS_A,$ITEMS_B)
 *		$SET_A = {HPwTV[X:D1 to D2] ASC RANK(best) QUALIFY(HPwV[X:Y]) WHERE(HPwV[X:Y;rank=best]) LIMIT(100)}
 *		$SET_B = {HPwCV[X:AROUND A B C,AROUND A B C] RANK(best) QUALIFY(HPwV[X:Y]) WHERE(HPwV[X:Y])}
 *		$SET_C = {HPwSV[X:"cat"] RANK(best) REFERENCE(HPwV[X:Y]) WHERE(HPwV[X:Y])}
 *		$STUFF = {items[2,425,62,23]}
 *		$WLINK = {HPwIV[X:A] WHERE(link[X,Y])}
 * )
 */
class WdqQueryParser {
	const RE_FLOAT = '[-+]?[0-9]*\.?[0-9]+';
	const RE_UFLOAT = '\+?[0-9]*\.?[0-9]+';
	const RE_DATE = '(-|\+)0*(\d+)-(\d\d)-(\d\d)T0*(\d\d):0*(\d\d):0*(\d\d)Z';
	const RE_VAR = '\$[a-zA-Z_]+';

	const FLD_BASIC = '/^(id|sitelinks|labels|claims)$/';
	const FLD_MAP = '/^((?:sitelinks|labels)\[\$?\d+\])\s+AS\s+([a-zA-Z][a-zA-Z0-9_]*)$/';
	const FLD_CLAIMS = '/^(claims\[\$?\d+\])(?:\[rank\s*=\s*([a-z]+)\])?\s+AS\s+([a-zA-Z][a-zA-Z0-9_]*)$/';

	/** @var array Used for getting cross products from Edge class */
	const OUT_ITEM_FIELDS = 'out AS @rid,"Item" AS @class,oid AS id,out AS fullitem';

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
	public static function parse( $s, $timeout = 5000, $limit = 1e9 ) {
		$s = trim( $s );

		// Remove any single-line comments
		$s = preg_replace( '/^\s*#.*$/m', '', $s );
		// Amour all quoted string values for easy parsing
		list( $s, $map ) = self::stripQuotedStrings( $s );
		$rest = $s;

		// Get the properties selecteed
		if ( $rest == '' || $rest[0] !== '(' ) {
			throw new WdqParseException( "Missing projections: $s" );
		}
		$props = self::consumePair( $rest, '()' );

		// Get the FROM query
		$token = self::consumeWord( $rest );
		if ( $token !== 'FROM' ) {
			throw new WdqParseException( "Expected FROM: $s" );
		} elseif ( $rest === '' ) {
			throw new WdqParseException( "Missing FROM query '$s'" );
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
			throw new WdqParseException( "Excess query statements found: $rest" );
		}

		// Validate the properties selected.
		// Enforce that [] fields use aliases (they otherwise get called out1, out2...)
		// @note: propagate certain * fields from subqueries that could be useful
		$proj = array( '*depth', '*distance', '*timevalue', '*value' );
		foreach ( explode( ',', $props ) as $prop ) {
			$prop = trim( $prop );
			$m = array();
			if ( $prop === 'id' ) {
				$proj[] = "id"; // make use "oid AS id" for performance
			} elseif ( preg_match( self::FLD_BASIC, $prop ) ) {
				$proj[] = "@rid.{$prop} AS {$prop}";
			} elseif ( preg_match( self::FLD_MAP, $prop, $m ) ) {
				$proj[] = "@rid.{$m[1]} AS {$m[2]}";
			} elseif ( preg_match( self::FLD_CLAIMS, $prop, $m ) ) {
				$field = "@rid.{$m[1]}";
				if ( empty( $m[2] ) ) {
					// no rank filter
				} elseif ( $m[2] === 'best' ) {
					$field .= "[best=1]";
				} elseif ( isset( self::$rankMap[$m[2]] ) ) {
					$field .= "[rank=" . self::$rankMap[$m[2]] . "]";
				} else {
					throw new WdqParseException( "Bad rank: '{$m[2]}'" );
				}
				$proj[] = "$field AS {$m[3]}";
			} else {
				throw new WdqParseException( "Invalid field: $prop" );
			}
		}
		$proj = implode( ',', $proj );

		// Get the main query conditions
		$query = self::consumeSetOp( $setQuery, $givenMap );
		if ( strlen( $setQuery ) ) {
			throw new WdqParseException( "Excess query statements found: $rest" );
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
			if ( preg_match( "/^" . self::RE_VAR . "$/", $variable ) ) {
				if ( isset( $map[$variable] ) ) {
					throw new WdqParseException( "Cannot mutate variable '$variable'" );
				}
				$token = self::consumeWord( $rest );
				if ( $token !== '=' ) {
					throw new WdqParseException( "Expected =: $rest" );
				}
				$map[$variable] = self::consumeSetOp( $rest, $map, '' );
			} else {
				throw new WdqParseException( "Bad variable: $variable" );
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
					throw new WdqParseException( "$operation expects variable arguments" );
				} elseif ( !isset( $givenMap[$var] ) ) {
					throw new WdqParseException( "$operation given undefined variable '$var'" );
				}
				$lets["\$t{$i}"] = "\$t{$i} = {$givenMap[$var]}";
				++$i;
			}
			if ( !$lets ) {
				throw new WdqParseException( "$operation missing arguments: $rest" );
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

		throw new WdqParseException( "Unparsable set: $orig" );
	}

	/**
	 * Parse things like
	 * "HPwIV[X:A,B,C] QUALIFY(HPwQV[X:Y]) WHERE(~HPwIV[X:A,C-D] AND (HPwQV[X:Y] OR HPwQV[X:Y]))"
	 *
	 * @note: results should be distinct items, but try to avoid
	 * https://github.com/orientechnologies/orientdb/issues/2376
	 * and https://github.com/orientechnologies/orientdb/issues/3244
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
		// Reference conditions normally applied in edge class WHERE
		$referencePrefix = 'refs';

		$m = array();
		$float = self::RE_FLOAT;
		$dlist = '(?:\d+,?)+';
		$ofields = self::OUT_ITEM_FIELDS; // out.* fields to get Item fields
		$gvar = self::RE_VAR; // item IDs generators via GIVEN

		// @TODO: dedup results (https://github.com/orientechnologies/orientdb/issues/3359)

		// Get the primary select condition (using some index)...
		// @note: watch out for https://bugs.php.net/bug.php?id=51881
		// Case A: queries to list items that use a property or item
		if ( preg_match( "/^(HPwSomeV|HIaPV|HPwIV|HPwSV|HPwQV|HPwTV|HPwCV)\[(\d+)\]\s*(?:CONTINUE\((\d+)\)\s*)?/", $rest, $m ) ) {
			$class = $m[1];
			$id = $m[2]; // property ID or item ID
			$cont = !empty( $m[3] ) ? "AND oid > {$m[3]}" : "";
			$orderBy = "ORDER BY iid ASC,oid ASC";
			$sql = "SELECT $ofields FROM $class WHERE iid=$id $cont AND @ECOND@ $orderBy";
		// Case B: queries that list items with certain values for a property
		} elseif ( preg_match( "/^HPwIV\[(\d+):(\d+)\]\s*(?:CONTINUE\((\d+)\)\s*)?/", $rest, $m ) ) {
			$pId = $m[1];
			$iId = $m[2];
			$cont = !empty( $m[3] ) ? "AND oid > {$m[3]}" : "";
			$cond = "(iid=$iId AND pid=$pId)";
			$orderBy = "ORDER BY iid ASC,pid ASC,oid ASC"; // parenthesis needed for index usage
			$sql = "SELECT $ofields FROM HIaPV WHERE $cond $cont AND @ECOND@ $orderBy";
		} elseif ( preg_match( "/^HPwSV\[(\d+):(\\$\d+)\]\s*(?:CONTINUE\((\d+)\)\s*)?/", $rest, $m ) ) {
			$pId = $m[1];
			$valId = $m[2];
			$cont = !empty( $m[3] ) ? "AND oid > {$m[3]}" : "";
			$cond = "(iid=$pId AND val=$valId)"; // parenthesis needed for index usage
			$orderBy = "ORDER BY oid ASC"; // give a stable ordering when distributed
			$sql = "SELECT $ofields FROM HPwSV WHERE $cond $cont AND @ECOND@ $orderBy";
		} elseif ( preg_match( "/^(HPwQV|HPwTV)\[(\d+):([^],]+)\]\s*(ASC|DESC)?\s*(?:SKIP\((\d+)\)\s*)?/", $rest, $m ) ) {
			$class = $m[1];
			$pId = $m[2];
			// Support only one condition due to query planner (commas disallowed above)
			if ( $class === 'HPwTV' ) {
				$valField = '*timevalue';
				$cond = self::parsePeriodDive( 'val', $m[3], "iid=$pId" );
			} else {
				$valField = '*value';
				$cond = self::parseRangeDive( 'val', $m[3], "iid=$pId" );
			}
			$order = !empty( $m[4] ) ? $m[4] : 'ASC';
			$skip = !empty( $m[5] ) ? "SKIP {$m[5]}" : "";
			// @note: relies on DB picking "from first scanned" values for non-oid fields
			$fields = "$ofields,val AS $valField";
			// @note: could be several claims...use the first (in order); good with rank=best
			// @note: with several claims, *value may change depending on ASC vs DESC
			$orderBy = "ORDER BY iid $order,val $order,oid $order";
			$sql = "SELECT $fields FROM $class WHERE $cond AND @ECOND@ $orderBy $skip";
		} elseif ( preg_match( "/^HPwCV\[(\d+):([^],]+)\]\s*(?:SKIP\((\d+)\)\s*)?/", $rest, $m ) ) {
			$pId = $m[1];
			// Support only one condition due to query planner (commas disallowed above)
			$cond = self::parseAroundDive( $m[2] );
			$skip = !empty( $m[3] ) ? "SKIP {$m[3]}" : "";
			// @note: relies on DB picking "from first scanned" values for non-oid fields
			$fields = "$ofields,\$distance AS *distance";
			$orderBy = "ORDER BY *distance ASC,oid ASC"; // give a stable ordering when distributed
			// @note: could be several claims...use the closest one; good with rank=best
			$sql = "SELECT $fields FROM HPwCV WHERE $cond AND iid=$pId AND @ECOND@ $orderBy $skip";
		// Case C: queries that fetch items by ID or sitelinks
		} elseif ( preg_match( "/^items\[($dlist)\]\s*/", $rest, $m ) ) {
			$iIds = explode( ',', $m[1] );
			$inClause = self::sqlIN( 'id', $iIds );
			$sql = "SELECT FROM Item WHERE $inClause AND @ICOND@";
			$qualiferPrefix = null; // makes no sense
			$referencePrefix = null; // same
		} elseif ( preg_match( "/^linkedto\[((?:\\$\d+,?)+)\]\s*/", $rest, $m ) ) {
			$valIds = explode( ',', $m[1] );
			$or = array();
			foreach ( $valIds as $valId ) {
				$or[] = "sitelinks containsvalue $valId";
			}
			$or = self::sqlOR( $or );
			$sql = "SELECT FROM Item WHERE $or AND @ICOND@";
			$qualiferPrefix = null; // makes no sense
			$referencePrefix = null; // same
		// Case D: recursive item queries
		} elseif ( preg_match(
			"/^HPwIVWeb\[($dlist|$gvar)\](?:\s+OUT\[($dlist)\])?(?:\s+IN\[($dlist)\])?(?:\s+MAXDEPTH\((\d+)\))?\s*/", $rest, $m )
		) {
			if ( preg_match( "/^$dlist$/", $m[1] ) ) {
				$iIds = explode( ',', $m[1] );
				$from = "(SELECT FROM Item WHERE " . self::sqlIN( 'id', $iIds ) . " AND @IDCOND@)";
			} else {
				$variable = $m[1];
				if ( !isset( $givenMap[$variable] ) ) {
					throw new WdqParseException( "No '$variable' entry in GIVEN clause: $s" );
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
				$tfields[] = "Item.fullitem.out_HIaPV[pid=$pId]"; // for generators
				$tfields[] = "HIaPV.in";
			}
			foreach ( $pIdsRV as $pId ) {
				// Edges followed in reverse direction are filtered on certain PIDs
				$tfields[] = "Item.in_HIaPV[pid=$pId]";
				$tfields[] = "Item.fullitem.in_HIaPV[pid=$pId]"; // for generators
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
			throw new WdqParseException( "Invalid index query: $s" );
		}

		// Skip past the stuff handled above
		$rest = substr( $rest, strlen( $m[0] ) );

		// Default conditions
		$rankCond = 'rank >= 0';
		$qualifyCond = '';
		$referenceCond = '';

		$limit = 0;
		$eClaimCond = '';
		$iClaimCond = '';
		while ( strlen( $rest ) ) {
			$token = self::consumeWord( $rest );
			// Check if there is a RANK condition
			if ( $token === 'RANK' ) {
				$rank = self::consumePair( $rest, '()' );
				$rankCond = self::parseRankCond( $rank );
			// Check if there is a QUALIFY condition
			} elseif ( $token === 'QUALIFY' ) {
				if ( $qualiferPrefix === null ) {
					throw new WdqParseException( "Index query does not support qualifiers: $s" );
				}
				$statement = self::consumePair( $rest, '()' );
				$qualifyCond = self::parseFilters( $statement, $qualiferPrefix );
			// Check if there is a REFERENCE condition
			} elseif ( $token === 'REFERENCE' ) {
				if ( $referencePrefix === null ) {
					throw new WdqParseException( "Index query does not support references: $s" );
				}
				$statement = self::consumePair( $rest, '()' );
				$referenceCond = self::parseFilters( $statement, $referencePrefix );
			// Check if there is a WHERE condition
			} elseif ( $token === 'WHERE' ) {
				$statement = self::consumePair( $rest, '()' );
				$iClaimCond = self::parseFilters( $statement, '@rid.claims' );
				$eClaimCond = self::parseFilters( $statement, 'out.claims' );
			// Check if there is a LIMI condition
			} elseif ( $token === 'LIMIT' ) {
				$limit = (int)self::consumePair( $rest, '()' );
			} else {
				throw new WdqParseException( "Unexpected token: $token" );
			}
		}

		if ( $limit > 0 ) {
			$sql .= " LIMIT $limit";
		}

		// Apply item vertex filtering conditions
		$itemCond = '@rid.deleted IS NULL';
		$sql = str_replace( '@IDCOND@', $itemCond, $sql );
		if ( $iClaimCond ) {
			$itemCond .= " AND $iClaimCond";
		}
		$sql = str_replace( '@ICOND@', $itemCond, $sql );

		// Apply edge filtering conditions for non-recursive queries
		$edgeCond = implode( ' AND ', array_filter(
			array( $rankCond, $qualifyCond, $referenceCond, 'odeleted IS NULL', $eClaimCond ),
			'strlen'
		) );
		$sql = str_replace( '@ECOND@', $edgeCond, $sql );
		// Apply edge filtering conditions for recursive queries (defer claim conditions)
		$edgeCondTraverse = implode( ' AND ', array_filter(
			array( $rankCond, $qualifyCond, $referenceCond, 'odeleted IS NULL' ),
			'strlen'
		) );
		$sql = str_replace( '@ETCOND@', $edgeCondTraverse, $sql );

		if ( strlen( $rest ) ) {
			throw new WdqParseException( "Excess set statements: $rest" );
		}

		return $sql;
	}

	/**
	 * Parse things like "HPwIV[X:A,C-D] AND (HPwQV[X:Y] OR HPwQV[X:Y])"
	 *
	 * See https://github.com/orientechnologies/orientdb/wiki/SQL-Where
	 * Lack of full "NOT" operator support means we have to be careful.
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
			} elseif ( preg_match( '/^(NOT)\s*\(/', $rest, $m ) ) {
				$operator = $m[1];
				$rest = substr( $rest, strlen( $m[0] ) - 1 );
				$statement = self::consumePair( $rest , '()' );
				$where[] = 'NOT ' . self::parseFilter( $statement, $claimPrefix );
			} elseif ( preg_match( '/^(AND|OR)\s/', $rest, $m ) ) {
				if ( $junction && $m[1] !== $junction ) {
					// "(A AND B OR C)" is confusing and requires precendence order
					throw new WdqParseException( "Unparsable: $s" );
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
			throw new WdqParseException( "Unparsable: $s" );
		} elseif ( strlen( $rest ) ) {
			throw new WdqParseException( "Excess filter statements found: $rest" );
		}

		return $junction ? '(' . implode( " $junction ", $where ) . ')' : $where[0];
	}

	/**
	 * Parse things like "HPwIV[X:A,C-D]"
	 *
	 * @param string $s
	 * @param string $claimPrefix
	 * @return string Orient SQL
	 */
	protected static function parseFilter( $s, $claimPrefix ) {
		$where = '';

		$s = trim( $s );

		$m = array();
		$float = self::RE_FLOAT;
		$date = self::RE_DATE;
		$dlist = '(?:\d+,?)+';
		// @note: in OrientDB, if field b is an array, then a.b.c=5 scans all
		// the items in b to see if any has c=5. This applies to qualifiers.PX
		// and searches on other properties than the one the index was used for.
		if ( preg_match( "/^(HPwNoV|HPwSomeV|HPwAnyV)\[($dlist)(?:;rank=([a-z]+))?\]$/", $s, $m ) ) {
			$class = $m[1];
			$rank = !empty( $m[3] ) ? $m[3] : null;

			$contains = array();
			if ( $class === 'HPwNoV' ) {
				$contains[] = "snaktype = 'novalue'";
			} elseif ( $class === 'HPwSomeV' ) {
				$contains[] = "snaktype = 'somevalue'";
			} else {
				$contains[] = "snaktype IN ['value','somevalue']";
			}
			$contains[] = self::parseRankCond( $rank );
			$contains = implode( ' AND ', $contains );

			$or = array();
			foreach ( explode( ',', $m[2] ) as $pId ) {
				$or[] = "{$claimPrefix}[$pId] contains ($contains)";
			}
			$where = '(' . implode( ' OR ', $or ) . ')';
		} elseif ( preg_match( "/^HPwV\[(\d+):([^];]+)(?:;rank=([a-z]+))?\]$/", $s, $m ) ) {
			$pId = $m[1];
			$rank = !empty( $m[3] ) ? $m[3] : null;
			$field = "{$claimPrefix}[$pId]";
			$rCond = self::parseRankCond( $rank );

			$or = array();
			foreach ( explode( ',', $m[2] ) as $val ) {
				// Floats: trivial range support
				if ( preg_match( "/^($float)\s+TO\s+($float)$/", $val, $m ) ) {
					$or[] = "{$field} contains (datavalue between {$m[1]} and {$m[2]} and $rCond)";
				} elseif ( preg_match( "/^(GT|GTE|LT|LTE)\s+($float)$/", $val, $m ) ) {
					$op = self::$compareOpMap[$m[1]];
					$or[] = "{$field} contains (datavalue $op {$m[2]} and $rCond)";
				} elseif ( preg_match( "/^$float$/", $val ) ) {
					$or[] = "{$field} contains (datavalue = $val and $rCond)";
				// Dates: formatted like -20001-01-01T00:00:00Z and +20001-01-01T00:00:00Z
				// can be compared lexographically for simplicity due to padding
				} elseif ( preg_match( "/^($date)\s+TO\s+($date)/", $val, $m ) ) {
					list( , $a, $b ) = $m;
					$or[] = "{$field} contains (datavalue between '$a' and '$b' and $rCond)";
				} elseif ( preg_match( "/^(GT|GTE|LT|LTE)\s+($date)$/", $val, $m ) ) {
					$op = self::$compareOpMap[$m[1]];
					$or[] = "{$field} contains (datavalue $op '{$m[2]}' and $rCond)";
				} elseif ( preg_match( "/^$date$/", $val ) ) {
					$or[] = "{$field} contains (datavalue = '$val' and $rCond)";
				// Strings: exact match only
				} elseif ( preg_match( "/^\$\d+$/", $val ) ) {
					$or[] = "{$field} contains (datavalue = '$val' and $rCond)";
				} else {
					throw new WdqParseException( "Invalid quantity or range: $val" );
				}
			}
			$where = '(' . implode( ' OR ', $or ) . ')';
		} elseif ( preg_match( "/^haslinks\[((?:\\$\d+,?)+)\]\$/", $s, $m ) ) {
			if ( preg_match( '/(^|\.)qlfrs$/', $claimPrefix ) ) {
				throw new WdqParseException( "Invalid qualifier condition: $s" );
			}
			$valIds = explode( ',', $m[1] );
			$or = array();
			foreach ( $valIds as $valId ) {
				$or[] = "sitelinks containskey $valId";
			}
			$where = '(' . implode( ' OR ', $or ) . ')';
		} else {
			throw new WdqParseException( "Invalid filter or qualifier condition: $s" );
		}

		if ( $where == ''  ) {
			throw new WdqParseException( "Bad filter or qualifier condition: $s" );
		}

		return $where;
	}

	/**
	 * Parse a rank condition to be used in WHERE or CONTAINS
	 * @param string $rank
	 * @return string
	 */
	protected static function parseRankCond( $rank ) {
		if ( $rank === 'best' ) {
			return "best = 1";
		} elseif ( $rank === 'deprecated' ) { // performance
			throw new WdqParseException( "rank=deprecated filter is not supported" );
		} elseif ( isset( self::$rankMap[$rank] ) ) {
			return "rank = " . self::$rankMap[$rank];
		} elseif ( $rank === null ) {
			return "rank >= 0"; // default
		}
		throw new WdqParseException( "Bad rank: '$rank'" );
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
				throw new WdqParseException( "Unparsable: $v" );
			}
		}

		if ( !$where ) {
			throw new WdqParseException( "Unparsable range: $s" );
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
				$at = WdqUtils::getUnixTimeFromISO8601( $at, 'WdqParseException' );
				$bt = WdqUtils::getUnixTimeFromISO8601( $bt, 'WdqParseException' );
				$where[] = "{$cond}{$field} BETWEEN $at AND $bt";
			} elseif ( preg_match( "/^(GT|GTE|LT|LTE)\s+([^\s]+)$/", $v, $m ) ) {
				$op = self::$compareOpMap[$m[1]];
				$t = WdqUtils::getUnixTimeFromISO8601( $m[2], 'WdqParseException' );
				$where[] = "{$cond}{$field} $op $t";
			} else {
				$t = WdqUtils::getUnixTimeFromISO8601( $v, 'WdqParseException' );
				$where[] = "{$cond}{$field}=$t";
			}
		}

		if ( !$where ) {
			throw new WdqParseException( "Unparsable period: $s" );
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
				throw new WdqParseException( "Unparsable: $v" );
			}
		}

		if ( !$where ) {
			throw new WdqParseException( "Unparsable area: $s" );
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
			throw new WdqParseException( "Expected token: $orig" );
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
			throw new WdqParseException( "Expected $pair pair: $orig" );
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
			throw new WdqParseException( "Unparsable: $orig" );
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
