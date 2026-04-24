<?php

namespace LeanCommerce\ProductAudit\Model;

use Magento\Framework\App\ResourceConnection;

class ImportSnapshot
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

    public function saveSnapshot(array $data): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('leancommerce_product_import_snapshot');

        if (!empty($data['product_id'])) {
            $connection->delete(
                $tableName,
                ['product_id = ?' => (int)$data['product_id']]
            );
        } elseif (!empty($data['sku'])) {
            $connection->delete(
                $tableName,
                ['sku = ?' => (string)$data['sku']]
            );
        }

        $connection->insert($tableName, [
            'product_id' => (int)$data['product_id'],
            'sku' => (string)$data['sku'],
            'status' => $data['status'],
            'price' => $data['price'],
            'special_price' => $data['special_price'],
            'al_pagar_label' => $data['al_pagar_label'],
            'al_pagar_precio' => $data['al_pagar_precio'],
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    public function getSnapshot(int $productId = 0, string $sku = ''): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('leancommerce_product_import_snapshot');

        $select = $connection->select()->from($tableName);

        if ($productId > 0) {
            $select->where('product_id = ?', $productId);
        } elseif ($sku !== '') {
            $select->where('sku = ?', $sku);
        } else {
            return null;
        }

        $select->limit(1);

        $result = $connection->fetchRow($select);

        return $result ?: null;
    }

    public function deleteSnapshot(int $productId = 0, string $sku = ''): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('leancommerce_product_import_snapshot');

        if ($productId > 0) {
            $connection->delete(
                $tableName,
                ['product_id = ?' => $productId]
            );
            return;
        }

        if ($sku !== '') {
            $connection->delete(
                $tableName,
                ['sku = ?' => $sku]
            );
        }
    }
}