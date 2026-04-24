<?php

namespace LeanCommerce\ProductAudit\Setup\Patch\Schema;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class FixAuditLogForeignKey implements SchemaPatchInterface
{
    /**
     * @var SchemaSetupInterface
     */
    private $schemaSetup;

    public function __construct(SchemaSetupInterface $schemaSetup)
    {
        $this->schemaSetup = $schemaSetup;
    }

    public function apply()
    {
        $setup = $this->schemaSetup;
        $setup->startSetup();

        $tableName = $setup->getTable('leancommerce_product_change_log');
        $referenceTable = $setup->getTable('catalog_product_entity');
        $connection = $setup->getConnection();

        if ($setup->tableExists($tableName)) {
            $foreignKeys = $connection->getForeignKeys($tableName);

            foreach ($foreignKeys as $foreignKey) {
                $columnName = $foreignKey['COLUMN_NAME'] ?? $foreignKey['column_name'] ?? null;
                if ($columnName !== 'product_id') {
                    continue;
                }

                $fkName = $foreignKey['FK_NAME'] ?? $foreignKey['fk_name'] ?? null;
                if ($fkName) {
                    $connection->dropForeignKey($tableName, $fkName);
                }
            }

            $connection->addForeignKey(
                $setup->getFkName($tableName, 'product_id', 'catalog_product_entity', 'entity_id'),
                $tableName,
                'product_id',
                $referenceTable,
                'entity_id',
                Table::ACTION_NO_ACTION
            );
        }

        $setup->endSetup();
    }

    public static function getDependencies()
    {
        return [
            \LeanCommerce\ProductAudit\Setup\Patch\Schema\CreateProductChangeLogTable::class
        ];
    }

    public function getAliases()
    {
        return [];
    }
}