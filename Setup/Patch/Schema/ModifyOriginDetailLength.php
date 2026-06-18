<?php

namespace LeanCommerce\ProductAudit\Setup\Patch\Schema;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class ModifyOriginDetailLength implements SchemaPatchInterface
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

        if ($setup->tableExists($tableName) && $connection->tableColumnExists($tableName, 'origin_detail')) {
            $connection->modifyColumn(
                $tableName,
                'origin_detail',
                [
                    'type' => Table::TYPE_TEXT,
                    'length' => 2048,
                    'nullable' => true,
                    'comment' => 'Origin Detail'
                ]
            );
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
