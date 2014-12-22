<?php

/**
 * Easy to use abstract query language helper
 *
 * Example query:
 * (id,claims) FROM
 * UNION(
 * 	DIFFERENCE(
 *		{HIaPVWeb[X] OUTGOING[X,Y] INCOMING[X,Y]}
 *		{HPwQV[X:A,B,-C TO D] DESC LIMIT(100)}
 *  )
 * 	UNION(
 * 		{HIaPV[X:A,B,C] WHERE(HIaPV[X:A,C,D] AND (HPwQV[X:Y] OR HPwQV[X:Y]))}
 * 		{HPwQV[X:A,B,-C TO D] QUALIFY(HPwTV[P,X,Y])}
 * 		INTERSECT(
 * 			{HIaPV[X:Y] WHERE(HIaPV[X:Y] AND (HIaPV[X:Y] OR HIaPV[X:Y]))}
 * 			{HIaPV[X:Y] RANK(best) WHERE(HIaPV[X:Y])}
 * 			{HIaPV[X:Y]}
 * 			{HIaPVWeb[X] OUTGOING[X,Y] INCOMING[X,Y]}
 * 		)
 *      {HIaPV[X:Y] WHERE(NOT (HIaPV[X:A,C,D]))}
 * 		{HPwQV[X:Y TO Z] ASC RANK(preferred) LIMIT(10)}
 * 		{HIaPVTree[X:Y] QUALIFY(HPwQV[X:Y]) AND (HPwQV[X:Y] OR HPwQV[X:Y])) WHERE(link[X,Y])}
 *		{HIaPV[X:Y] QUALIFY(NOT (HIaPV[X:A,C,D]))}
 * 	)
 * 	INTERSECT(
 * 		{HIaPVWeb[X] OUTGOING[X,Y] INCOMING[X,Y] RANK(best)}
 * 		{HP[X,Y,Z] RANK(preferred) QUALIFY(HPwSV[X:"Y"])}
 * 		{HPwCV[X:(AROUND A B C),(AROUND A B C)] QUALIFY(HIaPV[P:X]) WHERE(link[X,Y])}
 * 	)
 * )
 */
class WdqQueryParser {
	const RE_FLOAT = '[-+]?[0-9]*\.?[0-9]+';
	const RE_UFLOAT = '\+?[0-9]*\.?[0-9]+';
	const FLD_BASIC = '/^(id|claims|sitelinks|labels)$/';
	const FLD_CMPLX = '/^((?:claims|sitelinks|labels)\[\$\d+\])\s+AS\s+([a-zA-Z_]+)$/';
	const FLD_EDGE = '/^(val|sid)$/';

