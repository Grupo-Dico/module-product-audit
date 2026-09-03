<?php

namespace LeanCommerce\ProductAudit\Setup\Patch\Schema;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

/**
 * Adds DB-level audit triggers so direct SQL changes that bypass Magento's
 * service/resource layers are still written to the Product Audit log.
 *
 * The triggers only watch the same product fields audited by the PHP layer.
 */
class AddDatabaseAuditTriggers implements SchemaPatchInterface, PatchRevertableInterface
{
    /** @var SchemaSetupInterface */
    private $schemaSetup;

    /** @var ResourceConnection */
    private $resourceConnection;

    /**
     * @var string[]
     */
    private $eavBackendTables = [
        'catalog_product_entity_int',
        'catalog_product_entity_decimal',
        'catalog_product_entity_varchar',
        'catalog_product_entity_text',
        'catalog_product_entity_datetime',
    ];

    public function __construct(
        SchemaSetupInterface $schemaSetup,
        ResourceConnection $resourceConnection
    ) {
        $this->schemaSetup = $schemaSetup;
        $this->resourceConnection = $resourceConnection;
    }

    public function apply()
    {
        $this->schemaSetup->startSetup();

        try {
            foreach ($this->eavBackendTables as $table) {
                $this->createEavTrigger($table, 'INSERT');
                $this->createEavTrigger($table, 'UPDATE');
            }

            $this->createStockUpdateTrigger();
        } finally {
            $this->schemaSetup->endSetup();
        }

        return $this;
    }

    public function revert()
    {
        $this->schemaSetup->startSetup();

        try {
            foreach ($this->eavBackendTables as $table) {
                $this->dropTrigger($this->getTriggerName($table, 'INSERT'));
                $this->dropTrigger($this->getTriggerName($table, 'UPDATE'));
            }

            $this->dropTrigger($this->getTriggerName('cataloginventory_stock_item', 'UPDATE'));
        } finally {
            $this->schemaSetup->endSetup();
        }
    }

    private function createEavTrigger(string $baseTable, string $event): void
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName($baseTable);

        if (!$connection->isTableExists($table)) {
            return;
        }

        $auditTable = $this->resourceConnection->getTableName('leancommerce_product_change_log');
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $attributeTable = $this->resourceConnection->getTableName('eav_attribute');
        $entityTypeTable = $this->resourceConnection->getTableName('eav_entity_type');
        $storeTable = $this->resourceConnection->getTableName('store');
        $triggerName = $this->getTriggerName($baseTable, $event);

        $this->dropTrigger($triggerName);

        $isInsert = $event === 'INSERT';
        $oldExpression = $isInsert ? 'NULL' : 'CAST(OLD.value AS CHAR)';
        $changeCondition = $isInsert ? 'TRUE' : 'NOT (OLD.value <=> NEW.value)';
        $operation = strtolower($event);
        $isInsertSql = $isInsert ? 1 : 0;
        $isNumericBackend = in_array($baseTable, [
            'catalog_product_entity_int',
            'catalog_product_entity_decimal'
        ], true);

        // Magento normalizes numeric EAV values before logging them in PHP.
        // Example: Admin may log 30800.0000 while MariaDB stores 30800.000000.
        // Compare numeric backends numerically so the DB trigger recognizes the
        // Admin/API audit row and does not create a second "database" row.
        $oldMatchCondition = $isNumericBackend
            ? '(CAST(old_value AS DECIMAL(65,10)) <=> CAST(v_old_value AS DECIMAL(65,10)))'
            : '(old_value <=> v_old_value)';
        $newMatchCondition = $isNumericBackend
            ? '(CAST(new_value AS DECIMAL(65,10)) <=> CAST(v_new_value AS DECIMAL(65,10)))'
            : '(new_value <=> v_new_value)';

        $sql = <<<SQL
CREATE TRIGGER `{$triggerName}` AFTER {$event} ON `{$table}`
FOR EACH ROW
BEGIN
    DECLARE v_attribute_code VARCHAR(64) DEFAULT NULL;
    DECLARE v_sku VARCHAR(64) DEFAULT NULL;
    DECLARE v_store_code VARCHAR(64) DEFAULT NULL;
    DECLARE v_old_value TEXT DEFAULT NULL;
    DECLARE v_new_value TEXT DEFAULT NULL;
    DECLARE v_existing INT DEFAULT 0;
    DECLARE v_product_created_at DATETIME DEFAULT NULL;

