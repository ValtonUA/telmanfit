-- C3: forward-only, одноразовий перехід тільки legacy draft.
CREATE OR REPLACE FUNCTION recipe_batch_root_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='UPDATE' THEN
  IF OLD.status='published' THEN RAISE EXCEPTION 'Published recipe version immutable'; END IF;
  IF NEW.editor_contract<>OLD.editor_contract THEN
   IF OLD.editor_contract<>'legacy_v1' OR NEW.editor_contract<>'batch_v1'
    OR OLD.status<>'draft' OR NEW.status<>'draft'
    OR (to_jsonb(NEW)-ARRAY['editor_contract','servings_count','lock_version']) IS DISTINCT FROM
       (to_jsonb(OLD)-ARRAY['editor_contract','servings_count','lock_version'])
    OR NOT EXISTS(SELECT 1 FROM recipe_batch_formats f WHERE f.recipe_version_id=OLD.id AND f.code='single'
      AND (f.policy_snapshot->'legacy_root')-'lock_version' = to_jsonb(OLD)-'lock_version')
    OR NOT EXISTS(SELECT 1 FROM recipe_ingredients WHERE recipe_version_id=OLD.id)
    OR EXISTS(SELECT 1 FROM recipe_ingredients r WHERE r.recipe_version_id=OLD.id AND NOT EXISTS(
      SELECT 1 FROM recipe_batch_quantities q WHERE q.recipe_ingredient_id=r.id AND q.format_code='week'
       AND q.input_snapshot->'legacy_source'=to_jsonb(r))) THEN
    RAISE EXCEPTION 'Legacy conversion requires draft and exact original provenance';
   END IF;
  END IF;
  IF NEW.editor_contract='batch_v1' AND NEW.lock_version<>OLD.lock_version+1 THEN
   RAISE EXCEPTION 'Batch update must advance revision exactly once' USING ERRCODE='40001';
  END IF;
  -- Legacy root/details теж мають CAS; published branch вище лишається immutable.
  IF NEW.editor_contract='legacy_v1' THEN NEW.lock_version:=OLD.lock_version+1; END IF;
 END IF;
 IF NEW.editor_contract='batch_v1' AND NEW.servings_count<>1 THEN RAISE EXCEPTION 'Batch A divisor is 1'; END IF;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION recipe_batch_detail_guard() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE v_id integer;
BEGIN
 FOR v_id IN SELECT DISTINCT x FROM unnest(ARRAY[
  CASE WHEN TG_OP<>'INSERT' THEN OLD.recipe_version_id END,
  CASE WHEN TG_OP<>'DELETE' THEN NEW.recipe_version_id END]) x WHERE x IS NOT NULL ORDER BY x LOOP
  PERFORM 1 FROM recipe_versions WHERE id=v_id FOR UPDATE;
  PERFORM assert_recipe_version_editable(v_id);
  UPDATE recipe_versions SET lock_version=lock_version+1,publication_review=NULL WHERE id=v_id;
 END LOOP;
 RETURN CASE WHEN TG_OP='DELETE' THEN OLD ELSE NEW END;
END $$;