	/**
	 * @param string $s
	 * @param integer $timeout
	 * @return string
	 */
	public static function parse( $s, $timeout = 5000 ) {
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
		$query = $rest;

		// Validate the properties selected.
		// Enforce that [] fields use aliases (they otherwise get called out1, out2...)
		$proj = array();
		foreach ( explode( ',', $props ) as $prop ) {
			$prop = trim( $prop );
			$m = array();
			if ( preg_match( self::FLD_BASIC, $prop ) ) {
				$proj[] = "out.$prop AS $prop";
			} elseif ( preg_match( self::FLD_CMPLX, $prop, $m ) ) {
				$field = self::unstripQuotedStrings( $m[1], $map );
				$proj[] = "out.$field AS {$m[2]}";
			} elseif ( preg_match( self::FLD_EDGE, $prop ) ) {
				$proj[] = $prop;
			} else {
				throw new ParseException( "Invalid field: $prop" );
			}
		}
		$proj = implode( ',', $proj );

		return "SELECT $proj FROM (" . self::parseSetOp( $query, $map ) . ") TIMEOUT $timeout";
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
		$filters = '';
		$qualifiers = '';
		$qualiferPrefix = '';
		$wherePrefix = 'out.';

		$s = trim( $s );

		$m = array();
		$dlist = '(?:\d+,?)+';
		// Get the primary select condition (using some index)
		if ( preg_match( "/^(HPwNoV|HPwSomeV|HP)\[($dlist)\]\s*/", $s, $m ) ) {
			$classes = ( $m[1] === 'HP' ) // HP means "any classes of property"
				? 'HPwIV,HPwQV,HPwCV,HPwSV,HPwTV,HPwNoV,HPwSomeV'
				: $m[1];
			$pIds = explode( ',', $m[2] );
			$inClause = self::makeINClause( 'id', $pIds );
			$sql = "SELECT DISTINCT(out) AS out FROM (" .
				"SELECT expand(inE($classes)) FROM Property WHERE $inClause)\$RWHERE\$";
			$qualiferPrefix = "out.claims[in.id.prefix('P')][sid]['qualifiers'].";
		} elseif ( preg_match( "/^HIaPV\[(\d+):($dlist)\]\s*/", $s, $m ) ) {
			$pId = $m[1];
			$iIds = explode( ',', $m[2] );
			// Avoid IN[] per https://github.com/orientechnologies/orientdb/issues/3204
			$orClause = array();
			foreach ( $iIds as $iId ) {
				$orClause[] = "(iid=$iId AND pid=$pId)";
			}
			$orClause = implode( ' OR ', $orClause );
			$sql = "SELECT DISTINCT(out) AS out FROM HIaPV WHERE ($orClause)\$RKCOND\$";
			$qualiferPrefix = "out.claims['P$pId'][sid]['qualifiers'].";
		} elseif ( preg_match( "/^HPwSV\[(\d+):((?:\\$\d+,?)+)\]\s*/", $s, $m ) ) {
			$pId = $m[1];
			$valIds = explode( ',', $m[2] );
			// Avoid IN[] per https://github.com/orientechnologies/orientdb/issues/3204
			$orClause = array();
			foreach ( $valIds as $valId ) {
				$val = self::unstripQuotedStrings( $valId, $map );
				$orClause[] = "(iid=$pId AND val=$val)";
			}
			$orClause = implode( ' OR ', $orClause );
			$sql = "SELECT DISTINCT(out) AS out FROM HPwSV WHERE ($orClause)\$RKCOND\$";
			$qualiferPrefix = "out.claims['P$pId'][sid]['qualifiers'].";
		} elseif ( preg_match( "/^(HPwQV|HPwTV)\[(\d+):([^]]+)\]\s*(ASC|DESC)?\s*/", $s, $m ) ) {
			$class = $m[1];
			$pId = $m[2];
			$where = self::parseRangeDive( 'val', $m[3] );
			$order = isset( $m[4] ) ? $m[4] : null;
			$sql = "SELECT DISTINCT(out) AS out FROM $class WHERE iid=$pId AND $where\$RKCOND\$";
			if ( $order ) {
				$sql .= " ORDER BY val $order";
			}
			$qualiferPrefix = "out.claims['P$pId'][sid]['qualifiers'].";
		} elseif ( preg_match( "/^HPwCV\[(\d+):([^]]+)\]\s*/", $s, $m ) ) {
			$pId = $m[1];
			$where = self::parseAroundDive( $m[2] );
			$sql = "SELECT DISTINCT(out) AS out FROM HPwCV WHERE iid=$pId AND $where\$RKCOND\$";
			$qualiferPrefix = "out.claims['P$pId'][sid]['qualifiers'].";
		} elseif ( preg_match( "/^items\[($dlist)\]\s*/", $s, $m ) ) {
			$iIds = explode( ',', $m[1] );
			$inClause = self::makeINClause( 'id', $iIds );
			$sql = "SELECT @RID AS out FROM Item WHERE $inClause";
			$qualiferPrefix = null; // makes no sense
			$wherePrefix = ''; // queries Item class, not edge class
		} elseif ( preg_match( "/^linkedto\[((?:\\$\d+,?)+)\]\s*/", $s, $m ) ) {
			$valIds = explode( ',', $m[1] );
			$orClause = array();
			foreach ( $valIds as $valId ) {
				$orClause[] = 'sitelinks CONTAINSVALUE ' .
					self::unstripQuotedStrings( $valId, $map );
			}
			$orClause = implode( ' OR ', $orClause );
			$sql = "SELECT DISTINCT(@RID) AS out FROM Item WHERE ($orClause)\$RKCOND\$";
			$qualiferPrefix = null; // makes no sense
			$wherePrefix = ''; // queries Item class, not edge class
		} elseif ( preg_match(
			"/^HIaPVWeb\[($dlist)\](?:\s+OUTGOING\[($dlist)\])?(?:\s+INCOMING\[($dlist)\])?\s*/", $s, $m )
		) {
			$iIds = explode( ',', $m[1] );
			$pIdsFD = isset( $m[2]) ? explode( ',', $m[2] ) : array();
			$pIdsRV = isset( $m[3]) ? explode( ',', $m[3] ) : array();

			$tfields = array();
			$whileCond = array();
			if ( $pIdsFD ) {
				$tfields[] = 'Item.out_HIaPV';
				$tfields[] = 'HIaPV.in';
				// Edges followed in forwards direction are filtered on certain PIDs
				$whileCond[] = '(out=$parent.$current AND ' .
					self::makeINClause( 'pid', $pIdsFD ) . ')';
			}
			if ( $pIdsRV ) {
				$tfields[] = 'Item.in_HIaPV';
				$tfields[] = 'HIaPV.out';
				// Edges followed in reverse direction are filtered on certain PIDs
				$whileCond[] = '(in=$parent.$current AND ' .
					self::makeINClause( 'pid', $pIdsRV ) . ')';
			}
			$tfields = implode( ',', $tfields );
			$whileCond = implode( ' OR ', $whileCond );

			$inClause = self::makeINClause( 'id', $iIds );
			if ( $tfields ) {
				$sql = "SELECT @RID AS out FROM (" .
					"TRAVERSE $tfields " .
					"FROM (select FROM Item WHERE $inClause) " .
					"WHILE (@class='Item' OR (($whileCond)\$RKCOND\$))" .
					") WHERE @class='Item'";
			} else {
				// Just grab the root items
				$sql = "SELECT @RID AS out FROM Item WHERE ($inClause)";
				$wherePrefix = ''; // queries Item class, not edge class
			}
			$qualiferPrefix = null; // makes no sense
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

			static $rankMap = array(
				'deprecated' => -1,
				'normal'     => 0,
				'preferred'  => 1
			);

			if ( $rank === 'best' ) {
				$rankCond = "best=1";
			} elseif ( isset( $rankMap[$rank] ) ) {
				$rankCond = "rank={$rankMap[$rank]}";
			} else {
				throw new ParseException( "Bad rank: '$rank'" );
			}
			$sql = str_replace(
				array( '$RWHERE$', '$RKCOND$' ),
				array( " WHERE $rankCond", " AND $rankCond" ),
				$sql
			);
		} else {
			$sql = str_replace( array( '$RKCOND$', '$RWHERE$' ), array( '', '' ), $sql );
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
			$sql .= " AND (" . self::parseFilters( $statement, $qualiferPrefix, $map ) . ")";
		}

		$rest = trim( $rest );
		$token = substr( $rest, 0, strcspn( $rest, " \t\n\r(" ) );
		// Check if there is a WHERE condition
		if ( $token === 'WHERE' ) {
			$rest = substr( $rest, strlen( $token ) );
			$statement = self::consume( $rest, '()' );
			$sql .= " AND (" . self::parseFilters( $statement, $wherePrefix, $map ) . ")";
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
				$where[] = 'NOT ' . self::parseFilter( $statement, $fldPrefix, $map );
			} elseif ( preg_match( '/^(AND|OR)\s/', $rest, $m ) ) {
				if ( $junction && $m[1] !== $junction ) {
					// "(A AND B OR C)" is confusing and requires precendence order
					throw new ParseException( "Unparsable: $s" );
				}
				$junction = $m[1];
				$rest = substr( $rest, strlen( $m[0] ) );
			} else {
				$token = substr( $rest, 0, strcspn( $rest, " \t\n\r" ) );
				$rest = substr( $rest, strlen( $token ) );
				$where[] = self::parseFilter( $token, $fldPrefix, $map );
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
		$dlist = '(?:\d+,?)+';
		// @note: in OrientDB, if field b is an array, then a.b.c=5 scans all
		// the items in b to see if any has c=5. This applies to qualifiers.PX
		// and searches on other properties than the one the index was used for.
		if ( preg_match( "/^HP\[($dlist)\]$/", $s, $m ) ) {
			$pIds = $m[1];
			$sql = "{$fldPrefix}claims CONTAINSKEY (in.id.prefix('P')])";
		} elseif ( preg_match( "/^HPwNoV\[($dlist)\]$/", $s, $m ) ) {
			$where2 = array();
			foreach ( explode( ',', $m[1] ) as $pId ) {
				$where2[] = "{$fldPrefix}claims['P$pId'].snaktype='novalue'";
			}
			$where[] = '(' . implode( ' AND ', $where2 ) . ')';
		} elseif ( preg_match( "/^HPwSomeV\[($dlist)\]$/", $s, $m ) ) {
			$where2 = array();
			foreach ( explode( ',', $m[1] ) as $pId ) {
				$where2[] = "{$fldPrefix}claims['P$pId'].snaktype='somevalue'";
			}
			$where[] = '(' . implode( ' AND ', $where2 ) . ')';
		} elseif ( preg_match( "/^HIaPV\[(\d+):((\d+,?)+)\]$/", $s, $m ) ) {
			$pId = $m[1];
			$where2 = array();
			foreach ( explode( ',', $m[2] ) as $iId ) {
				$where2[] = "{$fldPrefix}claims['P$pId'].datavalue.value['numeric-id']=$iId";
			}
			$where[] = '(' . implode( ' AND ', $where2 ) . ')';
		} elseif ( preg_match( "/^HPwSV\[(\d+):((\\$\d+,?)+)\]$/", $s, $m ) ) {
			$pId = $m[1];
			$where2 = array();
			foreach ( explode( ',', $m[2] ) as $valId ) {
				$val = self::unstripQuotedStrings( $valId, $map ); // "$\d+" => "value"
				$where2[] = "{$fldPrefix}claims['P$pId'].datavalue.value=$val";
			}
			$where[] = '(' . implode( ' AND ', $where2 ) . ')';
		} elseif ( preg_match( "/^HPwQV\[(\d+):([^]]+)\]$/", $s, $m ) ) {
			$pId = $m[1];
			$where2 = array();
			foreach ( explode( ',', $m[2] ) as $val ) {
				if ( preg_match( "/^$float TO $float$/", $val, $m ) ) {
					list( , $a, $b ) = $m;
					$where2[] =
						"{$fldPrefix}claims['P$pId'].datavalue.value.amount BETWEEN $a AND $b";
				} elseif ( preg_match( "/^$float$/", $val ) ) {
					$where2[] = "{$fldPrefix}claims['P$pId'].datavalue.value.amount=$val";
				}
			}
			$where[] = '(' . implode( ' AND ', $where2 ) . ')';
		} elseif ( preg_match( "/^HPwTV\[(\d+):([^\]]+)\]$/", $s, $m ) ) {
			$pId = $m[1];
			$where2 = array();
			foreach ( explode( ',', $m[2] ) as $val ) {
				if ( preg_match( "/^$float TO $float$/", $val, $m ) ) {
					list( , $a, $b ) = $m;
					$where2[] = "{$fldPrefix}claims['P$pId'].datavalue.value.time BETWEEN $a AND $b";
				} elseif ( preg_match( "/^$float$/", $val ) ) {
					$where2[] = "{$fldPrefix}claims['P$pId'].datavalue.value.time=$val";
				} elseif ( preg_match( "/^\w+ TO \w+$/", $val, $m ) ) {
					list( , $a, $b ) = $m;
					$a = WdqUtils::getUnixTimeFromISO8601( $a );
					$b = WdqUtils::getUnixTimeFromISO8601( $a );
					if ( $a === false || $b === false ) {
						throw new ParseException( "Unparsable timestamps: $val" );
					}
					$where2[] = "{$fldPrefix}claims['P$pId'].datavalue.value.time BETWEEN $a AND $b";
				} elseif ( preg_match( "/^$float$/", $val ) ) {
					$val = WdqUtils::getUnixTimeFromISO8601( $val );
					if ( $val === false ) {
						throw new ParseException( "Unparsable timestamps: $val" );
					}
					$where2[] = "{$fldPrefix}claims['P$pId'].datavalue.value.time=$val";
				} else {
					throw new ParseException( "Unparsable timestamps: $val" );
				}
			}
			$where[] = '(' . implode( ' AND ', $where2 ) . ')';
		} elseif ( preg_match( "/^haslinks\[((?:\\$\d+,?)+)\]\$/", $s, $m ) ) {
			$valIds = explode( ',', $m[1] );
			$orClause = array();
			foreach ( $valIds as $valId ) {
				$orClause[] = "{$fldPrefix}sitelinks CONTAINSKEY " .
					self::unstripQuotedStrings( $valId, $map );
			}
			$where[] = '(' . implode( ' OR ', $orClause ) . ')';
		} elseif ( preg_match( "/^HPwCV\[(\d+):([^]]+)\]$/", $s, $m ) ) {
			throw new ParseException( "HPwCV not supported as a filter: $s" );
		} else {
			throw new ParseException( "Invalid filter condition: $s" );
		}

		if ( !$where ) {
			throw new ParseException( "Invalid filter condition: $s" );
		}

		return implode( ' AND ', $where );
	}

	/**
	 * Parse stuff like "A,B,-C TO D" for index based queries
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
				$where[] = "field=$v";
			} elseif ( preg_match( "/^($float)\s+TO\s+($float)\$/", $v, $m ) ) {
				$where[] = "($field BETWEEN {$m[1]} AND {$m[2]})";
			} else {
				throw new ParseException( "Unparsable: $v" );
			}
		}

		if ( !$where ) {
			throw new ParseException( "Unparsable: $s" );
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
			throw new ParseException( "Unparsable: $s" );
		}

		return count( $where ) > 1 ? '(' . implode( ' OR ', $where ) . ')' : $where[0];
	}

	/**
	 * @param string $field
	 * @param array $vals
	 * @return string
	 */
	protected static function makeINClause( $field, array $vals ) {
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
