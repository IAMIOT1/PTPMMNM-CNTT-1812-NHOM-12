<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

$connection = null;
try {
    $connection = db();
    if (pg_query($connection, 'BEGIN') === false) {
        throw new RuntimeException('MIGRATION_TRANSACTION_FAILED');
    }

    queryParams(
        $connection,
        'CREATE TABLE IF NOT EXISTS translation_history (
            id BIGSERIAL PRIMARY KEY,
            translation_id INT NOT NULL REFERENCES translations(id) ON DELETE CASCADE,
            old_text TEXT,
            new_text TEXT,
            changed_by_user_id INT REFERENCES users(id) ON DELETE SET NULL,
            created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        []
    );
    queryParams(
        $connection,
        'CREATE INDEX IF NOT EXISTS translation_history_translation_idx
         ON translation_history (translation_id, created_at DESC, id DESC)',
        []
    );
    queryParams(
        $connection,
        'CREATE TABLE IF NOT EXISTS notifications (
            id BIGSERIAL PRIMARY KEY,
            user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            message TEXT NOT NULL,
            is_read BOOLEAN NOT NULL DEFAULT FALSE,
            created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
        )',
        []
    );
    queryParams(
        $connection,
        'CREATE INDEX IF NOT EXISTS notifications_user_unread_idx
         ON notifications (user_id, is_read, created_at DESC)',
        []
    );
    $historyFunctionSql = <<<'SQL'
CREATE OR REPLACE FUNCTION record_translation_text_history()
RETURNS trigger AS $$
DECLARE
    actor_id INT;
BEGIN
    actor_id := NULLIF(current_setting('tms.user_id', TRUE), '')::INT;
    IF TG_OP = 'INSERT' THEN
        INSERT INTO translation_history
            (translation_id, old_text, new_text, changed_by_user_id)
        VALUES (NEW.id, NULL, NEW.translated_text, actor_id);
    ELSIF OLD.translated_text IS DISTINCT FROM NEW.translated_text THEN
        INSERT INTO translation_history
            (translation_id, old_text, new_text, changed_by_user_id)
        VALUES (NEW.id, OLD.translated_text, NEW.translated_text, actor_id);
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql
SQL;
    queryParams($connection, $historyFunctionSql, []);
    queryParams($connection, 'DROP TRIGGER IF EXISTS translations_history_trigger ON translations', []);
    queryParams(
        $connection,
        'CREATE TRIGGER translations_history_trigger
         AFTER INSERT OR UPDATE OF translated_text ON translations
         FOR EACH ROW EXECUTE FUNCTION record_translation_text_history()',
        []
    );

    if (pg_query($connection, 'COMMIT') === false) {
        throw new RuntimeException('MIGRATION_COMMIT_FAILED');
    }
    echo "Translation history and notifications migration completed.\n";
} catch (Throwable $e) {
    if ($connection instanceof PgSql\Connection &&
        pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE) {
        pg_query($connection, 'ROLLBACK');
    }
    fwrite(STDERR, "Translation features migration failed: {$e->getMessage()}\n");
    exit(1);
}
