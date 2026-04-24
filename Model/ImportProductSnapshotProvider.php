<?php

namespace LeanCommerce\ProductAudit\Model;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

class ImportProductSnapshotProvider
{
    /**
     * @var CollectionFactory
     */
    private $productCollectionFactory;

    /**
     * @var string[]
     */
    private $watchedAttributes = [
        'status',
        'price',
        'special_price',
        'al_pagar_label',
        'al_pagar_precio',
    ];

    public function __construct(CollectionFactory $productCollectionFactory)
    {
        $this->productCollectionFactory = $productCollectionFactory;
    }

    public function getSnapshots(array $rows): array
    {
        $entityIds = [];
        $skus = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $entityId = isset($row['entity_id']) ? (int)$row['entity_id'] : 0;
            $sku = isset($row['sku']) ? trim((string)$row['sku']) : '';

            if ($entityId > 0) {
                $entityIds[] = $entityId;
            }

            if ($sku !== '') {
                $skus[] = $sku;
            }
        }

        $entityIds = array_values(array_unique(array_filter($entityIds)));
        $skus = array_values(array_unique(array_filter($skus)));

        if (empty($entityIds) && empty($skus)) {
            return [];
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToSelect($this->watchedAttributes);

        if (!empty($entityIds) && !empty($skus)) {
            $collection->addFieldToFilter([
                ['attribute' => 'entity_id', 'in' => $entityIds],
                ['attribute' => 'sku', 'in' => $skus],
            ]);
        } elseif (!empty($entityIds)) {
            $collection->addFieldToFilter('entity_id', ['in' => $entityIds]);
        } else {
            $collection->addFieldToFilter('sku', ['in' => $skus]);
        }

        $result = [];
        foreach ($collection as $product) {
            $snapshot = [
                'product_id' => (int)$product->getId(),
                'sku' => (string)$product->getSku(),
                'status' => $this->normalizeValue($product->getData('status')),
                'price' => $this->normalizeValue($product->getData('price')),
                'special_price' => $this->normalizeValue($product->getData('special_price')),
                'al_pagar_label' => $this->normalizeValue($product->getData('al_pagar_label')),
                'al_pagar_precio' => $this->normalizeValue($product->getData('al_pagar_precio')),
            ];

            $result['id:' . (int)$product->getId()] = $snapshot;
            $result['sku:' . (string)$product->getSku()] = $snapshot;
        }

        return $result;
    }

    private function normalizeValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? trim((string)$value) : null;
    }
}