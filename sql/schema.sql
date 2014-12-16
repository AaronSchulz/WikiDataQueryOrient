-- Some notes:
-- 1) 'rank' fields use the system: (-1=deprecated, 0=normal, 1=preferred).
-- 2) 'best' fields are 0 or 1 (1 if 'rank' is >= max rank of item statements for that property).
-- 3) 'sid' fields identify a statement ID, making it easy to reference in the full JSON.
-- 4) oid/iid are denormalized out.id/in.id to avoid network I/O.

create database remote:localhost/WikiData root root plocal graph;

-- Item pages (Q entity)
create class Item extends V;
create property Item.id long;
alter property Item.id MANDATORY true;
-- Store site links as a map of <site> => <site>#<title>
create property Item.sitelinks EMBEDDEDMAP string;
-- Store the IDs of properties and items referenced
create property Item.pids EMBEDDEDSET long; -- properties referenced
create property Item.iids EMBEDDEDSET long; -- items referenced
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
alter property Property.id MANDATORY true;
-- See See http://www.wikidata.org/wiki/Special:ListDatatypes
create property Property.datatype string;
-- P codes are unique
create index ProperyIdIdx on Property (id) unique;

create class HP extends E;
create property HP.out LINK Item;
create property HP.in LINK Property;
create property HP.rank short;
create property HP.best short;
create property HP.oid long;
create property HP.iid long;
create property HP.sid string;

create class HPwSomeV extends E;
create property HPwSomeV.out LINK Item;
create property HPwSomeV.in LINK Property;
create property HPwSomeV.rank short;
create property HPwSomeV.best short;
create property HPwSomeV.oid long;
create property HPwSomeV.iid long;
create property HPwSomeV.sid string;

create class HPwNoV extends E;
create property HPwNoV.out LINK Item;
create property HPwNoV.in LINK Property;
create property HPwNoV.rank short;
create property HPwNoV.best short;
create property HPwNoV.oid long;
create property HPwNoV.iid long;
create property HPwNoV.sid string;

create class HPwIV extends E;
create property HPwIV.out LINK Item;
create property HPwIV.in LINK Item;
create property HPwIV.pid long;
create property HPwIV.rank short;
create property HPwIV.best short;
create property HPwIV.oid long;
create property HPwIV.iid long;
create property HPwIV.sid string;
create index HPwIVIidPidIdx on HPwIV (iid, pid) notunique;

create class HPwQV extends E;
create property HPwQV.out LINK Item;
create property HPwQV.in LINK Property;
create property HPwQV.val double;
create property HPwQV.rank short;
create property HPwQV.best short;
create property HPwQV.oid long;
create property HPwQV.iid long;
create property HPwQV.sid string;
create index HPwQVIidValIdx on HPwQV (iid, val) notunique;

create class HPwSV extends E;
create property HPwSV.out LINK Item;
create property HPwSV.in LINK Property;
create property HPwSV.val string;
create property HPwSV.rank short;
create property HPwSV.best short;
create property HPwSV.oid long;
create property HPwSV.iid long;
create property HPwSV.sid string;
create index HPwSVIidValIdx on HPwSV (iid, val) notunique_hash_index;

create class HPwTV extends E;
create property HPwTV.out LINK Item;
create property HPwTV.in LINK Property;
create property HPwTV.val long;
create property HPwTV.rank short;
create property HPwTV.best short;
create property HPwTV.oid long;
create property HPwTV.iid long;
create property HPwTV.sid string;
create index HPwTVIidValIdx on HPwTV (iid, val) notunique;

create class HPwCV extends E;
create property HPwCV.out LINK Item;
create property HPwCV.in LINK Property;
create property HPwCV.lat double;
create property HPwCV.lon double;
create property HPwCV.rank short;
create property HPwCV.best short;
create property HPwCV.oid long;
create property HPwCV.iid long;
create property HPwCV.sid string;
create index HPwCVLocGeoIdx on HPwCV (lat, lon) spatial engine LUCENE;

-- Metadata table for tracking DB status info (e.g. for feed updates)
create class DBStatus;
create property DBStatus.name string;
alter property DBStatus.name MANDATORY true;
create index DBStatusNameIdx on DBStatus (name) unique;
