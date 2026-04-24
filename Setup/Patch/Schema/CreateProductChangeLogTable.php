<?php

namespace LeanCommerce\ProductAudit\Setup\Patch\Schema;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class CreateProductChangeLogTable implements SchemaPatchInterface
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

        if (!$connection->isTableExists($tableName)) {
            $table = $connection->newTable($tableName)
                ->addColumn(
                    'log_id',
                    Table::TYPE_INTEGER,
                    null,
                    ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true],
                    'Log ID'
                )
                ->addColumn(
                    'product_id',
                    Table::TYPE_INTEGER,
                    null,
                    ['unsigned' => true, 'nullable' => false],
                    'Product ID'
                )
                ->addColumn(
                    'sku',
                    Table::TYPE_TEXT,
                    64,
                    ['nullable' => false],
                    'SKU'
                )
                ->addColumn(
                    'attribute_code',
                    Table::TYPE_TEXT,
                    64,
                    ['nullable' => false],
                    'Attribute Code'
                )
                ->addColumn(
                    'old_value',
                    Table::TYPE_TEXT,
                    65536,
                    ['nullable' => true],
                    'Old Value'
                )
                ->addColumn(
                    'new_value',
                    Table::TYPE_TEXT,
                    65536,
                    ['nullable' => true],
                    'New Value'
                )
                ->addColumn(
                    'admin_user',
                    Table::TYPE_TEXT,
                    64,
                    ['nullable' => true],
                    'Admin User'
                )
                ->addColumn(
                    'area',
                    Table::TYPE_TEXT,
                    32,
                    ['nullable' => true],
                    'Area'
                )
                ->addColumn(
                    'origin_type',
                    Table::TYPE_TEXT,
                    64,
                    ['nullable' => true],
                    'Origin Type'
                )
                ->addColumn(
                    'origin_detail',
                    Table::TYPE_TEXT,
                    255,
                    ['nullable' => true],
                    'Origin Detail'
                )
                ->addColumn(
                    'created_at',
                    Table::TYPE_TIMESTAMP,
                    null,
                    ['nullable' => false, 'default' => Table::TIMESTAMP_INIT],
                    'Created At'
                )
                ->addIndex(
                    $setup->getIdxName($tableName, ['product_id']),
                    ['product_id'],
                    ['type' => AdapterInterface::INDEX_TYPE_INDEX]
                )
                ->addIndex(
                    $setup->getIdxName($tableName, ['sku']),
                    ['sku'],
                    ['type' => AdapterInterface::INDEX_TYPE_INDEX]
                )
                ->addIndex(
                    $setup->getIdxName($tableName, ['created_at']),
                    ['created_at'],
                    ['type' => AdapterInterface::INDEX_TYPE_INDEX]
                )
                ->addIndex(
                    $setup->getIdxName($tableName, ['attribute_code']),
                    ['attribute_code'],
                    ['type' => AdapterInterface::INDEX_TYPE_INDEX]
                )
                ->addIndex(
                    $setup->getIdxName($tableName, ['origin_type']),
                    ['origin_type'],
                    ['type' => AdapterInterface::INDEX_TYPE_INDEX]
                )
                ->addForeignKey(
                    $setup->getFkName(
                        $tableName,
                        'product_id',
                        'catalog_product_entity',
                        'entity_id'
                    ),
                    'product_id',
                    $setup->getTable('catalog_product_entity'),
                    'entity_id',
                    Table::ACTION_NO_ACTION
                );

            $connection->createTable($table);
        }

        $setup->endSetup();
    }

    public static function getDependencies()
    {
        return [];
    }

    public function getAliases()
    {
        return [];
    }
}