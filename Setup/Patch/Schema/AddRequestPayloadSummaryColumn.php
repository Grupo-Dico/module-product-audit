<?php

namespace LeanCommerce\ProductAudit\Setup\Patch\Schema;

use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\DB\Ddl\Table;

class AddRequestPayloadSummaryColumn implements SchemaPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    public function apply()
    {
        $setup = $this->moduleDataSetup;
        $setup->startSetup();

        $connection = $setup->getConnection();
        $tableName = $setup->getTable('leancommerce_product_change_log');

        if ($connection->isTableExists($tableName)
            && !$connection->tableColumnExists($tableName, 'request_payload_summary')) {
            $connection->addColumn(
                $tableName,
                'request_payload_summary',
                [
                    'type' => Table::TYPE_TEXT,
                    'length' => 2048,
                    'nullable' => true,
                    'comment' => 'Request Payload Summary'
                ]
            );
        }

        $setup->endSetup();
    }

    public static function getDependencies()
    {
        return [ModifyOriginDetailLength::class];
    }

    public function getAliases()
    {
        return [];
    }
}
