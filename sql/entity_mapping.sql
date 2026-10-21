CREATE TABLE /*_*/wbs_entity_mapping (
  -- Local entity id assigned by this wiki
  wbs_local_id VARBINARY(255) NOT NULL PRIMARY KEY,
  -- Entity id on the source wiki (for example Wikidata Q64)
  wbs_original_id VARBINARY(255) NOT NULL
) /*$wgDBTableOptions*/;

CREATE UNIQUE INDEX /*i*/wbs_original_idx ON /*_*/wbs_entity_mapping (wbs_original_id);
