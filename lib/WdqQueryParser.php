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
 * FROM
 * UNION(
 * 	DIFFERENCE(
 *		{HIaPVWeb[X] OUTGOING[X,Y] INCOMING[X,Y]}
 *		{HPwQV[X:A,B,-C TO D] DESC LIMIT(100)} )
 * 	UNION(
 * 		{HIaPV[X:A,B,C] WHERE(HPwV[X:A,C,D] AND (HPwV[X:Y TO Z] OR HPwV[X:Y]))}
 * 		{HPwQV[X:A,B,-C TO D] QUALIFY(HPwV[P,X,Y])}
 * 		INTERSECT(
 * 			{HIaPV[X:Y] WHERE(HPwV[X:Y] AND (HPwV[X:Y] OR HPwV[X:Y]))}
 * 			{HIaPV[X:Y] RANK(best) WHERE(HPwV[X:Y])}
 * 			{HIaPV[X:Y]}
 * 			{HIaPVWeb[X] OUTGOING[X,Y] INCOMING[X,Y]} )
 *      {HIaPV[X:Y] WHERE(NOT (HPwV[X:A,C,D]))}
 * 		{HPwQV[X:Y TO Z] ASC RANK(preferred) LIMIT(10)}
 * 		{HIaPVTree[X:Y] QUALIFY(HPwV[X:Y]) AND (HPwV[X:Y] OR HPwV[X:Y])) WHERE(link[X,Y])}
 *		{HIaPV[X:Y] QUALIFY(NOT (HPwV[X:A,C,D]))} )
 * 	INTERSECT(
 * 		{HIaPVWeb[X] OUTGOING[X,Y] INCOMING[X,Y] RANK(best)}
 * 		{HPwAnyV[X,Y,Z] RANK(preferred) QUALIFY(HPwV[X:"Y"])}
 * 		{HPwCV[X:(AROUND A B C),(AROUND A B C)] QUALIFY(HPwV[P:X]) WHERE(link[X,Y])} )
 * )
 */
class WdqQueryParser {
	const RE_FLOAT = '[-+]?[0-9]*\.?[0-9]+';
	const RE_UFLOAT = '\+?[0-9]*\.?[0-9]+';
	const RE_DATE = '(-|\+)0*(\d+)-(\d\d)-(\d\d)T0*(\d\d):0*(\d\d):0*(\d\d)Z';
	const FLD_BASIC = '/^(id|sitelinks|labels|claims)$/';
	const FLD_MAP = '/^((?:sitelinks|labels|claims)\[\$?\d+\])\s+AS\s+([a-zA-Z][a-zA-Z0-9_]*)$/';

	/** @var array */
	protected static $rankMap = array(
		'deprecated' => -1,
		'normal'     => 0,
		'preferred'  => 1
	);

	/**
	 * @param string $s
	 * @param integer $timeout
	 * @param integer $limit
	 * @return string
	 */
	public static function parse( $s, $timeout = 5000, $limit = 1000 ) {
		$s = trim( $s );
		// Amour all quoted string values for easy parsing
		list( $s, $map ) = self::stripQuotedStrings( $s );
		$rest = $s;

		// Get the properties selecteed
		$props = self::consume( $rest, '()' );

		$rest = ltrim( $rest );
		$token = substr( $rest, 0, strcspn( $rest, " \t\n\r(" ) );
		if ( $token !== 'FROM' ) {
			throw new ParseException( "Expected FROM: $s" );
		}
		$rest = ltrim( substr( $rest, strlen( $token ) ) );
		$query = self::parseSetOp( $rest, $map );

		// Validate the properties selected.
		// Enforce that [] fields use aliases (they otherwise get called out1, out2...)
		$proj = array();
		foreach ( explode( ',', $props ) as $prop ) {
			$prop = trim( $prop );
			$m = array();
			if ( preg_match( self::FLD_BASIC, $prop ) ) {
				$proj[] = "out.{$prop} AS {$prop}";
			} elseif ( preg_match( self::FLD_MAP, $prop, $m ) ) {
				$proj[] = "out.{$m[1]} AS {$m[2]}";
			} else {
				throw new ParseException( "Invalid field: $prop" );
			}
		}
		$proj = implode( ',', $proj );

		$sql = "SELECT $proj FROM ($query) TIMEOUT $timeout LIMIT $limit";

		return self::unstripQuotedStrings( $sql, $map );
	}

