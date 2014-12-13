WikiDataQueryOrient
===================

Importing scripts and query helper classes for storing WikiData information in OrientDB

*** DB Schema Initialization Stuff ***
1)	Get OrientDB 2.0 (M3)

2)  Edit the server.sh file (or bat on Windows).
	Bump the JVM heap size to 2128m and set MAXDISKCACHE=-Dstorage.diskCache.bufferSize=8192.

3)  Set -XX:+UseConcMarkSweepGC in the java options while at it.
    Other GCs can be played around with.

4)	Also add -Dstorage.wal.syncOnPageFlush=false (remove after import)

5) 	Edit the server config XML file, setting log.file.level to "warning"

6) 	[Skip this if the lucene dist jar is in libs/ already (e.g. OrientDB 2.0+)]
    Follow https://github.com/orientechnologies/orientdb-lucene/wiki
	Use the SNAPSHOT for OrientDB 2.0 (M3) and build with:
		mvn assembly:assembly -DskipTests=true
	Copy the dist .jar file into the /lib directory (only use /plugin if you used the premade release jars)

7) 	Set the root user and password (e.g. "root"/"root")

8) 	Run the schema.sql via the console (e.g. console.sh)

9) 	Import vertexes:
	php importWikiDataDump.php --dump F:/importer/data/20141124.json --phase vertexes --user root --password root --posfile=pos/lastv.pos --method=insert

10) Import edges:
	php importWikiDataDump.php --dump F:/importer/data/20141124.json --phase edges --user root --password root --posfile=pos/laste.pos --method=bulk_init

*** Updating via API ***
php updateWdqGraphViaFeed.php --user root --password root --start 20141124000000 --posfile=pos/lastchangetime.pos

e.g.:
php N:/Dropbox/MW/WDQImporter/updateWdqGraphViaFeed.php  --user root --password root --start 20141124000000 --posfile=pos/lastchangetime.pos

*** Connecting ***

connect remote:127.0.0.1/WikiData admin admin

*** Example queries ***

select oid from HasPropertyWithStringValue where in.id=856 and val='http://www.whitehouse.gov/' TIMEOUT 5000;
select oid from HasPropertyWithStringValue where in in(select Item where id=856) and val='http://www.whitehouse.gov/' TIMEOUT 5000;

select oid from HasPropertyWithTimeValue where in.id=569 and val between 0 and 1417847993 TIMEOUT 5000;
select oid from HasPropertyWithTimeValue where in.id=569 and val between 315988712 and 631607912 TIMEOUT 5000;

select oid from HasPropertyWithDoubleValue where in.id=1082 and val between 800000 and 810000 TIMEOUT 5000;

select Item where pids contains(625) limit 10 TIMEOUT 3000;
select id from Item where pids_someval contains(625) limit 10 TIMEOUT 3000;
select Item where pids_noval contains(625) limit 10 TIMEOUT 3000;
select Item where pids contains(21) limit 10 TIMEOUT 3000;
select Item where pids_someval contains(21) limit 10 TIMEOUT 3000;
select Item where pids_noval contains(21) limit 10 TIMEOUT 3000;
select id from Item where pids_noval contains(21) limit 10 TIMEOUT 3000;
select id from Item where pids_noval contains(102) limit 10 TIMEOUT 3000;

select out.id from HasPropertyWithCoordinateValue where [lat,lon,$spatial] NEAR [38.897669444444,-77.03655,{"maxDistance": 5}] and in.id = 625
select out.id,lat,lon,$distance from HasPropertyWithCoordinateValue where [lat,lon,$spatial] NEAR [38.897669444444,-77.03655,{"maxDistance": 1}] and in.id = 625

select count(out) from HasPropertyWithItemValue where pid=31 and in.id=5 TIMEOUT 1000
select oid from HasPropertyWithItemValue where pid=31 and in.id=5 TIMEOUT 1000
select out.id from HasPropertyWithItemValue where pid=31 and in.id=5 TIMEOUT 1000

select expand(sitelinks) From Item where id = 1

select expand(claims.P47.mainsnak.datavalue.value.numeric-id) from Item where id='43'

select expand(claims.P298.mainsnak) from Item where id='43' and claims['P298']['mainsnak']['datavalue']['value'] = 'TUR'

select expand(claims.P553.qualifiers) from Item where id='233158'

select expand(claims.P47.mainsnak.datavalue) from Item where id='43'
select expand(claims.P47.mainsnak.datavalue.value) from Item where id='43'
select expand(claims.P47.mainsnak.datavalue.value.numeric-id) from Item where id='43'

select out.id from HasPropertyWithItemValue where pid=279 and in.id=5113
select expand(out) from HasPropertyWithItemValue where pid=279 and in.id=5113
select id from (select expand(out) from HasPropertyWithItemValue where pid=279 and in.id=5113)

select expand(out) from HasPropertyWithItemValue where pid=31 and in.id=1549591
select id from (select expand(out) from HasPropertyWithItemValue where pid=31 and in.id=1549591)
select count(out) from HasPropertyWithItemValue where pid=31 and in.id=1549591

select id from Item where sitelinks contains('enwiki#Universe')
select expand(out(HasPropertyWithItem)) from Item where sitelinks contains('enwiki#Universe')
select id from (select expand(out(HasPropertyWithItem)) from Item where sitelinks contains('enwiki#Universe'))
select out.id from HasPropertyWithItemValue where pid=31 and in.id=323 and out in (select from Item where sitelinks contains('enwiki#Universe'))

select from HasPropertyWithItemValue where pid=279
select from HasPropertyWithItemValue where pid=279 and in in (select from Item where id=5113)

select id from (select expand(out) from HasPropertyWithItemValue where pid=279 and in=(select from Item where id=5113))
select id from (select expand(out) from HasPropertyWithItemValue where pid=279 and in=(select from Item where id=5113) and out=(select id from Item where sitelinks contains('enwiki#Bird of prey')))
