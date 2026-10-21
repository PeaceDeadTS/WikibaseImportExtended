WikibaseImportExtended
=======================

Maintenance script that copies entities from another Wikibase (by default Wikidata) into the local repository. Labels, descriptions and aliases are copied. Statements are not copied unless `--statements` or `$wgWBImportStatements` is set. With that switch, statements of the requested entities are copied after referenced properties and items have been created, and those references are remapped to the new local ids.

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

The account passed with `--user` (default `$wgWBImportUser`, `WikibaseImport`) must already exist and be allowed to edit, create pages and create properties. An empty `$wgWBImportUser` falls back to `WikibaseImport`. Dry-run uses the same check and prints `Would import as …`. If the account is missing or lacks a right, the script stops before it fetches entities.

Special:WikibaseImport is the same check, then import, for one entity. Check runs a dry-run and shows what would be copied and which account would own the edits. Import is shown only when that check has no errors. The form can name a different local account. The right `wikibaseimport` is granted to the groups in `$wgWBImportGroups` (default `sysop` and `bureaucrat`). Those groups also get a link in the left navigation. The page is listed on Special:SpecialPages.

Usage
-----

From the MediaWiki root:

```
php maintenance/run.php WikibaseImportExtended:importEntities --entity Q64 --dry-run --user WikibaseImport
php maintenance/run.php WikibaseImportExtended:importEntities --entity Q64 --dry-run --user WikibaseImport --statements
php maintenance/run.php WikibaseImportExtended:importEntities --entity Q64 --user WikibaseImport
php maintenance/run.php WikibaseImportExtended:importEntities --entity Q64 --user WikibaseImport --statements
php maintenance/run.php WikibaseImportExtended:importEntities --file presidents.csv
php maintenance/run.php WikibaseImportExtended:importEntities --all-properties
php maintenance/run.php WikibaseImportExtended:importEntities --query P31:Q515
php maintenance/run.php WikibaseImportExtended:importEntities --range Q1:Q20
printf 'Q%s\n' 1 2 3 | php maintenance/run.php WikibaseImportExtended:importEntities --stdin
```

`--dry-run` reads the source API and the mapping table and prints what would be created, including the account that would own the edits. It does not save entities and does not insert mapping rows.

Without `--statements`, only labels, descriptions and aliases of the requested ids are imported. Referenced entities are not fetched. With `--statements`, only the requested entities receive statements. Entities they point at are created with labels, descriptions and aliases only. Import those ids themselves when their statements are needed.

A repeated run does not create a second entity while the local page still exists. If that page was deleted, the same local id is created again as a new page. The archived revision is not restored, so statements that were on the deleted page do not come back. Statements are copied only when `--statements` is set, and then they are copied by the current importer onto the new page. A page that still exists, including a redirect, is left unchanged. The mapping row is kept.

`--query` and `--range` can match a very large set. Try one `--entity` with `--dry-run` first.

Configuration
-------------

* `$wgWBImportSourceApi` — source `api.php` (default Wikidata)
* `$wgWBImportQueryUrl` — SPARQL endpoint for `--query`
* `$wgWBImportSourcePropertyNamespace` — property namespace on the source wiki (Wikidata: 120)
* `$wgWBImportUser` — local user used when `--user` and the web form leave the name empty. An empty value means `WikibaseImport`
* `$wgWBImportGroups` — groups that receive the `wikibaseimport` right (default `sysop` and `bureaucrat`). Set the array in LocalSettings to replace the list
* `$wgWBImportSitelinks` — keep sitelinks (default false)
* `$wgWBImportStatements` — copy statements of requested entities (default false). The `--statements` flag does the same for one run.
* `$wgWBImportUserAgent` — HTTP User-Agent sent to the source
