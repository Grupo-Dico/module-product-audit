<?php

namespace LeanCommerce\ProductAudit\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;

class Logger
{
    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var TimezoneInterface
     */
    private $timezone;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(
        ResourceConnection $resourceConnection,
        TimezoneInterface $timezone,
        StoreManagerInterface $storeManager
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->timezone = $timezone;
        $this->storeManager = $storeManager;
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
        ?string $storeCode = null
    ): void {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('leancommerce_product_change_log');

        $storeData = $this->resolveStoreData($storeId, $storeCode);

        $data = [
            'product_id'     => $productId,
            'sku'            => $sku,
            'attribute_code' => $attributeCode,
            'old_value'      => $oldValue,
            'new_value'      => $newValue,
            'admin_user'     => $adminUser,
            'area'           => $area,
            // Fecha/hora local configurada en Magento: Stores > Configuration > General > Locale Options > Timezone
            'created_at'     => $this->timezone->date()->format('Y-m-d H:i:s')
        ];

        if ($connection->tableColumnExists($tableName, 'origin_type')) {
            $data['origin_type'] = $originType;
        }

        if ($connection->tableColumnExists($tableName, 'origin_detail')) {
            $data['origin_detail'] = $originDetail;
        }

        if ($connection->tableColumnExists($tableName, 'store_id')) {
            $data['store_id'] = $storeData['store_id'];
        }

        if ($connection->tableColumnExists($tableName, 'store_code')) {
            $data['store_code'] = $storeData['store_code'];
        }

        $connection->insert($tableName, $data);
    }

    private function resolveStoreData(?int $storeId = null, ?string $storeCode = null): array
    {
        $resolvedStoreId = $storeId;
        $resolvedStoreCode = $storeCode;

        try {
            if ($resolvedStoreCode !== null && $resolvedStoreCode !== '') {
                $store = $this->storeManager->getStore($resolvedStoreCode);
                return [
                    'store_id' => (int)$store->getId(),
                    'store_code' => (string)$store->getCode()
                ];
            }

            if ($resolvedStoreId !== null && $resolvedStoreId >= 0) {
                $store = $this->storeManager->getStore($resolvedStoreId);
                return [
                    'store_id' => (int)$store->getId(),
                    'store_code' => (string)$store->getCode()
                ];
            }

            $store = $this->storeManager->getStore();
            return [
                'store_id' => (int)$store->getId(),
                'store_code' => (string)$store->getCode()
            ];
        } catch (\Throwable $e) {
            return [
                'store_id' => $resolvedStoreId,
                'store_code' => $resolvedStoreCode
            ];
        }
    }
}
