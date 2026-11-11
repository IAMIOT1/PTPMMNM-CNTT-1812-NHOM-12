<?php
declare(strict_types=1);

require_once __DIR__ . '/src/Database.php';

$conn = db();
queryParams($conn, 'ALTER TABLE users ADD COLUMN IF NOT EXISTS verification_code VARCHAR(6)', []);
queryParams($conn, 'ALTER TABLE users ADD COLUMN IF NOT EXISTS is_verified BOOLEAN NOT NULL DEFAULT TRUE', []);
echo "Email verification migration completed. New registrations should set is_verified=false.\n";
