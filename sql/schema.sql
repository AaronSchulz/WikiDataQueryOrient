-- Some notes:
-- 1) 'rank' fields use the system: (-1=deprecated, 0=normal, 1=preferred).
-- 2) 'best' fields are 0 or 1 (1 if 'rank' is >= max rank of item statements for that property).
-- 3) 'sid' fields identify a statement ID, making it easy to reference in the full JSON.
-- 4) 'oid'/'iid' are denormalized out.id/in.id to avoid network I/O.

create database remote:localhost/WikiData root root plocal graph;

-- Item pages (Q entity)
create class Item extends V;
create property Item.id long;
-- Store labels as a map of <language> => <label>
create property Item.labels EMBEDDEDMAP string;
-- Store site links as a map of <site> => <site>#<title>
create property Item.sitelinks EMBEDDEDMAP string;
-- Store simplified claims as map of <property> => ((type,value,qlfrs),...)
create property Item.claims EMBEDDEDMAP embedded;
-- Store the IDs of properties and items referenced
create property Item.pids EMBEDDEDSET long; -- properties referenced
create property Item.iids EMBEDDEDSET long; -- items referenced
-- Flag things as deleted when deleted/moved
create property Item.deleted boolean;
-- Enforce basic field presence
alter property Item.id MANDATORY true;
alter property Item.labels MANDATORY true;
alter property Item.sitelinks MANDATORY true;
alter property Item.claims MANDATORY true;
alter property Item.pids MANDATORY true;
alter property Item.iids MANDATORY true;
-- Q codes are unique
create index ItemIdIdx on Item (id) unique;
-- Support looking up items by site links
create index ItemSiteLinksIdx on Item (sitelinks by value) notunique_hash_index;
-- Support query/item usage queries (includes broken references)
create index ItemPidsIdx on Item (pids,id) notunique;
create index ItemIidsIdx on Item (iids,id) notunique;

-- Property pages (P entity)
create class Property extends V;
create property Property.id long;
-- Store labels as a map of <language> => <label>
create property Property.labels EMBEDDEDMAP string;
-- See See http://www.wikidata.org/wiki/Special:ListDatatypes
create property Property.datatype string;
-- Flag things as deleted when deleted/moved
create property Property.deleted boolean;
-- Enforce basic field presence
alter property Property.id MANDATORY true;
alter property Property.labels MANDATORY true;
-- P codes are unique
create index ProperyIdIdx on Property (id) unique;

-- "Item X has an unspecific value for Property Y" relationships
create class HPwSomeV extends E;
create property HPwSomeV.out LINK Item;
create property HPwSomeV.in LINK Property;
create property HPwSomeV.rank short;
create property HPwSomeV.best short;
create property HPwSomeV.qlfrs EMBEDDEDMAP embedded;
create property HPwSomeV.oid long;
create property HPwSomeV.iid long;
create property HPwSomeV.sid string;
alter property HPwSomeV.rank MANDATORY true;
alter property HPwSomeV.best MANDATORY true;
alter property HPwSomeV.qlfrs MANDATORY true;
alter property HPwSomeV.oid MANDATORY true;
alter property HPwSomeV.iid MANDATORY true;
alter property HPwSomeV.sid MANDATORY true;

-- "Item X has no value for Property Y" relationships
create class HPwNoV extends E;
create property HPwNoV.out LINK Item;
create property HPwNoV.in LINK Property;
create property HPwNoV.rank short;
create property HPwNoV.best short;
create property HPwNoV.qlfrs EMBEDDEDMAP embedded;
create property HPwNoV.oid long;
create property HPwNoV.iid long;
create property HPwNoV.sid string;
alter property HPwNoV.rank MANDATORY true;
alter property HPwNoV.best MANDATORY true;
alter property HPwNoV.qlfrs MANDATORY true;
alter property HPwNoV.oid MANDATORY true;
alter property HPwNoV.iid MANDATORY true;
alter property HPwNoV.sid MANDATORY true;

-- "Item X has quantity value Z for Property Y" relationships
create class HPwQV extends E;
create property HPwQV.out LINK Item;
create property HPwQV.in LINK Property;
create property HPwQV.val double;
create property HPwQV.rank short;
create property HPwQV.best short;
create property HPwQV.qlfrs EMBEDDEDMAP embedded;
create property HPwQV.oid long;
create property HPwQV.iid long;
create property HPwQV.sid string;
alter property HPwQV.val MANDATORY true;
alter property HPwQV.rank MANDATORY true;
alter property HPwQV.best MANDATORY true;
alter property HPwQV.qlfrs MANDATORY true;
alter property HPwQV.oid MANDATORY true;
alter property HPwQV.iid MANDATORY true;
alter property HPwQV.sid MANDATORY true;
create index HPwQVIidValIdx on HPwQV (iid, val) notunique;

