<?php

namespace LeanCommerce\ProductAudit\Setup\Patch\Schema;

/**
 * Recreates database audit triggers using the latest audit row instead of
 * a created_at time window. This prevents Admin/API/import writes from being
 * duplicated as database writes even when PHP and MariaDB use different
 * timezones.
 */
class UseLatestAuditRowForDatabaseTriggerDeduplication extends AddDatabaseAuditTriggers
{
    public static function getDependencies()
    {
        return [UseApplicationSessionMarkerForDatabaseAudit::class];
    }

    public function getAliases()
    {
        return [];
    }
}
