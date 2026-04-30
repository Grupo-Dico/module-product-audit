<?php

namespace LeanCommerce\ProductAudit\Observer;

use LeanCommerce\ProductAudit\Model\ImportSnapshot;
use LeanCommerce\ProductAudit\ResourceModel\Logger as AuditLogger;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Psr\Log\LoggerInterface;

class ProductImportObserver implements ObserverInterface
{
    /**
     * @var ImportSnapshot
     */
    private $importSnapshot;

    /**
     * @var AuditLogger
     */
    private $auditLogger;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var StoreRepositoryInterface
     */
    private $storeRepository;

    /**
     * @var string[]
     */
    private $watchedAttributes = [
        'price',
        'status',
        'special_price',
        'al_pagar_label',
        'al_pagar_precio'
    ];

    public function __construct(
        ImportSnapshot $importSnapshot,
        AuditLogger $auditLogger,
        LoggerInterface $logger,
        StoreRepositoryInterface $storeRepository
    ) {
        $this->importSnapshot = $importSnapshot;
        $this->auditLogger = $auditLogger;
        $this->logger = $logger;
        $this->storeRepository = $storeRepository;
    }

    public function execute(Observer $observer)
    {
        $bunch = $observer->getEvent()->getBunch();
        if (!is_array($bunch) || empty($bunch)) {
            return;
        }

        foreach ($bunch as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sku = isset($row['sku']) ? (string)$row['sku'] : '';
            $productId = isset($row['entity_id']) ? (int)$row['entity_id'] : 0;

            $storeData = $this->resolveImportStoreData($row);

            $snapshot = $this->importSnapshot->getSnapshot($productId, $sku);
            if (!$snapshot) {
                continue;
            }

            $resolvedProductId = (int)$snapshot['product_id'];
            $resolvedSku = (string)$snapshot['sku'];

            foreach ($this->watchedAttributes as $attributeCode) {
                if (!array_key_exists($attributeCode, $row)) {
                    continue;
                }

                $oldValue = $this->normalizeValue($snapshot[$attributeCode] ?? null, $attributeCode);
                $newValue = $this->normalizeValue($row[$attributeCode] ?? null, $attributeCode);

                if ($oldValue === $newValue) {
                    continue;
                }

                try {
                    $this->auditLogger->logChange(
                        $resolvedProductId,
                        $resolvedSku,
                        $attributeCode,
                        $this->stringifyValue($oldValue),
                        $this->stringifyValue($newValue),
                        'import_csv',
                        'import_export',
                        'import_csv',
                        'catalog_product_import',
                        $storeData['store_id'],
                        $storeData['store_code']
                    );
                } catch (\Throwable $e) {
                    $this->logger->error('Unable to persist import product audit log', [
                        'product_id' => $resolvedProductId,
                        'sku' => $resolvedSku,
                        'attribute_code' => $attributeCode,
                        'message' => $e->getMessage(),
                        'store_id' => $storeData['store_id'],
                        'store_code' => $storeData['store_code']
                    ]);
                }
            }

            $this->importSnapshot->deleteSnapshot($resolvedProductId, $resolvedSku);
        }
    }


    private function resolveImportStoreData(array $row): array
    {
        $storeCode = isset($row['store_view_code']) ? trim((string)$row['store_view_code']) : '';

        if ($storeCode !== '') {
            try {
                $store = $this->storeRepository->get($storeCode);
                return [
                    'store_id' => (int)$store->getId(),
                    'store_code' => (string)$store->getCode()
                ];
            } catch (\Throwable $e) {
                return [
                    'store_id' => null,
                    'store_code' => $storeCode
                ];
            }
        }

        return [
            'store_id' => 0,
            'store_code' => 'admin'
        ];
    }

    private function normalizeValue($value, string $attributeCode)
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (in_array($attributeCode, ['price', 'special_price', 'al_pagar_precio'], true)) {
            $value = str_replace(',', '', (string)$value);
            return is_numeric($value) ? (string)(float)$value : trim((string)$value);
        }

        if ($attributeCode === 'status') {
            return (string)(int)$value;
        }

        return trim((string)$value);
    }

    private function stringifyValue($value): ?string
    {
        return $value === null ? null : (string)$value;
    }
}
