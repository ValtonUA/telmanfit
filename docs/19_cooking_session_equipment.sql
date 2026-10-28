-- T5.7b additive forward projection; базові SQL17/18 лишаються незмінними.
-- Віковий snapshot авторського обладнання для конкретної кухонної сесії.
CREATE TABLE cooking_session_equipment (
 version_id integer NOT NULL,
 session_id integer NOT NULL,
 position smallint NOT NULL CHECK(position>0),
 equipment_id smallint NOT NULL REFERENCES kitchen_equipment(id) ON DELETE RESTRICT,
 name_snapshot text NOT NULL CHECK(length(btrim(name_snapshot)) BETWEEN 1 AND 300),
 image_url_snapshot text CHECK(image_url_snapshot IS NULL OR length(image_url_snapshot)<=2048),
 PRIMARY KEY(session_id,position),
 UNIQUE(session_id,equipment_id),
 FOREIGN KEY(session_id,version_id) REFERENCES cooking_sessions(id,version_id) ON DELETE RESTRICT
);
CREATE INDEX cooking_session_equipment_version_idx ON cooking_session_equipment(version_id,session_id,position);
CREATE TRIGGER cooking_session_equipment_detail_guard
 BEFORE INSERT OR UPDATE OR DELETE ON cooking_session_equipment
 FOR EACH ROW EXECUTE FUNCTION cooking_detail_guard();
