<?php

require_once( __DIR__ . '/../lib/autoload.php' );

$queries[] = 'SELECT (id) FROM {HP[279] LIMIT(5)}';
$queries[] = 'SELECT (id) FROM {HP[31,279] LIMIT(5)}';
$queries[] = 'SELECT (id) FROM {HPwIV[31:2590631] LIMIT(5)}';
$queries[] = 'SELECT (id) FROM {HPwIV[31:5] LIMIT(5)}';
$queries[] = 'SELECT (id) FROM {items[1339,350,34,64,747,24242,636,3] WHERE(haslinks["enwiki"])}';
$queries[] = 'SELECT (id) FROM {linkedto["enwiki#Universe"]}';
$queries[] = 'SELECT (id,sitelinks["enwiki"]) FROM {HP[175,275,757]}';
$queries[] = 'SELECT (id) FROM {HPwSomeV[1036,237]}';
$queries[] = 'SELECT (id) FROM {HPwNoV[1,2,3] RANK(best)}';
$queries[] = 'SELECT (id) FROM {HPwIV[31:1,2,3]}';
$queries[] = 'SELECT (id) FROM {HPwQV[31:1,-10000 TO 3000000] ASC}';
$queries[] = 'SELECT (id) FROM {HPwQV[31:1,50000 TO 3000000] DESC}';
$queries[] = 'SELECT (id) FROM {HPwQV[31:1,35000 TO 60000] DESC LIMIT(10)}';
$queries[] = "SELECT (id) FROM {HPwSV[311:'www.whitehouse.gov','www.fafsa.edu']}";
$queries[] = 'SELECT (id) FROM {HPwSV[311:"cat","says","meow"]}';
$queries[] = 'SELECT (id) FROM {HPwSV[311:"O\'reilly Pub\""]}';
$queries[] = 'SELECT (id) FROM {HPwQV[31:1.0,2,33 TO 63] RANK(best)}';
$queries[] = 'SELECT (id) FROM {HPwTV[131:1.0,2,-1111133 TO 1111163]}';
$queries[] = 'SELECT (id) FROM {HPwTV[131:1.0,2,-1111133 TO 1111163] DESC}';
$queries[] = 'SELECT (id) FROM {HPwTV[131:1.0,2,-1111133 TO 1111163] DESC LIMIT(5)}';
$queries[] = 'SELECT (id) FROM {HPwCV[625:AROUND 38.897669444444 -77.03655 2,AROUND -1.1 -2.2 3.3] LIMIT(5)}';
$queries[] = 'SELECT (id) FROM {HPwIV[31:1,2,3] WHERE(HPwQV[1:3.141596] OR HPwQV[353:2525])}';
$queries[] = 'SELECT (id) FROM {HPwIV[31:1,2,3] RANK(preferred) QUALIFY(HPwIV[15141:222,252,353])}';
$queries[] = 'SELECT (id) FROM {HPwIV[31:1,2,3] QUALIFY(HPwQV[1414:89-90,1] AND HPwIV[1414:356,46]) WHERE(HPwQV[14:3.0] OR (HPwQV[24:2.5] AND HPwQV[98:5.6]))}';
$queries[] = 'SELECT (id) FROM INTERSECT( {HPwIV[31:1,2,3]} {HPwCV[131:AROUND 1.1 -2.2 3.3,AROUND -1.1 -2.2 3.3]} )';
$queries[] = 'SELECT (id) FROM DIFFERENCE( {HPwIV[31:1,2,3]} {HPwCV[131:AROUND 1.1 -2.2 3.3,AROUND -1.1 -2.2 3.3]} )';
$queries[] = 'SELECT (id) FROM UNION( {HPwIV[31:1,2,3]} {HPwCV[131:AROUND 1.1 -2.2 3.3,AROUND -1.1 -2.2 3.3]} )';
$queries[] = 'SELECT (id) FROM UNION( {HPwIV[31:1,2,3]} INTERSECT( {HPwSV[311:"cat","says","meow"]} {HPwQV[31:1.0,2,33 TO 63]} ) )';
$queries[] = 'SELECT (id) FROM {HPwIVWeb[30] OUTGOING[150] INCOMING[17,131]}';

foreach ( $queries as $query ) {
	print( $query . "\n=====\n" );
	print( WdqQueryParser::parse( "$query" ) . "\n\n" );
}
