<?php

namespace LeanCommerce\ProductAudit\Setup\Patch\Schema;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class CreateProductImportSnapshotTable implements SchemaPatchInterface
{
    /**
     * @var SchemaSetupInterface
     */
    private $schemaSetup;

    public function __construct(
        SchemaSetupInterface $schemaSetup
    ) {
        $this->schemaSetup = $schemaSetup;
    }

    public function apply()
    {
        $setup = $this->schemaSetup;
        $setup->startSetup();

        $tableName = $setup->getTable('leancommerce_product_import_snapshot');

        if (!$setup->tableExists($tableName)) {
            $connection = $setup->getConnection();

            $table = $connection->newTable($tableName)
                ->addColumn(
                    'snapshot_id',
                    Table::TYPE_INTEGER,
                    null,
                    ['identity' => true, 'unsigned' => true, 'nullable' => false, 'primary' => true],
                    'Snapshot ID'
                )
                ->addColumn(
                    'product_id',
                    Table::TYPE_INTEGER,
                    null,
                    ['nullable' => false, 'unsigned' => true],
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
                    'status',
                    Table::TYPE_INTEGER,
                    null,
                    [],
                    'Status'
                )
                ->addColumn(
                    'price',
                    Table::TYPE_DECIMAL,
                    '12,4',
                    [],
                    'Price'
                )
                ->addColumn(
                    'special_price',
                    Table::TYPE_DECIMAL,
                    '12,4',
                    [],
                    'Special Price'
                )
                ->addColumn(
                    'al_pagar_label',
                    Table::TYPE_TEXT,
                    255,
                    [],
                    'Al Pagar Label'
                )
                ->addColumn(
                    'al_pagar_precio',
                    Table::TYPE_TEXT,
                    255,
                    [],
                    'Al Pagar Precio'
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

