CREATE TABLE IF NOT EXISTS project_locales (
    id BIGSERIAL PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    version_id INTEGER REFERENCES source_versions(id) ON DELETE CASCADE,
    locale_code VARCHAR(10) NOT NULL REFERENCES locales(code) ON DELETE CASCADE,
    activated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE project_locales
    ADD COLUMN IF NOT EXISTS version_id INTEGER REFERENCES source_versions(id) ON DELETE CASCADE;

DELETE FROM project_locales AS newer
USING project_locales AS older
WHERE newer.project_id = older.project_id
  AND newer.version_id IS NOT DISTINCT FROM older.version_id
  AND LOWER(newer.locale_code) = LOWER(older.locale_code)
  AND newer.id > older.id;

UPDATE project_locales
SET locale_code = LOWER(locale_code)
WHERE locale_code <> LOWER(locale_code);

DO $$
DECLARE constraint_row RECORD;
BEGIN
    FOR constraint_row IN
        SELECT conname
        FROM pg_constraint
        WHERE conrelid = 'project_locales'::regclass
          AND contype = 'u'
          AND pg_get_constraintdef(oid) = 'UNIQUE (project_id, locale_code)'
    LOOP
        EXECUTE format('ALTER TABLE project_locales DROP CONSTRAINT %I', constraint_row.conname);
    END LOOP;
END
$$;

CREATE UNIQUE INDEX IF NOT EXISTS project_locales_project_global_uidx
    ON project_locales (project_id, locale_code)
    WHERE version_id IS NULL;
CREATE UNIQUE INDEX IF NOT EXISTS project_locales_project_version_uidx
    ON project_locales (project_id, version_id, locale_code)
    WHERE version_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS project_locales_project_idx
    ON project_locales (project_id, version_id, locale_code);

INSERT INTO project_locales (project_id, locale_code)
SELECT p.id, l.code
FROM projects AS p
CROSS JOIN locales AS l
WHERE l.code IN ('vi', 'en')
  AND NOT EXISTS (
      SELECT 1
      FROM project_locales AS existing
      WHERE existing.project_id = p.id
        AND existing.locale_code = l.code
  );
