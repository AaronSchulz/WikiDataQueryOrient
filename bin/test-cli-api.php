<?php

if ( PHP_SAPI !== 'cli' ) {
	die( "This script can only be run in CLI mode\n" );
}

require_once( __DIR__ . '/../lib/autoload.php' );

$q[] = '(id,labels["en"] AS label) FROM {HPwTV[569] LIMIT(10)}';
$q[] = '(id,labels["en"] AS label) FROM {HPwQV[1082] LIMIT(10)}';
$q[] = '(id,labels["en"] AS label) FROM {HPwCV[625] LIMIT(10)}';
$q[] = '(id,labels["en"] AS label) FROM {HPwSV[856] LIMIT(10)}';
$q[] = '(id,labels["en"] AS label) FROM {HPwIV[31] LIMIT(10)}';
$q[] = '(id,labels["en"] AS label) FROM {HIaPV[5] LIMIT(10)}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link,claims[31] AS P31) FROM {items[62]}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link,claims[569] AS P569) FROM {HPwTV[569:+00000001981-09-16T00:00:00Z TO +00000001981-09-17T00:00:00Z] LIMIT(20)}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link,claims[569] AS P569,claims[40] AS P40) FROM {HPwTV[569:+00000001971-09-16T00:00:00Z TO +00000001981-09-17T00:00:00Z] ASC RANK(best) WHERE(HPwAnyV[40]) LIMIT(5)}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwSomeV[20] WHERE(HPwAnyV[40])}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwSomeV[20] WHERE(HPwV[166:10855195])}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwIV[31:2590631] LIMIT(5)}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwIV[31:5] LIMIT(5)}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwIV[31:5] WHERE(HPwNoV[40]) LIMIT(10)}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwIV[31:5] WHERE(NOT(HPwSomeV[40])) LIMIT(10)}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link,claims[40][rank=best] AS P40,claims[19][rank=best] AS P19) FROM {HPwIV[31:5] LIMIT(5)}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link,claims[31] AS P31) FROM {items[23] WHERE(haslinks["enwiki"])}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link,claims[31] AS P31) FROM {items[1339,350,34,64,747,24242,636,3] WHERE(haslinks["enwiki"])}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {linkedto["enwiki#Universe"]}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link,sitelinks["enwiki"] AS sitelink) FROM {HPwSomeV[175]}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwSomeV[569] RANK(best) LIMIT(10)}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwIV[31:5] WHERE(HPwV[41:14] OR NOT (HPwV[321:1]))}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link,claims[1082][rank=best] AS P1082) FROM {HPwQV[1082:35000 TO 60000] ASC LIMIT(10)}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwQV[1082:35000 TO 60000] DESC RANK(best) WHERE(HPwV[31:515]) LIMIT(10)}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwQV[1082:LTE 1000] DESC RANK(best) LIMIT(10)}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwQV[1082:GTE 1000000] DESC RANK(best) WHERE(HPwV[31:515]) LIMIT(10)}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwQV[1082:GTE 1] DESC RANK(best) WHERE(HPwV[31:515]) LIMIT(10)}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwSV[856:"http://www.whitehouse.gov/"]}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwSV[311:"O\'reilly Pub\""] WHERE(NOT (HPwV[31:1,2,3]))}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link,claims[569] AS P569) FROM {HPwTV[569:+00000001949-01-01T00:00:00Z TO +00000001959-12-30T00:00:00Z] ASC LIMIT(10)}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwCV[625:AROUND 38.897669444444 -77.03655 2] LIMIT(10)}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwIV[31:5] QUALIFY(HPwV[1414:89 TO 90,1] AND HPwV[1414:356,46]) WHERE(HPwV[14:3.0] OR (HPwV[24:2.5] AND HPwV[98:5.6]))}';
$q[] = '(id,labels["en"] AS label) FROM INTERSECT($a,$b,$c) GIVEN($a = {items[1339,350,34,64,747,24242,636,3]} $b = {items[34,64,747,24242,636,3]} $c = {items[636,3]})';
$q[] = '(id,labels["en"] AS label) FROM DIFFERENCE($a,$b,$c) GIVEN($a = {items[1339,350,34,64,747,24242,636,3]} $b = {items[34,64,747]} $c = {items[636,3]})';
$q[] = '(id,labels["en"] AS label) FROM UNION($a,$b) GIVEN($a = {HPwSV[856:"http://www.nsa.gov/"]} $b = {HPwSV[856:"https://www.cia.gov/"]})';
$q[] = '(id,labels["en"] AS label) FROM UNION($a,$b) GIVEN($a = {items[1339,350]} $b = {items[34,64,747]} $c = {items[24242,636,3]})';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link,claims[40] AS P40) FROM {HPwIVWeb[23505] OUT[40] RANK(best)}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link,claims[40] AS P40) FROM {HPwIVWeb[23505] IN[40] RANK(best)}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link,claims[40] AS P40) FROM {HPwIVWeb[23505] OUT[40] IN[40] RANK(best)}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwIVWeb[30] OUT[150] IN[17,131]}';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM {HPwIVWeb[$PARENTS] OUT[40]} GIVEN($PARENTS = {HPwIV[40:23505]})';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link) FROM UNION($CHILDREN) GIVEN($PARENTS = {HPwIV[40:23505]} $CHILDREN = {HPwIVWeb[$PARENTS] OUT[40]})';
$q[] = '(id,labels["en"] AS label,sitelinks["enwiki"] AS link,claims[40] AS P40,claims[569] AS P569) FROM {HPwIVWeb[23505] OUT[40] RANK(best) WHERE(HPwV[569:GTE +00000001980-01-01T00:00:00Z])}';

foreach ( $q as $query ) {
	print( $query . "\n=====\n" );
	print( WdqQueryParser::parse( "$query" ) . "\n\n" );
}