    IF COALESCE(@leancommerce_product_audit_application, 0) = 0
       AND {$changeCondition} THEN
        SELECT ea.attribute_code
          INTO v_attribute_code
          FROM `{$attributeTable}` ea
          INNER JOIN `{$entityTypeTable}` eet ON eet.entity_type_id = ea.entity_type_id
         WHERE ea.attribute_id = NEW.attribute_id
           AND eet.entity_type_code = 'catalog_product'
           AND ea.attribute_code IN ('price','status','special_price','al_pagar_label','al_pagar_precio')
         LIMIT 1;

        IF v_attribute_code IS NOT NULL THEN
            SELECT sku, created_at INTO v_sku, v_product_created_at
              FROM `{$productTable}`
             WHERE entity_id = NEW.entity_id
             LIMIT 1;

            SELECT code INTO v_store_code
              FROM `{$storeTable}`
             WHERE store_id = NEW.store_id
             LIMIT 1;

            SET v_old_value = {$oldExpression};
            SET v_new_value = CAST(NEW.value AS CHAR);

            /*
             * Magento's PHP audit row is written immediately before the EAV UPDATE.
             * Check only the latest audit row for this product/attribute/store.
             * This avoids timezone-dependent created_at comparisons and prevents
             * the DB trigger from duplicating Admin/API/import changes.
             */
            SELECT COUNT(*) INTO v_existing
              FROM `{$auditTable}` l
             WHERE l.log_id = (
                    SELECT MAX(l2.log_id)
                      FROM `{$auditTable}` l2
                     WHERE l2.product_id = NEW.entity_id
                       AND l2.attribute_code = v_attribute_code
                       AND (l2.store_id <=> NEW.store_id)
               )
               AND {$oldMatchCondition}
               AND {$newMatchCondition}
               AND COALESCE(l.origin_type, '') <> 'database';

