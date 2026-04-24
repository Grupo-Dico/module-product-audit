<?php

namespace LeanCommerce\ProductAudit\ResourceModel;

use Magento\Framework\App\ResourceConnection;

class ImportSnapshot
{
    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private $connection;

    /**
     * @var string
     */
    private $table;

    public function __construct(
        ResourceConnection $resource
    ) {
        $this->connection = $resource->getConnection();
        $this->table = $resource->getTableName('leancommerce_product_import_snapshot');
    }

    /**
     * Save or update snapshot for a given product/sku.
     */
    public function saveSnapshot(
        int $productId,
        string $sku,
        ?int $status,
        ?float $price,
        ?float $specialPrice,
        ?string $alPagarLabel,
        ?string $alPagarPrecio
    ): void {
        $data = [
            'product_id' => $productId,
            'sku' => $sku,
            'status' => $status,
            'price' => $price,
            'special_price' => $specialPrice,
            'al_pagar_label' => $alPagarLabel,
            'al_pagar_precio' => $alPagarPrecio,
        ];

        $this->connection->insertOnDuplicate(
            $this->table,
            $data,
            array_keys($data)
        );
    }

    /**
     * Get snapshot row for product and sku.
     */
    public function getSnapshot(int $productId, string $sku): ?array
    {
        $select = $this->connection->select()
            ->from($this->table)
            ->where('product_id = ?', $productId)
            ->where('sku = ?', $sku)
            ->order('snapshot_id DESC')
            ->limit(1);

        $row = $this->connection->fetchRow($select);

        return $row !== false ? $row : null;
    }

    /**
     * Delete snapshot rows for product and sku.
     */
    public function deleteSnapshot(int $productId, string $sku): void
    {
        $this->connection->delete(
            $this->table,
            [
                'product_id = ?' => $productId,
                'sku = ?' => $sku,
            ]
        );
    }
}

