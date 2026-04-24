<?php

namespace LeanCommerce\ProductAudit\ResourceModel;

use Magento\Framework\App\ResourceConnection;

class Logger
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    public function __construct(
        ResourceConnection $resourceConnection
    ) {
        $this->resourceConnection = $resourceConnection;
    }

    public function logChange(
        int $productId,
        string $sku,
        string $attributeCode,
        ?string $oldValue,
        ?string $newValue,
        ?string $adminUser,
        ?string $area
    ): void {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('leancommerce_product_change_log');

        $connection->insert($tableName, [
            'product_id'     => $productId,
            'sku'            => $sku,
            'attribute_code' => $attributeCode,
            'old_value'      => $oldValue,
            'new_value'      => $newValue,
            'admin_user'     => $adminUser,
            'area'           => $area,
            'created_at'     => date('Y-m-d H:i:s')
        ]);
    }
}