            IF v_existing = 0
               AND ({$isInsertSql} = 0 OR v_product_created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)) THEN
                INSERT INTO `{$auditTable}`
                    (product_id, sku, attribute_code, old_value, new_value,
                     admin_user, area, origin_type, origin_detail,
                     request_payload_summary, store_id, store_code, created_at)
                VALUES
                    (NEW.entity_id, COALESCE(v_sku, ''), v_attribute_code,
                     v_old_value, v_new_value,
                     'database', 'database', 'database',
                     CONCAT('direct_db | table:{$baseTable} | operation:{$operation}'),
                     CONCAT('changed: ', v_attribute_code, ':', COALESCE(v_old_value, 'NULL'), '=>', COALESCE(v_new_value, 'NULL')),
                     NEW.store_id, v_store_code, CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-06:00'));
            END IF;
        END IF;
    END IF;
END
SQL;

        // Magento's DB adapter rejects CREATE TRIGGER bodies because the
        // internal semicolons are detected as multiple SQL statements.
        // Execute this single CREATE TRIGGER statement directly through PDO.
        $pdo = $connection->getConnection();
        $pdo->exec($sql);
    }

    private function createStockUpdateTrigger(): void
    {
        $connection = $this->resourceConnection->getConnection();
        $baseTable = 'cataloginventory_stock_item';
        $table = $this->resourceConnection->getTableName($baseTable);

        if (!$connection->isTableExists($table)) {
            return;
        }

        $auditTable = $this->resourceConnection->getTableName('leancommerce_product_change_log');
        $productTable = $this->resourceConnection->getTableName('catalog_product_entity');
        $triggerName = $this->getTriggerName($baseTable, 'UPDATE');

        $this->dropTrigger($triggerName);

        $sql = <<<SQL
CREATE TRIGGER `{$triggerName}` AFTER UPDATE ON `{$table}`
FOR EACH ROW
BEGIN
    DECLARE v_sku VARCHAR(64) DEFAULT NULL;
    DECLARE v_existing INT DEFAULT 0;
    DECLARE v_old_value TEXT DEFAULT NULL;
    DECLARE v_new_value TEXT DEFAULT NULL;

    IF COALESCE(@leancommerce_product_audit_application, 0) = 0
       AND (NOT (OLD.qty <=> NEW.qty) OR NOT (OLD.is_in_stock <=> NEW.is_in_stock)) THEN
        SELECT sku INTO v_sku
          FROM `{$productTable}`
         WHERE entity_id = NEW.product_id
         LIMIT 1;
    END IF;

    IF COALESCE(@leancommerce_product_audit_application, 0) = 0
       AND NOT (OLD.qty <=> NEW.qty) THEN
        SET v_old_value = CAST(OLD.qty AS CHAR);
        SET v_new_value = CAST(NEW.qty AS CHAR);

        SELECT COUNT(*) INTO v_existing
          FROM `{$auditTable}` l
         WHERE l.log_id = (
                SELECT MAX(l2.log_id)
                  FROM `{$auditTable}` l2
                 WHERE l2.product_id = NEW.product_id
                   AND l2.attribute_code = 'stock.qty'
                   AND (l2.store_id <=> 0)
           )
           AND (CAST(l.old_value AS DECIMAL(65,10)) <=> CAST(v_old_value AS DECIMAL(65,10)))
           AND (CAST(l.new_value AS DECIMAL(65,10)) <=> CAST(v_new_value AS DECIMAL(65,10)))
           AND COALESCE(l.origin_type, '') <> 'database';

        IF v_existing = 0 THEN
            INSERT INTO `{$auditTable}`
                (product_id, sku, attribute_code, old_value, new_value,
                 admin_user, area, origin_type, origin_detail,
                 request_payload_summary, store_id, store_code, created_at)
            VALUES
                (NEW.product_id, COALESCE(v_sku, ''), 'stock.qty',
                 v_old_value, v_new_value,
                 'database', 'database', 'database',
                 'direct_db | table:cataloginventory_stock_item | operation:update',
                 CONCAT('changed: stock.qty:', COALESCE(v_old_value, 'NULL'), '=>', COALESCE(v_new_value, 'NULL')),
                 0, 'admin', CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-06:00'));
        END IF;
    END IF;

    IF COALESCE(@leancommerce_product_audit_application, 0) = 0
       AND NOT (OLD.is_in_stock <=> NEW.is_in_stock) THEN
        SET v_old_value = CAST(OLD.is_in_stock AS CHAR);
        SET v_new_value = CAST(NEW.is_in_stock AS CHAR);

        SELECT COUNT(*) INTO v_existing
          FROM `{$auditTable}` l
         WHERE l.log_id = (
                SELECT MAX(l2.log_id)
                  FROM `{$auditTable}` l2
                 WHERE l2.product_id = NEW.product_id
                   AND l2.attribute_code = 'stock.is_in_stock'
                   AND (l2.store_id <=> 0)
           )
           AND (l.old_value <=> v_old_value)
           AND (l.new_value <=> v_new_value)
           AND COALESCE(l.origin_type, '') <> 'database';

        IF v_existing = 0 THEN
            INSERT INTO `{$auditTable}`
                (product_id, sku, attribute_code, old_value, new_value,
                 admin_user, area, origin_type, origin_detail,
                 request_payload_summary, store_id, store_code, created_at)
            VALUES
                (NEW.product_id, COALESCE(v_sku, ''), 'stock.is_in_stock',
                 v_old_value, v_new_value,
                 'database', 'database', 'database',
                 'direct_db | table:cataloginventory_stock_item | operation:update',
                 CONCAT('changed: stock.is_in_stock:', COALESCE(v_old_value, 'NULL'), '=>', COALESCE(v_new_value, 'NULL')),
                 0, 'admin', CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-06:00'));
        END IF;
    END IF;
END
SQL;

        // Magento's DB adapter rejects CREATE TRIGGER bodies because the
        // internal semicolons are detected as multiple SQL statements.
        // Execute this single CREATE TRIGGER statement directly through PDO.
        $pdo = $connection->getConnection();
        $pdo->exec($sql);
    }

    private function getTriggerName(string $baseTable, string $event): string
    {
        $prefix = substr(md5($this->resourceConnection->getTableName($baseTable)), 0, 8);
        $eventCode = strtolower(substr($event, 0, 1));

        return 'lc_pa_' . $prefix . '_' . $eventCode;
    }

    private function dropTrigger(string $triggerName): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->query('DROP TRIGGER IF EXISTS `' . $triggerName . '`');
    }

    public static function getDependencies()
    {
        return [CreateProductChangeLogTable::class];
    }

    public function getAliases()
    {
        return [];
    }
}
