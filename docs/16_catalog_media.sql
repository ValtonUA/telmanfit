-- Forward SQL 16: mutable image URL metadata for the equipment catalog.
ALTER TABLE kitchen_equipment ADD COLUMN IF NOT EXISTS image_url text NULL;
