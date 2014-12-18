<?php

require_once( __DIR__ . '/../lib/autoload.php' );

$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HP[279] QUALIFY(HPwQV[1414:89-90,1]) LIMIT(5)}';
$queries[] = 'SELECT (id,labels["en"] AS label,claims["P279"][sid] AS claims) FROM {HP[31,279] LIMIT(5)}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HIaPV[31:2590631] LIMIT(5)}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HIaPV[31:5] LIMIT(5)}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {items[1339,350,34,64,747,24242,636,3] WHERE(haslinks["enwiki"])}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {linkedto["enwiki#Universe"]}';
$queries[] = 'SELECT (id,labels["en"] AS label,sitelinks["enwiki"] AS sitelink) FROM {HP[175,275,757]}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HPwSomeV[1036,237]}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HPwNoV[1,2,3] RANK(best)}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HIaPV[31:1,2,3]}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HPwQV[31:1,-10000 TO 3000000] ASC}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HPwQV[31:1,50000 TO 3000000] DESC}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HPwQV[31:1,35000 TO 60000] DESC LIMIT(10)}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HPwSV[311:\'www.whitehouse.gov\',\'www.fafsa.edu\']}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HPwSV[311:"cat","says","meow"]}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HPwSV[311:"O\'reilly Pub\""]}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HPwQV[31:1.0,2,33 TO 63] RANK(best)}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HPwTV[131:1.0,2,-1111133 TO 1111163]}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HPwTV[131:1.0,2,-1111133 TO 1111163] DESC}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HPwTV[131:1.0,2,-1111133 TO 1111163] DESC LIMIT(5)}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HPwCV[625:AROUND 38.897669444444 -77.03655 2] LIMIT(10)}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HPwCV[625:AROUND 38.897669444444 -77.03655 2,AROUND -1.1 -2.2 3.3] LIMIT(5)}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HIaPV[31:1,2,3] WHERE(HPwQV[1:3.141596] OR HPwQV[353:2525])}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HIaPV[31:1,2,3] RANK(preferred) QUALIFY(HIaPV[15141:222,252,353])}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HIaPV[31:1,2,3] QUALIFY(HPwQV[1414:89-90,1] AND HIaPV[1414:356,46]) WHERE(HPwQV[14:3.0] OR (HPwQV[24:2.5] AND HPwQV[98:5.6]))}';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM INTERSECT( {HIaPV[31:1,2,3]} {HPwCV[131:AROUND 1.1 -2.2 3.3,AROUND -1.1 -2.2 3.3]} )';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM DIFFERENCE( {HIaPV[31:1,2,3]} {HPwCV[131:AROUND 1.1 -2.2 3.3,AROUND -1.1 -2.2 3.3]} )';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM UNION( {HIaPV[31:1,2,3]} {HPwCV[131:AROUND 1.1 -2.2 3.3,AROUND -1.1 -2.2 3.3]} )';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM UNION( {HIaPV[31:1,2,3]} INTERSECT( {HPwSV[311:"cat","says","meow"]} {HPwQV[31:1.0,2,33 TO 63]} ) )';
$queries[] = 'SELECT (id,labels["en"] AS label) FROM {HIaPVWeb[30] OUTGOING[150] INCOMING[17,131]}';

foreach ( $queries as $query ) {
	print( $query . "\n=====\n" );
	print( WdqQueryParser::parse( "$query" ) . "\n\n" );
}
