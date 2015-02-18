WikiDataQueryOrient
===================

Importing scripts and query helper classes for storing WikiData information in OrientDB

DB schema and server setup
--------------

1)	Get and compile OrientDB 2.0 SNAPSHOT (https://github.com/orientechnologies/orientdb).
	Use the 'develop' branch.

2)	Get and compile orientdb-lucene (https://github.com/orientechnologies/orientdb-lucene).
	Put the dist jar in the orient DB /libs directory.
	Use the 'develop' branch. You might want to use -DskipTests=true with Maven.

3)  Edit the server.sh file (or bat on Windows).
	Bump the JVM heap size to 2128m and set MAXDISKCACHE=-Dstorage.diskCache.bufferSize=8192.
	Set -XX:+UseConcMarkSweepGC in the java options while at it.

4) 	Edit the server config XML file, setting log.file.level to "warning".

5) 	Set the root user and password (e.g. "root"/"root").

6) 	Run the sql/schema.sql via the OrientDB console (e.g. console.sh).

7)	Save JSON dump file from http://dumps.wikimedia.org/other/wikidata/

Importing data
--------------

1) Import live vertexes:
	php importDump.php --user admin --password admin --method=insert --dump <dump path> --posdir=F:/importer/pos

2) Import stub vertexes:
	php createStubEntities.php --user admin --password admin --posdir=F:/importer/pos

3) Import edges:
	php connectEntities.php --user admin --password admin --method=bulk_init --posdir=F:/importer/pos

Updating via the API
--------------

1) Make sure at least all vertexes of a dump are finished being added

2) php updateWdqGraphViaFeed.php --user root --password root

Console
--------------

1) Start the OrientDB console (e.g. console.sh).

2) Run the first command for server level access, the later to connect to the DB
connect remote:localhost root root
connect remote:127.0.0.1/WikiData admin admin

g = new OrientGraph("remote:127.0.0.1/WikiData");

WDQ query tester
--------------

php cli-api.php --user admin --password admin

You can then issue commands in the query language (see below).

Query language
--------------

Queries are of the form:
(%PROJECTION%>,[%PROJECTION%,]*) FROM %QUERY% [GIVEN( %variable%=%QUERY% )]
```
* %PROJECTION% is of the form %FIELD% or %FIELD% AS %ALIAS%
* A %FIELD% can be id, claims, sitelinks, or labels
* The [] operator can be used on fields to get sub-fields like "sitelinks['en']"; an alias is required for this
* An %ALIAS% should be alphanumeric characters (starting with a non-number)
* A variable is an alphanumeric word (starting with a non-number) that a prefixed with "$", e.g. "$SOME_VAR"
```
```
%QUERY% can be one of the following, which generate sets of Items:
* UNION[%list of variables%]
* INTERSECT[%list of variables%]
* DIFFERENCE[%list of variables%]
* {HIaPV[%ITEMID%} [CONTINUE(%ITEMID%)] [RANK=(best|preferred|normal)] [QUALIFY(%FILTERQUERY%)] [WHERE(%FILTERQUERY%)]
* {HPwSomeV[%PROPERTYID%]} [CONTINUE=%ITEMID%] [RANK=(best|preferred|normal)] [QUALIFY(%FILTERQUERY%)] [WHERE(%FILTERQUERY%)]
* {HPwIV[%PROPERTYID%} [CONTINUE(%ITEMID%)] [RANK=(best|preferred|normal)] [QUALIFY(%FILTERQUERY%)] [WHERE(%FILTERQUERY%)]
* {HPwSV[%PROPERTYID%]} [CONTINUE(%ITEMID%)] [RANK=(best|preferred|normal)] [QUALIFY(%FILTERQUERY%)] [WHERE(%FILTERQUERY%)]
* {HPwTV[%PROPERTYID%]} [CONTINUE(%ITEMID%)] [ASC|DESC] [RANK=(best|preferred|normal)] [QUALIFY(%FILTERQUERY%)] [WHERE(%FILTERQUERY%)]
* {HPwQV[%PROPERTYID%]} [CONTINUE(%ITEMID%)] [ASC|DESC] [RANK=(best|preferred|normal)] [QUALIFY(%FILTERQUERY%)] [WHERE(%FILTERQUERY%)]
* {HPwCV[%PROPERTYID%]} [CONTINUE(%ITEMID%)] [RANK=(best|preferred|normal)] [QUALIFY(%FILTERQUERY%)] [WHERE(%FILTERQUERY%)]
* {HPwIV[%PROPERTYID%:%ITEMID%]} [CONTINUE(%ITEMID%)] [RANK=(best|preferred|normal)] [QUALIFY(%FILTERQUERY%)] [WHERE(%FILTERQUERY%)]
* {HPwSV[%PROPERTYID%:%quoted string%]} [CONTINUE(%ITEMID%)] [RANK=(best|preferred|normal)] [QUALIFY(%FILTERQUERY%)] [WHERE(%FILTERQUERY%)]
* {HPwTV[%PROPERTYID%:%list of dates/date ranges%]} [SKIP(%integer%)] [ASC|DESC] [RANK=(best|preferred|normal)] [QUALIFY(%FILTERQUERY%)] [WHERE(%FILTERQUERY%)]
* {HPwQV[%PROPERTYID%:%list of doubles/double ranges%]} [SKIP(%integer%)] [ASC|DESC] [RANK=(best|preferred|normal)] [QUALIFY(%FILTERQUERY%)] [WHERE(%FILTERQUERY%)]
* {HPwCV[%PROPERTYID%:list of AROUND %lat% %lon% %km range%]} [SKIP(%integer%)] [RANK=(best|preferred|normal)] [QUALIFY(%FILTERQUERY%)] [WHERE(%FILTERQUERY%)]
* {HPwIVWeb[%PROPERTYID%:%list of ITEMID or a single variable%]} [IN[%list of property IDs%]] [OUT[%list of property IDs%] [MAXDEPTH(%integer%) [RANK=(best|preferred|normal)] [QUALIFY(%FILTERQUERY%)] [WHERE(%FILTERQUERY%)]
* {items[%LIST OF ITEMID%]} [WHERE(%FILTERQUERY%)]
* {linkedto[%LIST OF SITELINK%]} [WHERE(%FILTERQUERY%)]
```
The above all support an optional "LIMIT(%MAXRECORDS%)" at the end.
* RANK is used to filter claims by their assigned rank.
* QUALIFY puts conditions on claim qualifiers and WHERE puts them on the item claims.
```
%FILTERQUERY% supports AND/OR/NOT and can use:
* HPwV[%PROPERTYID%,%value or value range%[;rank=(best|preferred|normal)]]
* HPwNoV[%list of %PROPERTYID%[;rank=(best|preferred|normal)]]
* HPwSomeV[%list of %PROPERTYID%[;rank=(best|preferred|normal)]]
* HPwAnyV[%list of %PROPERTYID%[;rank=(best|preferred|normal)]]
* haslinks[%list of sitelinks%]
```
Note: lists always use commas as separators. Ranges can be specified like:
* X TO Y
* GT X
* GTE X
* LT X
* LTE X
Timestamps should appear like +2001-01-01T00:00:00Z (use a minus sign for BCE dates)

