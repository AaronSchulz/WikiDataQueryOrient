<?php

require_once( __DIR__ . '/../lib/autoload.php' );

$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HP[279] QUALIFY(HPwQV[1414:89-90,1]) LIMIT(5)}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link,claims["P279"] AS claims) FROM {HP[31,279] LIMIT(5)}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HIaPV[31:2590631] LIMIT(5)}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HIaPV[31:5] LIMIT(5)}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {items[1339,350,34,64,747,24242,636,3] WHERE(haslinks["enwiki"])}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {linkedto["enwiki#Universe"]}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link,sitelinks["enwiki"] AS sitelink) FROM {HP[175,275,757] LIMIT(10)}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwSomeV[1036,237] LIMIT(10)}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwSomeV[1036,237] RANK(best) LIMIT(10)}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwNoV[102,47] RANK(best) LIMIT(10)}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HIaPV[31:1,2,3]}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HIaPV[31:1,2,3] WHERE(HIaPV[41:14] OR NOT (HIaPV[321:1]))}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwQV[31:1,-10000 TO 3000000] ASC}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwQV[31:1,50000 TO 3000000] DESC}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwQV[31:1,35000 TO 60000] DESC LIMIT(10)}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwSV[311:\'www.whitehouse.gov\',\'www.fafsa.edu\']}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwSV[311:"cat","says","meow"]}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwSV[311:"O\'reilly Pub\""] WHERE(NOT (HIaPV[31:1,2,3]))}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwQV[31:1.0,2,33 TO 63] RANK(best)}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwTV[131:1.0,2,-1111133 TO 1111163]}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwTV[131:1.0,2,-1111133 TO 1111163] DESC}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwTV[131:1.0,2,-1111133 TO 1111163] DESC LIMIT(5)}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwCV[625:AROUND 38.897669444444 -77.03655 2] LIMIT(10)}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwCV[625:AROUND 38.897669444444 -77.03655 2,AROUND -1.1 -2.2 3.3] RANK(best) LIMIT(5)}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HIaPV[31:1,2,3] WHERE(HPwQV[1:3.141596] OR HPwQV[353:2525])}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HIaPV[31:1,2,3] RANK(preferred) QUALIFY(HIaPV[15141:222,252,353])}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HIaPV[31:1,2,3] QUALIFY(HPwQV[1414:89-90,1] AND HIaPV[1414:356,46]) WHERE(HPwQV[14:3.0] OR (HPwQV[24:2.5] AND HPwQV[98:5.6]))}';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM INTERSECT( {HIaPV[31:1,2,3]} {HPwCV[131:AROUND 1.1 -2.2 3.3,AROUND -1.1 -2.2 3.3]} )';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM DIFFERENCE( {HIaPV[31:1,2,3]} {HPwCV[131:AROUND 1.1 -2.2 3.3,AROUND -1.1 -2.2 3.3]} )';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM UNION( {HIaPV[31:1,2,3]} {HPwCV[131:AROUND 1.1 -2.2 3.3,AROUND -1.1 -2.2 3.3]} )';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM UNION( {HIaPV[31:1,2,3]} INTERSECT( {HPwSV[311:"cat","says","meow"]} {HPwQV[31:1.0,2,33 TO 63]} ) )';
$queries[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HIaPVWeb[30] OUTGOING[150] INCOMING[17,131]}';

foreach ( $queries as $query ) {
	print( $query . "\n=====\n" );
	print( WdqQueryParser::parse( "$query" ) . "\n\n" );
}
