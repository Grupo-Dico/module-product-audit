<?php

namespace LeanCommerce\ProductAudit\Setup\Patch\Schema;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class AddAuditOriginColumns implements SchemaPatchInterface
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
            if (!$connection->tableColumnExists($tableName, 'origin_type')) {
                $connection->addColumn(
                    $tableName,
                    'origin_type',
                    [
                        'type' => Table::TYPE_TEXT,
                        'length' => 64,
                        'nullable' => true,
                        'comment' => 'Origin Type'
                    ]
                );
            }

            if (!$connection->tableColumnExists($tableName, 'origin_detail')) {
                $connection->addColumn(
                    $tableName,
                    'origin_detail',
                    [
                        'type' => Table::TYPE_TEXT,
                        'length' => 255,
                        'nullable' => true,
                        'comment' => 'Origin Detail'
                    ]
                );
            }
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