<?php

namespace LeanCommerce\ProductAudit\Setup\Patch\Schema;

/**
 * Recreates direct-database audit triggers so audit created_at is stored
 * directly in America/Mexico_City local time instead of UTC.
 *
 * Mexico City is UTC-06:00 year-round under the current timezone rules.
 */
class StoreMexicoCityLocalAuditTimestamps extends AddDatabaseAuditTriggers
{
    public static function getDependencies()
    {
        return [UseLatestAuditRowForDatabaseTriggerDeduplication::class];
    }

    public function getAliases()
    {
        return [];
    }
}
