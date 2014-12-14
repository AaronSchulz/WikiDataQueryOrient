WikiDataQueryOrient
===================

Importing scripts and query helper classes for storing WikiData information in OrientDB

*** DB schema and server setup ***

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

8) 	Run the sql/schema.sql via the console (e.g. console.sh)

9)	Grab a JSON dump file from http://dumps.wikimedia.org/other/wikidata/
    and store it somewhere (e.g. F:/importer/data).

*** Importing data ***

1) Import vertexes:
	php importWikiDataDump.php --dump F:/importer/data/20141124.json --phase vertexes --user root --password root --posfile=pos/lastv.pos --method=insert

2) Import edges:
	php importWikiDataDump.php --dump F:/importer/data/20141124.json --phase edges --user root --password root --posfile=pos/laste.pos --method=bulk_init

*** Updating via API ***

All of this is very unfinished and WIP :)

php updateWdqGraphViaFeed.php --user root --password root --start 20141124000000 --posfile=pos/lastchangetime.pos

*** Connecting ***

connect remote:127.0.0.1/WikiData admin admin
