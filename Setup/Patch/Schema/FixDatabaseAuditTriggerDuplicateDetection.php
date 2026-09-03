<?php

namespace LeanCommerce\ProductAudit\Setup\Patch\Schema;

/**
 * Recreates ProductAudit DB triggers with normalized numeric duplicate detection.
 *
 * AddDatabaseAuditTriggers may already be present in patch_list on existing
 * installations, so changing that patch alone would not execute again.
 */
class FixDatabaseAuditTriggerDuplicateDetection extends AddDatabaseAuditTriggers
{
    public static function getDependencies()
    {
        return [AddDatabaseAuditTriggers::class];
    }

    public function getAliases()
    {
        return [];
    }
}
