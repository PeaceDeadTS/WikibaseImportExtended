WikibaseImportExtended
=======================

Maintenance script that copies entities from another Wikibase (by default Wikidata) into the local repository. Labels, descriptions and aliases are copied. Statements are copied after referenced properties and items have been created, and those references are remapped to the new local ids.

Source ids are not reused. `Q64` on Wikidata becomes a new local id. The pair is stored in `wbs_entity_mapping`.

Sitelinks are dropped unless `--sitelinks` is passed. A repository that does not list the source wiki's sites in its sites table cannot save those links.

Install
-------

The directory name must be `WikibaseImportExtended`. No Composer install is required.

```php
wfLoadExtension( 'WikibaseImportExtended' );
```

Load it after WikibaseRepository. Then create the mapping table:

```
php maintenance/update.php --quick
```

The account passed with `--user` (default `$wgWBImportUser`, `WikibaseImport`) must already exist and be allowed to edit, create pages and create properties.

Usage
-----

From the MediaWiki root:

```
php maintenance/run.php WikibaseImportExtended:importEntities --entity Q64 --dry-run
php maintenance/run.php WikibaseImportExtended:importEntities --entity Q64 --user WikibaseImport
php maintenance/run.php WikibaseImportExtended:importEntities --file presidents.csv
php maintenance/run.php WikibaseImportExtended:importEntities --all-properties
php maintenance/run.php WikibaseImportExtended:importEntities --query P31:Q515
php maintenance/run.php WikibaseImportExtended:importEntities --range Q1:Q20
printf 'Q%s\n' 1 2 3 | php maintenance/run.php WikibaseImportExtended:importEntities --stdin
```

`--dry-run` reads the source API and the mapping table and prints what would be created. It does not save entities and does not insert mapping rows.

Only the requested entities receive statements. Entities they point at (properties, countries, values) are created with labels, descriptions and aliases only. Import those ids themselves when their statements are needed.

`--query` and `--range` can match a very large set. Try one `--entity` with `--dry-run` first.

Configuration
-------------

* `$wgWBImportSourceApi` — source `api.php` (default Wikidata)
* `$wgWBImportQueryUrl` — SPARQL endpoint for `--query`
* `$wgWBImportSourcePropertyNamespace` — property namespace on the source wiki (Wikidata: 120)
* `$wgWBImportUser` — local user used when `--user` is omitted
* `$wgWBImportSitelinks` — keep sitelinks (default false)
* `$wgWBImportUserAgent` — HTTP User-Agent sent to the source