	/**
	 * @param string $s
	 * @param array $map
	 * @return string
	 */
	protected static function parseSetOp( $s, array $map ) {
		$s = trim( $s );

		static $ops = array( 'UNION(', 'INTERSECT(', 'DIFFERENCE(' );

		$token = substr( $s, 0, strcspn( $s, " \t\n\r" ) );
		if ( $token[0] === '{' && substr( $s, -1 ) === '}' ) {
			return self::parseSet( substr( $s, 1, -1 ), $map );
		} elseif ( in_array( $token, $ops ) && substr( $s, -1 ) === ')' ) {
			$rest = trim( substr( $s, strlen( $token ), -1 ) );

			$argSets = array();
			while ( strlen( $rest ) ) {
				$stoken = substr( $rest, 0, strcspn( $rest, " \t\n\r" ) );
				if ( $stoken[0] === '{' ) {
					$set = self::consume( $rest, '{}' );
					$argSets[] = '(' . self::parseSet( $set, $map ) . ')';
				} else { // nested UNION/DIFFERENCE/INTERSECT...
					$rest = substr( $rest, strlen( $stoken ) - 1 );
					$sargs = self::consume( $rest, '()' );
					$set = $stoken . $sargs . ')';
					$argSets[] = '(' . self::parseSetOp( $set, $map ) . ')';
				}
				$rest = ltrim( $rest );
			}

			if ( !$argSets ) {
				throw new ParseException( "Unparsable: $s" );
			}

			if ( $token === 'UNION(' ) {
				return implode( ' UNIONALL ', $argSets );
			} elseif ( $token === 'INTERSECT(' ) {
				if ( count( $argSets ) == 1 ) {
					return $argSets[0];
				}
				$where = array();
				for ( $i=1; $i < count( $argSets ); ++$i ) {
					$where[] = 'out IN ' . $argSets[$i];
				}
				return 'SELECT FROM ' . $argSets[0] . ' WHERE ' . implode( ' AND ', $where );
			} elseif ( $token === 'DIFFERENCE(' ) {
				if ( count( $argSets ) == 1 ) {
					return $argSets[0];
				}
				$where = array();
				for ( $i=1; $i < count( $argSets ); ++$i ) {
					$where[] = 'out NOT IN ' . $argSets[$i];
				}
				return 'SELECT FROM ' . $argSets[0] . ' WHERE ' . implode( ' AND ', $where );
			}
			throw new ParseException( "Unreachable state." );
		}

		throw new ParseException( "Unparsable: $s" );
	}

