<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

/**
 * Migration: Cấu hình lại ID tuần tự cho các bảng chính
 * User, project, and translation IDs are remapped from 10000001 while
 * dependent foreign keys are updated through ON UPDATE CASCADE.
 */

try {
    $conn = db();
    
    if (pg_query($conn, 'BEGIN') === false) {
        throw new RuntimeException('MIGRATION_TRANSACTION_FAILED');
    }
    
    $quoteIdentifier = static fn(string $identifier): string => pg_escape_identifier($conn, $identifier);

    $enableCascadeForParent = function (PgSql\Connection $conn, string $parentTable) use ($quoteIdentifier): void {
        $constraints = queryParams(
            $conn,
            'SELECT child.relname AS child_table,
                    con.conname,
                    pg_get_constraintdef(con.oid) AS definition
             FROM pg_constraint con
             JOIN pg_class child ON child.oid = con.conrelid
             JOIN pg_namespace child_ns ON child_ns.oid = child.relnamespace
             JOIN pg_class parent ON parent.oid = con.confrelid
             JOIN pg_namespace parent_ns ON parent_ns.oid = parent.relnamespace
             WHERE con.contype = \'f\'
               AND child_ns.nspname = \'public\'
               AND parent_ns.nspname = \'public\'
               AND parent.relname = $1',
            [$parentTable]
        );

        while ($constraint = pg_fetch_assoc($constraints)) {
            $definition = (string)$constraint['definition'];
            if (stripos($definition, 'ON UPDATE') !== false) {
                continue;
            }
            $definition .= ' ON UPDATE CASCADE';
            $childTable = $quoteIdentifier((string)$constraint['child_table']);
            $constraintName = $quoteIdentifier((string)$constraint['conname']);
            queryParams(
                $conn,
                "ALTER TABLE {$childTable}
                 DROP CONSTRAINT {$constraintName},
                 ADD CONSTRAINT {$constraintName} {$definition}",
                []
            );
        }
    };

    $remapParentIds = function (
        PgSql\Connection $conn,
        string $tableName,
        string $sequenceName
    ) use ($quoteIdentifier): void {
        $table = $quoteIdentifier($tableName);
        $mapTable = $quoteIdentifier($tableName . '_id_map');
        $column = $quoteIdentifier('id');
        $start = 10000001;

        queryParams(
            $conn,
            "CREATE TEMP TABLE {$mapTable} (
                old_id INTEGER PRIMARY KEY,
                new_id INTEGER NOT NULL UNIQUE
            ) ON COMMIT DROP",
            []
        );
        queryParams(
            $conn,
            "INSERT INTO {$mapTable} (old_id, new_id)
             SELECT id,
                    GREATEST(
                        $1,
                        COALESCE((SELECT MAX(id) + 1 FROM {$table} WHERE id >= $1), $1)
                    ) + ROW_NUMBER() OVER (ORDER BY id)::INTEGER - 1
             FROM {$table}
             WHERE id < $1",
            [$start]
        );

        queryParams(
            $conn,
            "UPDATE {$table}
             SET {$column} = -{$column}
             WHERE {$column} IN (SELECT old_id FROM {$mapTable})",
            []
        );
        queryParams(
            $conn,
            "UPDATE {$table} AS parent
             SET {$column} = map.new_id
             FROM {$mapTable} AS map
             WHERE parent.{$column} = -map.old_id",
            []
        );
        queryParams(
            $conn,
            "SELECT setval(
                $1,
                GREATEST(COALESCE((SELECT MAX({$column}) FROM {$table}), $2), $2),
                TRUE
            )",
            [$sequenceName, $start - 1]
        );
    };

    // Foreign keys are changed before the two-phase remap so every dependent
    // user_id/project_id is updated by PostgreSQL's ON UPDATE CASCADE.
    $enableCascadeForParent($conn, 'users');
    $enableCascadeForParent($conn, 'projects');
    $enableCascadeForParent($conn, 'translations');
    $enableCascadeForParent($conn, 'source_versions');
    foreach ([
        ['source_versions', 'source_versions_frozen_guard'],
        ['strings', 'strings_frozen_guard'],
        ['translations', 'translations_frozen_guard'],
        ['translations', 'translations_history_trigger'],
        ['release_snapshots', 'release_snapshots_immutable'],
    ] as [$tableName, $triggerName]) {
        queryParams(
            $conn,
            'ALTER TABLE ' . $quoteIdentifier($tableName) .
            ' DISABLE TRIGGER ' . $quoteIdentifier($triggerName),
            []
        );
    }
    $remapParentIds($conn, 'users', 'users_id_seq');
    $remapParentIds($conn, 'projects', 'projects_id_seq');
    $remapParentIds($conn, 'translations', 'translations_id_seq');
    $remapParentIds($conn, 'source_versions', 'source_versions_id_seq');
    foreach ([
        ['source_versions', 'source_versions_frozen_guard'],
        ['strings', 'strings_frozen_guard'],
        ['translations', 'translations_frozen_guard'],
        ['translations', 'translations_history_trigger'],
        ['release_snapshots', 'release_snapshots_immutable'],
    ] as [$tableName, $triggerName]) {
        queryParams(
            $conn,
            'ALTER TABLE ' . $quoteIdentifier($tableName) .
            ' ENABLE TRIGGER ' . $quoteIdentifier($triggerName),
            []
        );
    }

    $configureSequence = function (PgSql\Connection $conn, string $seqName, string $tableName, string $columnName): void {
        queryParams(
            $conn,
            "CREATE SEQUENCE IF NOT EXISTS {$seqName} START WITH 10000001",
            []
        );
        queryParams(
            $conn,
            "ALTER SEQUENCE {$seqName} OWNED BY {$tableName}.{$columnName}",
            []
        );
        queryParams(
            $conn,
            "ALTER TABLE {$tableName}
             ALTER COLUMN {$columnName} TYPE INTEGER,
             ALTER COLUMN {$columnName} SET DEFAULT nextval('{$seqName}')",
            []
        );
        queryParams($conn, "ALTER SEQUENCE {$seqName} START WITH 10000001", []);
        queryParams(
            $conn,
            "SELECT setval(
                '{$seqName}',
                GREATEST(COALESCE((SELECT MAX({$columnName}) FROM {$tableName}), 10000000), 10000000),
                TRUE
            )",
            []
        );
        echo "✓ Configured {$tableName}.{$columnName} sequence\n";
    };
    
    // users.id
    $configureSequence($conn, 'users_id_seq', 'users', 'id');
    
    // projects.id
    $configureSequence($conn, 'projects_id_seq', 'projects', 'id');
    
    // source_versions.id (gọi là "versions")
    $configureSequence($conn, 'source_versions_id_seq', 'source_versions', 'id');
    
    // translations.id
    $configureSequence($conn, 'translations_id_seq', 'translations', 'id');
    
    if (pg_query($conn, 'COMMIT') === false) {
        throw new RuntimeException('MIGRATION_COMMIT_FAILED');
    }
    
    echo "\nSequential IDs migration completed.\n";
    echo "User, project, version, and translation IDs now start from 10000001; dependent foreign keys were cascaded.\n";
    
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PgSql\Connection && 
        pg_transaction_status($conn) !== PGSQL_TRANSACTION_IDLE) {
        pg_query($conn, 'ROLLBACK');
    }
    fwrite(STDERR, "Sequential ID migration failed: " . $e->getMessage() . "\n");
    exit(1);
}