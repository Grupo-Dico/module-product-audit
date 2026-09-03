<?php

namespace LeanCommerce\ProductAudit\Setup\Patch\Schema;

/**
 * Recreates DB audit triggers so Magento-origin writes are not also recorded
 * as direct database changes.
 *
 * Magento's audit logger sets the connection-local variable
 * @leancommerce_product_audit_application = 1 before Magento writes a watched
 * product value. Direct SQL executed from another MySQL/MariaDB connection
 * does not have that variable and is therefore still audited as "database".
 */
class UseApplicationSessionMarkerForDatabaseAudit extends AddDatabaseAuditTriggers
{
    public static function getDependencies()
    {
        return [FixDatabaseAuditTriggerDuplicateDetection::class];
    }

    public function getAliases()
    {
        return [];
    }
}