-- "Item X has timestamp value Z for Property Y" relationships
create class HPwTV extends E;
create property HPwTV.out LINK Item;
create property HPwTV.in LINK Property;
create property HPwTV.val long;
create property HPwTV.rank short;
create property HPwTV.best short;
create property HPwTV.qlfrs EMBEDDEDMAP embedded;
create property HPwTV.oid long;
create property HPwTV.iid long;
create property HPwTV.sid string;
alter property HPwTV.val MANDATORY true;
alter property HPwTV.rank MANDATORY true;
alter property HPwTV.best MANDATORY true;
alter property HPwTV.qlfrs MANDATORY true;
alter property HPwTV.oid MANDATORY true;
alter property HPwTV.iid MANDATORY true;
alter property HPwTV.sid MANDATORY true;
create index HPwTVIidValIdx on HPwTV (iid, val) notunique;

-- "Item X has string value Z for Property Y" relationships
create class HPwSV extends E;
create property HPwSV.out LINK Item;
create property HPwSV.in LINK Property;
create property HPwSV.val string;
create property HPwSV.rank short;
create property HPwSV.best short;
create property HPwSV.qlfrs EMBEDDEDMAP embedded;
create property HPwSV.oid long;
create property HPwSV.iid long;
create property HPwSV.sid string;
alter property HPwSV.val MANDATORY true;
alter property HPwSV.rank MANDATORY true;
alter property HPwSV.best MANDATORY true;
alter property HPwSV.qlfrs MANDATORY true;
alter property HPwSV.oid MANDATORY true;
alter property HPwSV.iid MANDATORY true;
alter property HPwSV.sid MANDATORY true;
create index HPwSVIidValIdx on HPwSV (iid, val) notunique_hash_index;

-- "Item X has coordinate value Z for Property Y" relationships
create class HPwCV extends E;
create property HPwCV.out LINK Item;
create property HPwCV.in LINK Property;
create property HPwCV.lat double;
create property HPwCV.lon double;
create property HPwCV.rank short;
create property HPwCV.best short;
create property HPwCV.qlfrs EMBEDDEDMAP embedded;
create property HPwCV.oid long;
create property HPwCV.iid long;
create property HPwCV.sid string;
alter property HPwCV.lat MANDATORY true;
alter property HPwCV.lon MANDATORY true;
alter property HPwCV.rank MANDATORY true;
alter property HPwCV.best MANDATORY true;
alter property HPwCV.qlfrs MANDATORY true;
alter property HPwCV.oid MANDATORY true;
alter property HPwCV.iid MANDATORY true;
alter property HPwCV.sid MANDATORY true;
create index HPwCVLocGeoIdx on HPwCV (lat, lon) spatial engine LUCENE;

-- "Item X has an Item value Z for Property Y" relationships
create class HPwIV extends E;
create property HPwIV.out LINK Item;
create property HPwIV.in LINK Property;
create property HPwIV.val long;
create property HPwIV.rank short;
create property HPwIV.best short;
create property HPwIV.qlfrs EMBEDDEDMAP embedded;
create property HPwIV.oid long;
create property HPwIV.iid long;
create property HPwIV.sid string;
alter property HPwIV.val MANDATORY true;
alter property HPwIV.rank MANDATORY true;
alter property HPwIV.best MANDATORY true;
alter property HPwIV.qlfrs MANDATORY true;
alter property HPwIV.oid MANDATORY true;
alter property HPwIV.iid MANDATORY true;
alter property HPwIV.sid MANDATORY true;

-- "Item X uses Item Z as value of Property Y" relationships
create class HIaPV extends E;
create property HIaPV.out LINK Item;
create property HIaPV.in LINK Item;
create property HIaPV.pid long;
create property HIaPV.rank short;
create property HIaPV.best short;
create property HIaPV.qlfrs EMBEDDEDMAP embedded;
create property HIaPV.oid long;
create property HIaPV.iid long;
create property HIaPV.sid string;
alter property HIaPV.pid MANDATORY true;
alter property HIaPV.rank MANDATORY true;
alter property HIaPV.best MANDATORY true;
alter property HIaPV.qlfrs MANDATORY true;
alter property HIaPV.oid MANDATORY true;
alter property HIaPV.iid MANDATORY true;
alter property HIaPV.sid MANDATORY true;
create index HIaPVIidPidIdx on HIaPV (iid, pid) notunique;

-- Metadata table for tracking DB status info (e.g. for feed updates)
create class DBStatus;
create property DBStatus.name string;
alter property DBStatus.name MANDATORY true;
create index DBStatusNameIdx on DBStatus (name) unique;
