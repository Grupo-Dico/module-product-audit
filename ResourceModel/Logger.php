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
        ?string $area,
        ?string $originType = null,
        ?string $originDetail = null,
        ?int $storeId = null,
        ?string $storeCode = null,
        ?string $requestPayloadSummary = null
    ): void {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('leancommerce_product_change_log');

        $data = [
            'product_id'     => $productId,
            'sku'            => $sku,
            'attribute_code' => $attributeCode,
            'old_value'      => $oldValue,
            'new_value'      => $newValue,
            'admin_user'     => $adminUser,
            'area'           => $area,
            'created_at'     => date('Y-m-d H:i:s')
        ];

        if ($connection->tableColumnExists($tableName, 'origin_type')) {
            $data['origin_type'] = $originType;
        }

        if ($connection->tableColumnExists($tableName, 'origin_detail')) {
            $data['origin_detail'] = $originDetail;
        }

        if ($connection->tableColumnExists($tableName, 'store_id')) {
            $data['store_id'] = $storeId;
        }

        if ($connection->tableColumnExists($tableName, 'store_code')) {
            $data['store_code'] = $storeCode;
        }

        if ($connection->tableColumnExists($tableName, 'request_payload_summary')) {
            $data['request_payload_summary'] = $requestPayloadSummary;
        }

        $connection->insert($tableName, $data);
    }
}
