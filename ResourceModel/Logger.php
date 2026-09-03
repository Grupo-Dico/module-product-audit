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

        // Mark this SQL connection as an application/Magento write.
        // Product table triggers use this session variable to distinguish
        // Magento Admin/API/import writes from SQL executed directly in a
        // different MySQL/MariaDB session.
        if ($originType !== 'database') {
            $connection->query('SET @leancommerce_product_audit_application = 1');
        }

        $data = [
            'product_id'     => $productId,
            'sku'            => $sku,
            'attribute_code' => $attributeCode,
            'old_value'      => $oldValue,
            'new_value'      => $newValue,
            'admin_user'     => $adminUser,
            'area'           => $area,
            'created_at'     => (new \DateTimeImmutable('now', new \DateTimeZone('America/Mexico_City')))->format('Y-m-d H:i:s')
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

        // A direct DB trigger may have logged the row first (notably CSV imports,
        // whose observer runs after Magento writes the EAV value). In that case,
        // promote that row to the richer Magento origin instead of creating a duplicate.
        if ($originType !== 'database' && $connection->tableColumnExists($tableName, 'origin_type')) {
            $select = $connection->select()
                ->from($tableName, ['log_id'])
                ->where('product_id = ?', $productId)
                ->where('attribute_code = ?', $attributeCode);

            $numericAttributes = [
                'price',
                'special_price',
                'al_pagar_precio',
                'status',
                'stock.qty',
                'stock.is_in_stock'
            ];

            if (in_array($attributeCode, $numericAttributes, true)) {
                $select->where(
                    'CAST(old_value AS DECIMAL(65,10)) <=> CAST(? AS DECIMAL(65,10))',
                    $oldValue
                )->where(
                    'CAST(new_value AS DECIMAL(65,10)) <=> CAST(? AS DECIMAL(65,10))',
                    $newValue
                );
            } else {
                $select->where('old_value <=> ?', $oldValue)
                    ->where('new_value <=> ?', $newValue);
            }

            $select->where('origin_type = ?', 'database')
                ->where("created_at >= DATE_SUB(CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '-06:00'), INTERVAL 5 MINUTE)")
                ->order('log_id DESC')
                ->limit(1);

            if ($storeId === null) {
                $select->where('store_id IS NULL');
            } else {
                $select->where('store_id = ?', $storeId);
            }

            $databaseLogId = $connection->fetchOne($select);

            if ($databaseLogId) {
                $connection->update(
                    $tableName,
                    $data,
                    ['log_id = ?' => (int)$databaseLogId]
                );
                return;
            }
        }

        $connection->insert($tableName, $data);
    }
}
