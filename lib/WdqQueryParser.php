<?php

/**
 * Easy to use abstract query language helper
 *
 * Example query:
 * SELECT id,claim FROM
 * UNION(
 *  DIFFERENCE (
 *		{HPwIVWeb[X] OUTGOING[X,Y] INCOMING[X,Y]}
 *		{HPwQV[X:A,B,-C TO D] DESC LIMIT(100)}
 *  )
 * 	UNION(
 * 		{HPwIV[X:A,B,C] WHERE(HPwIV[X:A,C-D] AND (HPwQV[X:Y] OR HPwQV[X:Y]))}
 * 		{HPwQV[X:A,B,-C TO D] QUALIFY(HPwTV[P,X,Y])}
 * 		INTERSECT(
 * 			{HPwIV[X:Y] WHERE(HPwIV[X:Y] AND (HPwIV[X:Y] OR HPwIV[X:Y]))}
 * 			{HPwIV[X:Y] RANK(best) WHERE(HPwIV[X:Y])}
 * 			{HPwIV[X:Y]}
 * 			{HPwIVWeb[X] OUTGOING[X,Y] INCOMING[X,Y]}
 * 		)
 * 		{HPwQV[X:Y TO Z] ASC RANK(preferred) LIMIT(10)}
 * 		{HPwIVTree[X:Y] QUALIFY(HPwQV[X:Y]) AND (HPwQV[X:Y] OR HPwQV[X:Y])) WHERE(link[X,Y])}
 * 	)
 * 	INTERSECT(
 * 		{HPwIVWeb[X] OUTGOING[X,Y] INCOMING[X,Y] RANK(best)}
 * 		{HP[X,Y,Z] RANK(preferred) QUALIFY(HPwSV[X:"Y"])}
 * 		{HPwCV[X:(AROUND A B C),(AROUND A B C)] QUALIFY(HPwIV[P:X]) WHERE(link[X,Y])}
 * 	)
 * )
 */
class WdqQueryParser {
	const RE_FLOAT = '[-+]?[0-9]*\.?[0-9]+';
	const RE_UFLOAT = '\+?[0-9]*\.?[0-9]+';

