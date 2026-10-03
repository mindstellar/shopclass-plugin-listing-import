<?php

use mindstellar\database\Connection;
use mindstellar\migration\MigrationInterface;

return new class () implements MigrationInterface {
    public function up(Connection $conn): void
    {
        $p = DB_TABLE_PREFIX;

        // Keys now live in core's API key table; a source lists the key ids that import into it.
        $has = (int)$conn->scalar(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            array($p . 't_listing_import_source', 's_key_ids')
        );
        if ($has === 0) {
            $conn->execute(
                'ALTER TABLE ' . $p . "t_listing_import_source ADD COLUMN s_key_ids VARCHAR(255) NOT NULL DEFAULT '' AFTER e_kind"
            );
        }

        $conn->execute('DROP TABLE IF EXISTS ' . $p . 't_listing_import_key');
    }
};
