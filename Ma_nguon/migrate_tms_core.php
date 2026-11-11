<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

try {
    $conn = db();
    if (pg_query($conn, 'BEGIN') === false) {
        throw new RuntimeException('MIGRATION_TRANSACTION_FAILED');
    }

    queryParams(
        $conn,
        'ALTER TABLE source_versions
         ADD COLUMN IF NOT EXISTS frozen_at TIMESTAMP WITH TIME ZONE,
         ADD COLUMN IF NOT EXISTS frozen_by_user_id INT REFERENCES users(id) ON DELETE SET NULL,
         ADD COLUMN IF NOT EXISTS source_url VARCHAR(2048)',
        []
    );
    queryParams($conn, 'ALTER TABLE translations DROP COLUMN IF EXISTS assigned_to_user_id', []);
    queryParams(
        $conn,
        'CREATE TABLE IF NOT EXISTS translation_comments (
            id BIGSERIAL PRIMARY KEY,
            string_id INT NOT NULL REFERENCES strings(id) ON DELETE CASCADE,
            locale VARCHAR(10) NOT NULL REFERENCES locales(code) ON DELETE CASCADE,
            user_id INT REFERENCES users(id) ON DELETE SET NULL,
            comment TEXT NOT NULL CHECK (length(trim(comment)) BETWEEN 1 AND 5000),
            created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        []
    );
    queryParams(
        $conn,
        'CREATE INDEX IF NOT EXISTS translation_comments_string_locale_idx
         ON translation_comments (string_id, locale, created_at, id)',
        []
    );
    queryParams(
        $conn,
        'CREATE TABLE IF NOT EXISTS source_version_changes (
            id BIGSERIAL PRIMARY KEY,
            source_version_id INT NOT NULL REFERENCES source_versions(id) ON DELETE CASCADE,
            key_path TEXT NOT NULL,
            change_type VARCHAR(10) NOT NULL CHECK (change_type IN (\'added\', \'modified\', \'deleted\')),
            old_text TEXT,
            new_text TEXT,
            created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (source_version_id, key_path)
        )',
        []
    );
    queryParams(
        $conn,
        'CREATE OR REPLACE FUNCTION reject_frozen_source_version_mutation() RETURNS trigger AS $$
         DECLARE
             version_id INT;
             frozen_time TIMESTAMP WITH TIME ZONE;
         BEGIN
             IF TG_TABLE_NAME = \'source_versions\' THEN
                 IF OLD.frozen_at IS NOT NULL THEN
                     IF TG_OP = \'UPDATE\'
                        AND NEW.project_id IS NOT DISTINCT FROM OLD.project_id
                        AND NEW.version_number IS NOT DISTINCT FROM OLD.version_number
                        AND NEW.frozen_at IS NOT DISTINCT FROM OLD.frozen_at
                        AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at
                        AND (NEW.frozen_by_user_id IS NOT DISTINCT FROM OLD.frozen_by_user_id
                             OR NEW.frozen_by_user_id IS NULL) THEN
                         RETURN NEW;
                     END IF;
                     RAISE EXCEPTION \'Frozen source versions are immutable\';
                 END IF;
                 IF TG_OP = \'DELETE\' THEN
                     RETURN OLD;
                 END IF;
                 RETURN NEW;
             ELSIF TG_TABLE_NAME = \'strings\' THEN
                 version_id := COALESCE(OLD.source_version_id, NEW.source_version_id);
             ELSE
                 SELECT source_version_id INTO version_id FROM strings
                 WHERE id = COALESCE(OLD.string_id, NEW.string_id);
             END IF;

             SELECT frozen_at INTO frozen_time FROM source_versions
             WHERE id = version_id FOR SHARE;
             IF frozen_time IS NOT NULL THEN
                 IF TG_TABLE_NAME = \'translations\' AND TG_OP = \'UPDATE\'
                    AND NEW.id IS NOT DISTINCT FROM OLD.id
                    AND NEW.string_id IS NOT DISTINCT FROM OLD.string_id
                    AND NEW.locale IS NOT DISTINCT FROM OLD.locale
                    AND NEW.translated_text IS NOT DISTINCT FROM OLD.translated_text
                    AND NEW.status IS NOT DISTINCT FROM OLD.status
                    AND NEW.updated_at IS NOT DISTINCT FROM OLD.updated_at
                    AND OLD.user_id IS NOT NULL
                    AND NEW.user_id IS NULL THEN
                     RETURN NEW;
                 END IF;
                 RAISE EXCEPTION \'Translations in a frozen source version are immutable\';
             END IF;
             IF TG_OP = \'DELETE\' THEN
                 RETURN OLD;
             END IF;
             RETURN NEW;
         END;
         $$ LANGUAGE plpgsql',
        []
    );
    queryParams($conn, 'DROP TRIGGER IF EXISTS source_versions_frozen_guard ON source_versions', []);
    queryParams(
        $conn,
        'CREATE TRIGGER source_versions_frozen_guard
         BEFORE UPDATE OR DELETE ON source_versions
         FOR EACH ROW EXECUTE FUNCTION reject_frozen_source_version_mutation()',
        []
    );
    queryParams($conn, 'DROP TRIGGER IF EXISTS strings_frozen_guard ON strings', []);
    queryParams(
        $conn,
        'CREATE TRIGGER strings_frozen_guard
         BEFORE INSERT OR UPDATE OR DELETE ON strings
         FOR EACH ROW EXECUTE FUNCTION reject_frozen_source_version_mutation()',
        []
    );
    queryParams($conn, 'DROP TRIGGER IF EXISTS translations_frozen_guard ON translations', []);
    queryParams(
        $conn,
        'CREATE TRIGGER translations_frozen_guard
         BEFORE INSERT OR UPDATE OR DELETE ON translations
         FOR EACH ROW EXECUTE FUNCTION reject_frozen_source_version_mutation()',
        []
    );

    if (pg_query($conn, 'COMMIT') === false) {
        throw new RuntimeException('MIGRATION_COMMIT_FAILED');
    }
    echo "TMS core migration completed.\n";
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PgSql\Connection &&
        pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
        pg_query($conn, 'ROLLBACK');
    }
    fwrite(STDERR, "TMS core migration failed: {$e->getMessage()}\n");
    exit(1);
}