	/**
	 * @param string $s
	 * @return string
	 */
	public static function parse( $s, $timeout = 5000 ) {
		$s = trim( $s );
		// Amour all quoted string values for easy parsing
		list( $s, $map ) = self::stripQuotedStrings( $s );
		$rest = $s;

		$token = substr( $rest, 0, strcspn( $rest, " \t\n\r(" ) );
		if ( $token !== 'SELECT' ) {
			throw new ParseException( "Expected SELECT: $s" );
		}
		$rest = ltrim( substr( $rest, strlen( $token ) ) );
		$props = self::consume( $rest, '()' );

		$rest = ltrim( $rest );
		$token = substr( $rest, 0, strcspn( $rest, " \t\n\r(" ) );
		if ( $token !== 'FROM' ) {
			throw new ParseException( "Expected FROM: $s" );
		}
		$rest = ltrim( substr( $rest, strlen( $token ) ) );
		$query = $rest;

		// Validate the properties selected
		$proj = array();
		static $fieldRe = '#(id|claims|sitelinks)(\.[a-zA-Z]+|\[\$\d+\])*#';
		foreach ( explode( ',', $props ) as $prop ) {
			if ( !preg_match( $fieldRe, $prop ) ) {
				throw new ParseException( "Invalid field: $prop" );
			}
			$proj[] = self::unstripQuotedStrings( $prop, $map );
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
	 * "HPwIV[X:A,B,C] QUALIFY(HPwQV[X:Y]) WHERE(~HPwIV[X:A,C-D] AND (HPwQV[X:Y] OR HPwQV[X:Y]))"
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
		$filterPrefix = '';

		$s = trim( $s );

		$m = array();
		$dlist = '(?:\d+,?)+';
		// Get the primary select condition (using some index)
		if ( preg_match( "/^(HP|HPwNoV|HPwSomeV)\[($dlist)\]\s*/", $s, $m ) ) {
			$class = $m[1];
			$pIds = explode( ',', $m[2] );
			$inClause = self::makeINClause( 'id', $pIds );
			$sql = "SELECT expand(in($class)) FROM Property WHERE ($inClause)";
			$qualiferPrefix = "out.claims[in.id.prefix('P')][sid]['qualifiers'].";
			$filterPrefix = "out.";
		} elseif ( preg_match( "/^HPwIV\[(\d+):($dlist)\]\s*/", $s, $m ) ) {
			$pId = $m[1];
			$iIds = explode( ',', $m[2] );
			$inClause = self::makeINClause( 'in.id', $iIds );
			$sql = "SELECT out,oid FROM HPwIV WHERE pid=$pId AND ($inClause)";
			$qualiferPrefix = "out.claims['P$pId'][sid]['qualifiers'].";
			$filterPrefix = "out.";
		} elseif ( preg_match( "/^HPwSV\[(\d+):((?:\\$\d+,?)+)\]\s*/", $s, $m ) ) {
			$pId = $m[1];
			$valIds = explode( ',', $m[2] );
			$vals = array();
			foreach ( $valIds as $valId ) {
				$vals[] = self::unstripQuotedStrings( $valId, $map );
			}
			$inClause = self::makeINClause( 'val', $vals );
			$sql = "SELECT out,oid FROM HPwSV WHERE in.id=$pId AND ($inClause)";
			$qualiferPrefix = "out.claims['P$pId'][sid]['qualifiers'].";
			$filterPrefix = "out.";
		} elseif ( preg_match( "/^HPwQV\[(\d+):([^]]+)\]\s*(ASC|DESC)?\s*/", $s, $m ) ) {
			$pId = $m[1];
			$where = self::parseRangeDive( 'val', $m[2] );
			$order = isset( $m[3] ) ? $m[3] : null;
			$sql = "SELECT out,oid FROM HPwQV WHERE in.id=$pId AND $where";
			if ( $order ) {
				$sql .= " ORDER BY val $order";
			}
			$qualiferPrefix = "out.claims['P$pId'][sid]['qualifiers'].";
			$filterPrefix = "out.";
		} elseif ( preg_match( "/^HPwTV\[(\d+):([^\]]+)\]\s*(ASC|DESC)?/", $s, $m ) ) {
			$pId = $m[1];
			$where = self::parseRangeDive( 'val', $m[2] );
			$order = isset( $m[3] ) ? $m[3] : null;
			$sql = "SELECT out,oid FROM HPwTV WHERE in.id=$pId AND $where";
			if ( $order ) {
				$sql .= " ORDER BY val $order";
			}
			$qualiferPrefix = "out.claims['P$pId'][sid]['qualifiers'].";
			$filterPrefix = "out.";
		} elseif ( preg_match( "/^HPwCV\[(\d+):([^]]+)\]\s*/", $s, $m ) ) {
			$pId = $m[1];
			$where = self::parseAroundDive( $m[2] );
			$sql = "SELECT out,oid FROM HPwCV WHERE in.id=$pId AND $where";
			$qualiferPrefix = "out.claims['P$pId'][sid]['qualifiers'].";
			$filterPrefix = "out.";
		} elseif ( preg_match( "/^items\[($dlist)\]\s*/", $s, $m ) ) {
			$iIds = explode( ',', $m[1] );
			$inClause = self::makeINClause( 'id', $iIds );
			$sql = "SELECT FROM Item WHERE ($inClause)";
			$qualiferPrefix = null; // makes no sense
			$filterPrefix = "";
		} elseif ( preg_match( "/^linkedto\[((?:\\$\d+,?)+)\]\s*/", $s, $m ) ) {
			$valIds = explode( ',', $m[1] );
			$orClause = array();
			foreach ( $valIds as $valId ) {
				$orClause[] = 'sitelinks CONTAINSVALUE ' .
					self::unstripQuotedStrings( $valId, $map );
			}
			$in = implode( ' OR ', $orClause );
			$sql = "SELECT FROM Item WHERE ($in)";
			$qualiferPrefix = null; // makes no sense
			$filterPrefix = "out.";
		} elseif ( preg_match(
			"/^HPwIVWeb\[($dlist)\](?:\s+OUTGOING\[($dlist)\])?(?:\s+INCOMING\[($dlist)\])?\s*/", $s, $m )
		) {
			$iIds = explode( ',', $m[1] );
			$pIdsFD = isset( $m[2]) ? explode( ',', $m[2] ) : array();
			$pIdsRV = isset( $m[3]) ? explode( ',', $m[3] ) : array();

			$tfields = array();
			$whileCond = array();
			if ( $pIdsFD ) {
				$tfields[] = 'Item.out_HPwIV';
				$tfields[] = 'HPwIV.in';
				// Edges followed in forwards direction are filtered on certain PIDs
				$whileCond[] = '(out=$parent.$current AND (' .
					self::makeINClause( 'pid', $pIdsFD ) . '))';
			}
			if ( $pIdsRV ) {
				$tfields[] = 'Item.in_HPwIV';
				$tfields[] = 'HPwIV.out';
				// Edges followed in reverse direction are filtered on certain PIDs
				$whileCond[] = '(in=$parent.$current AND (' .
					self::makeINClause( 'pid', $pIdsRV ) . '))';
			}
			$tfields = implode( ',', $tfields );
			$whileCond = implode( ' OR ', $whileCond );

			$inClause = self::makeINClause( 'id', $iIds );
			if ( $tfields ) {
				$sql = <<<EOD
SELECT FROM (
	TRAVERSE $tfields
	FROM (select Item WHERE ($inClause))
	WHILE (@class='Item' OR ($whileCond))
) WHERE @class='Item'
EOD;
			} else {
				// Just grab the root items
				$sql = "SELECT FROM Item WHERE ($inClause)";
			}
			$qualiferPrefix = null; // makes no sense
			$filterPrefix = "";
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
				$sql .= " AND (best=1)";
			} elseif ( isset( $rankMap[$rank] ) ) {
				$sql .= " AND (rank={$rankMap[$rank]})";
			} else {
				throw new ParseException( "Bad rank: '$rank'" );
			}
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
			$sql .= " AND (" . self::parseFilters( $statement, $filterPrefix, $map ) . ")";
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
	 * Parse things like "HPwIV[X:A,C-D] AND (HPwQV[X:Y] OR HPwQV[X:Y])"
	 *
	 * See https://github.com/orientechnologies/orientdb/wiki/SQL-Where
	 * Lack of "NOT" operator means we'd have to do deMorgan's ourselves.
	 *
	 * @param string $s
	 * @param string $fldPrefix
	 * @param array $map
	 * @return string
	 */
	protected static function parseFilters( $s, $fldPrefix, array $map ) {
		$orig = $s;
		$s = trim( $s );

		$junction = null; // AND/OR

		$m = array();
		$where = array();
		while ( strlen( $s ) ) {
			if ( $s[0] === '(' ) {
				$statement = self::consume( $s, '()' );
				$where[] = self::parseFilters( $statement, $fldPrefix, $map );
			} elseif ( preg_match( '/^(AND|OR) /', $s, $m ) ) {
				if ( $junction && $m[1] !== $junction ) {
					// "(A AND B OR C)" is confusing and requires precendence order
					throw new ParseException( "Unparsable: $orig" );
				}
				$junction = $m[1];
				$s = substr( $s, strlen( $m[0] ) );
			} else {
				$token = substr( $s, 0, strcspn( $s, " \t\n\r" ) );
				$s = substr( $s, strlen( $token ) );
				$where[] = self::parseFilter( $token, $fldPrefix, $map );
			}
			$s = ltrim( $s );
		}

		if ( !$where ) {
			throw new ParseException( "Unparsable: $orig" );
		}

		return $junction ? implode( " $junction ", $where ) : $where[0];
	}

	/**
	 * Parse things like "HPwIV[X:A,C-D]"
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
		} elseif ( preg_match( "/^HPwIV\[(\d+):((\d+,?)+)\]$/", $s, $m ) ) {
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
		$in = array();
		$where = array();

		$float = self::RE_FLOAT;

		$m = array();
		foreach ( explode( ',', $s ) as $v ) {
			if ( preg_match( "/^$float\$/", $v ) ) {
				$in[] = $v;
			} elseif ( preg_match( "/^($float) TO ($float)\$/", $v, $m ) ) {
				$where[] = "($field BETWEEN {$m[1]} AND {$m[2]})";
			} else {
				throw new ParseException( "Unparsable: $v" );
			}
		}

		if ( $in ) {
			$where[] = self::makeINClause( $field, $in );
		}

		if ( !$where ) {
			throw new ParseException( "Unparsable: $s" );
		}

		return implode( ' OR ', $where );
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

		return implode( ' OR ', $where );
	}

	/**
	 * @param string $field
	 * @param array $vals
	 * @return string
	 */
	protected static function makeINClause( $field, array $vals ) {
		// https://github.com/orientechnologies/orientdb/issues/3150
		return "$field IN [" . implode( ',', $vals ) . "]";
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

	protected static function stripQuotedStrings( $s ) {
		$pos = 0;
		$map = array();
		$s = preg_replace_callback(
			array( '/"([^"]*)"/Um', "/'([^']*)'/Um" ),
			function( array $m ) use ( &$pos, &$map ) {
				++$pos;
				// https://github.com/orientechnologies/orientdb/issues/1275
				$map['$' . $pos] = "'" . addcslashes( $m[1], "'" ) . "'";
				return '$' . $pos;
			},
			$s
		);

		return array( $s, $map );
	}

	protected static function unstripQuotedStrings( $s, array $map ) {
		return str_replace(
			array_keys( $map ),
			array_values( $map ),
			$s
		);
	}
}

class ParseException extends Exception {}