CONTINUE is used for paging through results.

Quick query definition:
* UNION/INTERSECT/DIFFERENCE: mean what they say, and can take multiple arguments (which must be variables from the GIVEN statement)
* HPwIV: "Has the given property with this item value"
* HIaPV: "Has this item as value for some property"
* HPwSV: "Has the given property with this string value"
* HPwTV: "Has the given property with these time values or in these time ranges"
* HPwQV: "Has the given property with these double values or in these double ranges"
* HPwCV: "Has the given property with coordinates around these coordinates"
* HPwSomeV: "Has the given property with some known unkown value"
* HPwNoV: "Known not have the given property"
* HPwAnyV: "Has the given property with any of these values or in these ranges"
* haslinks: "Has sitelinks in any of these languages"
* linkedto: "Is linked to any of these sitelinks"
* items: "Get items with these IDs"
* HPwIVWeb: "Follow claims of specified properties to other items recursively using a starting item list"

Site links are of the form "<site>#<title>".

Example of query syntax:
```sql
 (
	id,
	sitelinks['en'] AS sitelink,
	labels['en'] AS label,
	claims[X] AS PX,
	claims[Y] AS PY
 )
 FROM {HPwIVWeb[$SOMEITEMS] OUT[40] MAXDEPTH(3)}
 GIVEN (
	$SOME_ITEMS = {HPwIVWeb[X] OUT[X,Y]}
	$OTHER_ITEMS = {HPwIVWeb[$SOME_ITEMS] IN[X,Y]}
	$ITEMS_A = {HPwQV[X:A] DESC RANK(best) QUALIFY(HPwV[X:Y]) WHERE(HPwV[X:Y])}
	$ITEMS_B = {HPwQV[Y:B TO C, GTE D]}
	$BOTH_AB = UNION($ITEMS_A,$ITEMS_B)
	$DIFF_AB = DIFFERENCE($ITEMS_A,$ITEMS_B)
	$INTERSECT_AB = INTERSECT($ITEMS_A,$ITEMS_B)
	$SET_A = {HPwTV[X:D1 to D2] ASC RANK(best) QUALIFY(HPwV[X:Y]) WHERE(HPwV[X:Y]) LIMIT(100)}
	$SET_B = {HPwCV[X:AROUND A B C,AROUND A B C] RANK(best) QUALIFY(HPwV[X:Y]) WHERE(HPwV[X:Y])}
	$SET_C = {HPwSV[X:"cat"] RANK(best) QUALIFY(HPwV[X:Y]) WHERE(HPwV[X:Y])}
	$STUFF = {items[2,425,62,23]}
	$WLINK = {HPwIV[X:A] WHERE(link[X,Y])}
 )
```