	/**
	 * Parse things like
	 * "HIaPV[X:A,B,C] QUALIFY(HPwQV[X:Y]) WHERE(~HIaPV[X:A,C-D] AND (HPwQV[X:Y] OR HPwQV[X:Y]))"
	 *
	 * @param string $s
	 * @param array $map
	 * @return string
	 */
	protected static function parseSet( $s, array $map ) {
		$sql = null;
		$s = trim( $s );

		// Qualifier conditions normally applied in edge class WHERE
		$qualiferPrefix = 'qlfrs';
		// Filter conditions normally applied in edge class WHERE
		$filteringPrefix = 'out.claims';

		$m = array();
		$dlist = '(?:\d+,?)+';
		// Get the primary select condition (using some index)
		if ( preg_match( "/^(HPwNoV|HPwSomeV|HPwAnyV)\[($dlist)\]\s*/", $s, $m ) ) {
			// HPwAnyV gets items with specific or "some" values for the properties
			$classes = ( $m[1] === 'HPwAnyV' )
				? 'HPwIV,HPwQV,HPwCV,HPwSV,HPwTV,HPwSomeV'
				: $m[1];
			$pIds = explode( ',', $m[2] );
			$inClause = self::sqlIN( 'iid', $pIds );
			$sql = "SELECT DISTINCT(out) AS out FROM (" .
				"SELECT expand(inE($classes)) FROM Property WHERE $inClause\$RCOND\$\$QCOND\$)";
		} elseif ( preg_match( "/^HIaPV\[(\d+):($dlist)\]\s*/", $s, $m ) ) {
			$pId = $m[1];
			$iIds = explode( ',', $m[2] );
			// Avoid IN[] per https://github.com/orientechnologies/orientdb/issues/3204
			$orClause = array();
			foreach ( $iIds as $iId ) {
				$orClause[] = "(iid=$iId AND pid=$pId)";
			}
			$orClause = implode( ' OR ', $orClause );
			$sql = "SELECT DISTINCT(out) AS out FROM HIaPV WHERE ($orClause)\$RCOND\$\$QCOND\$";
		} elseif ( preg_match( "/^HPwSV\[(\d+):((?:\\$\d+,?)+)\]\s*/", $s, $m ) ) {
			$pId = $m[1];
			$valIds = explode( ',', $m[2] );
			// Avoid IN[] per https://github.com/orientechnologies/orientdb/issues/3204
			$orClause = array();
			foreach ( $valIds as $valId ) {
				$orClause[] = "(iid=$pId AND val=$valId)";
			}
			$orClause = implode( ' OR ', $orClause );
			$sql = "SELECT DISTINCT(out) AS out FROM HPwSV WHERE ($orClause)\$RCOND\$\$QCOND\$";
		} elseif ( preg_match( "/^(HPwQV|HPwTV)\[(\d+):([^]]+)\]\s*(ASC|DESC)?\s*/", $s, $m ) ) {
			$class = $m[1];
			$pId = $m[2];
			$where = ( $class === 'HPwTV' )
				? self::parsePeriodDive( 'val', $m[3] )
				: self::parseRangeDive( 'val', $m[3] );
			$order = isset( $m[4] ) ? $m[4] : null;
			$sql = "SELECT DISTINCT(out) AS out FROM $class " .
				"WHERE iid=$pId AND $where\$RCOND\$\$QCOND\$";
			if ( $order ) {
				$sql .= " ORDER BY val $order";
			}
		} elseif ( preg_match( "/^HPwCV\[(\d+):([^]]+)\]\s*/", $s, $m ) ) {
			$pId = $m[1];
			$where = self::parseAroundDive( $m[2] );
			$sql = "SELECT DISTINCT(out) AS out FROM HPwCV " .
				"WHERE iid=$pId AND $where\$RCOND\$\$QCOND\$";
		} elseif ( preg_match( "/^items\[($dlist)\]\s*/", $s, $m ) ) {
			$iIds = explode( ',', $m[1] );
			$inClause = self::sqlIN( 'id', $iIds );
			$sql = "SELECT @RID AS out FROM Item WHERE $inClause";
			$qualiferPrefix = null; // makes no sense
			$filteringPrefix = ''; // queries Item class, not edge class
		} elseif ( preg_match( "/^linkedto\[((?:\\$\d+,?)+)\]\s*/", $s, $m ) ) {
			$valIds = explode( ',', $m[1] );
			$orClause = array();
			foreach ( $valIds as $valId ) {
				$orClause[] = "sitelinks CONTAINSVALUE $valId";
			}
			$orClause = implode( ' OR ', $orClause );
			$sql = "SELECT DISTINCT(@RID) AS out FROM Item WHERE ($orClause)";
			$qualiferPrefix = null; // makes no sense
			$filteringPrefix = ''; // queries Item class, not edge class
		} elseif ( preg_match(
			"/^HIaPVWeb\[($dlist)\](?:\s+OUTGOING\[($dlist)\])?(?:\s+INCOMING\[($dlist)\])?\s*/", $s, $m )
		) {
			$iIds = explode( ',', $m[1] );
			// https://bugs.php.net/bug.php?id=51881
			$pIdsFD = empty( $m[2] ) ? array() : explode( ',', $m[2] );
			$pIdsRV = empty( $m[3] ) ? array() : explode( ',', $m[3] );

			$tfields = array();
			$whileCond = array();
			if ( $pIdsFD ) {
				$tfields[] = 'Item.out_HIaPV';
				$tfields[] = 'HIaPV.in';
				// Edges followed in forwards direction are filtered on certain PIDs
				$whileCond[] = '(out=$parent.$current AND ' . self::sqlIN( 'pid', $pIdsFD ) . ')';
			}
			if ( $pIdsRV ) {
				$tfields[] = 'Item.in_HIaPV';
				$tfields[] = 'HIaPV.out';
				// Edges followed in reverse direction are filtered on certain PIDs
				$whileCond[] = '(in=$parent.$current AND ' . self::sqlIN( 'pid', $pIdsRV ) . ')';
			}
			$tfields = implode( ',', $tfields );
			$whileCond = implode( ' OR ', $whileCond );

			$inClause = self::sqlIN( 'id', $iIds );
			if ( $tfields ) {
				$sql = "SELECT @RID AS out FROM (" .
					"TRAVERSE $tfields " .
					"FROM (select FROM Item WHERE $inClause) " .
					"WHILE (@class='Item' OR (($whileCond)\$RCOND\$\$QCOND\$))" .
					") WHERE @class='Item'";
			} else {
				// Just grab the root items
				$sql = "SELECT @RID AS out FROM Item WHERE ($inClause)";
				$filteringPrefix = ''; // queries Item class, not edge class
			}
		} else {
			throw new ParseException( "Invalid index query: $s" );
		}

		// Skip past the stuff handled above
		$rest = substr( $s, strlen( $m[0] ) );

		$rest = trim( $rest );
		$token = substr( $rest, 0, strcspn( $rest, " \t\n\r(" ) );
		// Check if there is a RANK condition
		if ( $token === 'RANK' ) {
			$rest = substr( $rest, strlen( $token ) );
			$rank = self::consume( $rest, '()' );

			if ( $rank === 'best' ) {
				$rankCond = "best=1";
			} elseif ( isset( self::$rankMap[$rank] ) ) {
				$rankCond = "rank=" . self::$rankMap[$rank];
			} else {
				throw new ParseException( "Bad rank: '$rank'" );
			}

			$sql = str_replace( '$RCOND$', " AND $rankCond", $sql );
		} else {
			$sql = str_replace( '$RCOND$', '', $sql );
		}

		$rest = trim( $rest );
		$token = substr( $rest, 0, strcspn( $rest, " \t\n\r(" ) );
		// Check if there is a QUALIFY condition
		if ( $token === 'QUALIFY' ) {
			if ( $qualiferPrefix === null ) {
				throw new ParseException( "Index query does not support qualifiers: $s" );
			}
			$rest = substr( $rest, strlen( $token ) );
			$statement = self::consume( $rest, '()' );
			$qualifyCond = self::parseFilters( $statement, $qualiferPrefix, $map );
			$sql = str_replace( '$QCOND$', " AND $qualifyCond", $sql );
		} else {
			$sql = str_replace( '$QCOND$', '', $sql );
		}

		$rest = trim( $rest );
		$token = substr( $rest, 0, strcspn( $rest, " \t\n\r(" ) );
		// Check if there is a WHERE condition
		if ( $token === 'WHERE' ) {
			$rest = substr( $rest, strlen( $token ) );
			$statement = self::consume( $rest, '()' );
			$sql .= " AND (" . self::parseFilters( $statement, $filteringPrefix, $map ) . ")";
		}

		$rest = trim( $rest );
		$token = substr( $rest, 0, strcspn( $rest, " \t\n\r(" ) );
		// Check if there is a LIMI condition
		if ( $token === 'LIMIT' ) {
			$rest = substr( $rest, strlen( $token ) );
			$limit = (int)self::consume( $rest, '()' );
			if ( $limit > 0 ) {
				$sql .= " LIMIT $limit";
			}
		}

		if ( strlen( $rest ) ) {
			throw new ParseException( "Excess query statements found: $rest" );
		}

		return $sql;
	}

