WikiDataQueryOrient
===================

Importing scripts and query helper classes for storing WikiData information in OrientDB

*** DB schema and server setup ***

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

6) 	Run the sql/schema.sql via the console (e.g. console.sh).

7)	Grab a JSON dump file from http://dumps.wikimedia.org/other/wikidata/
    and store it somewhere (e.g. F:/importer/data).

*** Importing data ***

1) Import vertexes:
	php importWikiDataDump.php --dump F:/importer/data/20141124.json --phase vertexes --user root --password root --method=insert --posdir=F:/importer/pos

2) Import edges:
	php importWikiDataDump.php --dump F:/importer/data/20141124.json --phase edges --user root --password root --method=bulk_init --posdir=F:/importer/pos

*** Updating via API ***

All of this is very unfinished and WIP :)

1) Make sure at least all vertexes of a dump are finished being added

2) php updateWdqGraphViaFeed.php --user root --password root

*** Console ***

connect remote:localhost root root
connect remote:127.0.0.1/WikiData admin admin

*** WDQ query tester ***

php cli-api.php --user admin --password admin

*** Gremlin ***

g = new OrientGraph("remote:127.0.0.1/WikiData");