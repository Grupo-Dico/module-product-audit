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
            $entries = [];

            foreach ($this->watchedAttributes as $attributeCode) {
                if (!array_key_exists($attributeCode, $row)) {
                    continue;
                }

                $oldValue = $this->normalizeValue($snapshot[$attributeCode] ?? null, $attributeCode);
                $newValue = $this->normalizeValue($row[$attributeCode] ?? null, $attributeCode);

                if ($oldValue === $newValue) {
                    continue;
                }

                $entries[] = [
                    'attribute_code' => $attributeCode,
                    'old_value' => $this->stringifyValue($oldValue),
                    'new_value' => $this->stringifyValue($newValue)
                ];
            }

            if (!$entries) {
                $this->importSnapshot->deleteSnapshot($resolvedProductId, $resolvedSku);
                continue;
            }

            // Keep Origin Detail clean. The actual changes are stored in Request Changes.
            $originDetail = $this->buildImportBaseDetail($storeData, $row);
            $requestPayloadSummary = $this->buildRequestPayloadSummary($row, $entries);

            foreach ($entries as $entry) {
                try {
                    $this->auditLogger->logChange(
                        $resolvedProductId,
                        $resolvedSku,
                        $entry['attribute_code'],
                        $entry['old_value'],
                        $entry['new_value'],
                        'import_csv',
                        'import_export',
                        'import_csv',
                        $originDetail,
                        $storeData['store_id'],
                        $storeData['store_code'],
                        $requestPayloadSummary
                    );
                } catch (\Throwable $e) {
                    $this->logger->error('Unable to persist import product audit log', [
                        'product_id' => $resolvedProductId,
                        'sku' => $resolvedSku,
                        'attribute_code' => $entry['attribute_code'],
                        'origin_detail' => $originDetail,
                        'message' => $e->getMessage(),
                        'store_id' => $storeData['store_id'],
                        'store_code' => $storeData['store_code']
                    ]);
                }
            }

            $this->importSnapshot->deleteSnapshot($resolvedProductId, $resolvedSku);
        }
    }

    private function buildRequestPayloadSummary(array $row, array $entries): string
    {
        $items = [];

        foreach ($entries as $entry) {
            $items[] = sprintf(
                '%s:%s=>%s',
                $entry['attribute_code'],
                $entry['old_value'] === null ? 'NULL' : $entry['old_value'],
                $entry['new_value'] === null ? 'NULL' : $entry['new_value']
            );
        }

        return $this->limitText('changed: ' . implode(', ', $items), 2048);
    }

    private function summarizeValue($value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return $encoded === false ? '[complex]' : $this->limitText($encoded, 256);
        }

        if ($value === '') {
            return "''";
        }

        return $this->limitText((string)$value, 256);
    }

    private function limitText(string $text, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $length);
        }

        return substr($text, 0, $length);
    }

    private function buildImportBaseDetail(array $storeData, array $row): string
    {
        $parts = ['catalog_product_import'];

        if (!empty($storeData['store_code'])) {
            $parts[] = 'store:' . $storeData['store_code'];
        }

        if (!empty($row['_attribute_set'])) {
            $parts[] = 'attribute_set:' . (string)$row['_attribute_set'];
        }

        if (!empty($row['product_type'])) {
            $parts[] = 'product_type:' . (string)$row['product_type'];
        }

        return implode(' | ', $parts);
    }

    private function buildOriginDetail(string $baseDetail, array $entries): string
    {
        $changes = [];

        foreach ($entries as $entry) {
            $changes[] = sprintf(
                '%s:%s=>%s',
                $entry['attribute_code'],
                $entry['old_value'] === null ? 'NULL' : $entry['old_value'],
                $entry['new_value'] === null ? 'NULL' : $entry['new_value']
            );
        }

        $detail = $baseDetail . ' | changed: ' . implode(', ', $changes);

        if (function_exists('mb_substr')) {
            return mb_substr($detail, 0, 2048);
        }

        return substr($detail, 0, 2048);
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

            if (is_numeric($value)) {
                return number_format((float)$value, 4, '.', '');
            }

            return trim((string)$value);
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
