<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';

function getDbConnection(): PgSql\Connection
{
    return db();
}