	/**
	 * Parse things like "HIaPV[X:A,C-D] AND (HPwQV[X:Y] OR HPwQV[X:Y])"
	 *
	 * @param string $s
	 * @param string $fldPrefix
	 * @param array $map
	 * @return string
	 */
	protected static function parseFilters( $s, $fldPrefix, array $map ) {
		$rest = trim( $s );

		$junction = null; // AND/OR

		$m = array();
		$where = array();
		while ( strlen( $rest ) ) {
			if ( $rest[0] === '(' ) {
				$statement = self::consume( $rest , '()' );
				$where[] = self::parseFilters( $statement, $fldPrefix, $map );
			} elseif ( preg_match( '/^(NOT)\s+\(/', $rest, $m ) ) {
				$operator = $m[1];
				$rest = substr( $rest, strlen( $m[0] ) - 1 );
				$statement = self::consume( $rest , '()' );
				$where[] = 'NOT (' . self::parseFilter( $statement, $fldPrefix, $map ) . ')';
			} elseif ( preg_match( '/^(AND|OR)\s/', $rest, $m ) ) {
				if ( $junction && $m[1] !== $junction ) {
					// "(A AND B OR C)" is confusing and requires precendence order
					throw new ParseException( "Unparsable: $s" );
				}
				$junction = $m[1];
				$rest = substr( $rest, strlen( $m[0] ) );
			} else {
				$token = substr( $rest, 0, strcspn( $rest, " \t\n\r[" ) );
				$rest = substr( $rest, strlen( $token ) );
				$args = self::consume( $rest, '[]' );
				$where[] = self::parseFilter( "{$token}[{$args}]", $fldPrefix, $map );
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
	 * Parse things like "HIaPV[X:A,C-D]"
	 *
	 * See https://github.com/orientechnologies/orientdb/wiki/SQL-Where
	 * Lack of full "NOT" operator support means we have to be careful.
	 * See https://github.com/orientechnologies/orientdb/issues/513 for
	 * better field condition piping support.
	 *
	 * @param string $s
	 * @param string $fldPrefix
	 * @param array $map
	 * @return string
	 */
	protected static function parseFilter( $s, $fldPrefix, array $map ) {
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
			$orClause = array();
			foreach ( explode( ',', $m[2] ) as $pId ) {
				$orClause[] = "{$fldPrefix}['$pId'] contains (snaktype in $stype)";
			}
			$where[] = '(' . implode( ' OR ', $orClause ) . ')';
		} elseif ( preg_match( "/^HPwV\[(\d+):([^]]+)\]$/", $s, $m ) ) {
			$pId = $m[1];
			$orClause = array();
			foreach ( explode( ',', $m[2] ) as $val ) {
				if ( preg_match( "/^($float)\s+TO\s+($float)$/", $val, $m ) ) {
					list( , $a, $b ) = $m;
					$orClause[] = "{$fldPrefix}['$pId'] contains (datavalue between $a and $b)";
				} elseif ( preg_match( "/^$float$/", $val ) ) {
					$orClause[] = "{$fldPrefix}['$pId'] contains (datavalue = $val)";
				} elseif ( preg_match( "/^($date)\s+TO\s+($date)/", $val, $m ) ) {
					list( , $a, $b ) = $m;
					// Dates are like -20001-01-01T00:00:00Z and +20001-01-01T00:00:00Z
					// and can thus be compared lexographically for simplicity
					$orClause[] = "{$fldPrefix}['$pId'] contains (datavalue between '$a' and '$b')";
				} elseif ( preg_match( "/^$date$/", $val ) ) {
					$orClause[] = "{$fldPrefix}['$pId'] contains (datavalue = '$val')";
				} elseif ( preg_match( "/^\$\d+$/", $val ) ) {
					$orClause[] = "{$fldPrefix}['$pId'] contains (datavalue = '$val')";
				} else {
					throw new ParseException( "Invalid quantity or range: $val" );
				}
			}
			$where[] = '(' . implode( ' OR ', $orClause ) . ')';
		} elseif ( preg_match( "/^haslinks\[((?:\\$\d+,?)+)\]\$/", $s, $m ) ) {
			if ( preg_match( '/(^|\.)qlfrs$/', $fldPrefix ) ) {
				throw new ParseException( "Invalid filter or qualifier condition: $s" );
			}
			$valIds = explode( ',', $m[1] );
			$orClause = array();
			foreach ( $valIds as $valId ) {
				$orClause[] = "{$fldPrefix}sitelinks CONTAINSKEY $valId";
			}
			$where[] = '(' . implode( ' OR ', $orClause ) . ')';
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
	 * @param string $field
	 * @param string $s
	 * @return string
	 */
	protected static function parseRangeDive( $field, $s ) {
		$where = array();

		$m = array();
		$float = self::RE_FLOAT;
		foreach ( explode( ',', $s ) as $v ) {
			$v = trim( $v );
			if ( preg_match( "/^$float\$/", $v ) ) {
				$where[] = "$field=$v";
			} elseif ( preg_match( "/^($float)\s+TO\s+($float)\$/", $v, $m ) ) {
				$where[] = "($field BETWEEN {$m[1]} AND {$m[2]})";
			} else {
				throw new ParseException( "Unparsable: $v" );
			}
		}

		if ( !$where ) {
			throw new ParseException( "Unparsable range: $s" );
		}

		return count( $where ) > 1 ? '(' . implode( ' OR ', $where ) . ')' : $where[0];
	}

	/**
	 * Parse stuff like "A,B,-C TO D" for timestamp index based queries
	 *
	 * @param string $field
	 * @param string $s
	 * @return string
	 */
	protected static function parsePeriodDive( $field, $s ) {
		$where = array();

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
				$where[] = "($field BETWEEN $at AND $bt)";
			} else {
				$t = WdqUtils::getUnixTimeFromISO8601( $v );
				if ( $t === false ) {
					throw new ParseException( "Unparsable timestamps: $v" );
				}
				$where[] = "$field=$t";
			}
		}

		if ( !$where ) {
			throw new ParseException( "Unparsable period: $s" );
		}

		return count( $where ) > 1 ? '(' . implode( ' OR ', $where ) . ')' : $where[0];
	}

	/**
	 * Parse stuff like "(AROUND A B C),(AROUND A B C)"
	 *
	 * @param string $s
	 * @return string
	 */
	protected static function parseAroundDive( $s ) {
		$where = array();

		$float = self::RE_FLOAT;
		$pfloat = self::RE_UFLOAT;

		$m = array();
		foreach ( explode( ',', $s ) as $v ) {
			$v = trim( $v );
			if ( preg_match( "/^AROUND ($float) ($float) ($pfloat)\$/", $v, $m ) ) {
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
	 * @param array $vals
	 * @return string
	 */
	protected static function sqlIN( $field, array $vals ) {
		// https://github.com/orientechnologies/orientdb/issues/3150
		return count( $vals ) == 1
			? "$field=" . $vals[0]
			: "$field IN [" . implode( ',', $vals ) . "]";
	}

	/**
	 * Take a string starting with a open bracket and find the closing bracket,
	 * returning the contents inside the brackets and consuming that part of $s.
	 *
	 * @param string $s
	 * @param string $pair e.g. "()", "[]", "{}"
	 * @return string
	 */
	protected static function consume( &$s, $pair ) {
		$orig = $s;
		list ( $open, $close ) = $pair;

		if ( $s[0] !== $open ) {
			throw new ParseException( "Unparsable: $orig" );
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

		return $matching;
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
