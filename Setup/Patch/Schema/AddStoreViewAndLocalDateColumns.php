<?php

namespace LeanCommerce\ProductAudit\Setup\Patch\Schema;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class AddStoreViewAndLocalDateColumns implements SchemaPatchInterface
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
        $connection = $setup->getConnection();

        if ($setup->tableExists($tableName)) {
            if (!$connection->tableColumnExists($tableName, 'store_id')) {
                $connection->addColumn(
                    $tableName,
                    'store_id',
                    [
                        'type' => Table::TYPE_INTEGER,
                        'nullable' => true,
                        'unsigned' => true,
                        'comment' => 'Store View ID'
                    ]
                );
            }

            if (!$connection->tableColumnExists($tableName, 'store_code')) {
                $connection->addColumn(
                    $tableName,
                    'store_code',
                    [
                        'type' => Table::TYPE_TEXT,
                        'length' => 64,
                        'nullable' => true,
                        'comment' => 'Store View Code'
                    ]
                );
            }

            if ($connection->tableColumnExists($tableName, 'created_at')) {
                $connection->modifyColumn(
                    $tableName,
                    'created_at',
                    [
                        'type' => Table::TYPE_DATETIME,
                        'nullable' => false,
                        'comment' => 'Created At Local Time'
                    ]
                );
            }

            $indexes = $connection->getIndexList($tableName);
            $storeIdIndex = $setup->getIdxName($tableName, ['store_id']);
            if (!isset($indexes[$storeIdIndex])) {
                $connection->addIndex(
                    $tableName,
                    $storeIdIndex,
                    ['store_id'],
                    AdapterInterface::INDEX_TYPE_INDEX
                );
            }

            $storeCodeIndex = $setup->getIdxName($tableName, ['store_code']);
            if (!isset($indexes[$storeCodeIndex])) {
                $connection->addIndex(
                    $tableName,
                    $storeCodeIndex,
                    ['store_code'],
                    AdapterInterface::INDEX_TYPE_INDEX
                );
            }
        }

        $setup->endSetup();
    }

    public static function getDependencies()
    {
        return [
            \LeanCommerce\ProductAudit\Setup\Patch\Schema\AddAuditOriginColumns::class
        ];
    }

    public function getAliases()
    {
        return [];
    }
}
