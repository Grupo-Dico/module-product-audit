<?php

namespace LeanCommerce\ProductAudit\Plugin;

use LeanCommerce\ProductAudit\Model\ImportAuditContext;
use LeanCommerce\ProductAudit\Model\ImportProductSnapshotProvider;
use LeanCommerce\ProductAudit\Model\ImportSnapshot;
use Magento\CatalogImportExport\Model\Import\Product as ImportProduct;
use Magento\Framework\Registry;
use Psr\Log\LoggerInterface;

class ImportProductPlugin
{
    /**
     * @var ImportProductSnapshotProvider
     */
    private $snapshotProvider;

    /**
     * @var ImportSnapshot
     */
    private $importSnapshot;

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array<string, bool>
     */
    private $processed = [];

    public function __construct(
        ImportProductSnapshotProvider $snapshotProvider,
        ImportSnapshot $importSnapshot,
        Registry $registry,
        LoggerInterface $logger
    ) {
        $this->snapshotProvider = $snapshotProvider;
        $this->importSnapshot = $importSnapshot;
        $this->registry = $registry;
        $this->logger = $logger;
    }

    public function beforeSaveProductEntity(
        ImportProduct $subject,
        array $entityRowsIn,
        array $entityRowsUp
    ) {
        $this->processed = [];
        $this->registerImportContext();

        $rows = array_merge($entityRowsIn, $entityRowsUp);
        $snapshots = $this->snapshotProvider->getSnapshots($rows);

        foreach ($rows as $rowData) {
            if (!is_array($rowData)) {
                continue;
            }

            $sku = isset($rowData['sku']) ? trim((string)$rowData['sku']) : '';
            $entityId = isset($rowData['entity_id']) ? (int)$rowData['entity_id'] : 0;

            if ($sku === '' && $entityId <= 0) {
                continue;
            }

            $cacheKey = $entityId > 0 ? 'id:' . $entityId : 'sku:' . $sku;
            if (isset($this->processed[$cacheKey])) {
                continue;
            }

            $snapshot = $snapshots[$cacheKey] ?? null;
            if (!$snapshot && $sku !== '') {
                $snapshot = $snapshots['sku:' . $sku] ?? null;
            }
            if (!$snapshot && $entityId > 0) {
                $snapshot = $snapshots['id:' . $entityId] ?? null;
            }

            if (!$snapshot || empty($snapshot['product_id'])) {
                $this->logger->debug('AUDIT IMPORT: product not found in batch snapshot, snapshot skipped', [
                    'sku' => $sku,
                    'entity_id' => $entityId
                ]);
                continue;
            }

            try {
                $this->importSnapshot->saveSnapshot($snapshot);
                $this->processed[$cacheKey] = true;

                if (!empty($snapshot['product_id'])) {
                    $this->processed['id:' . (int)$snapshot['product_id']] = true;
                }
                if (!empty($snapshot['sku'])) {
                    $this->processed['sku:' . (string)$snapshot['sku']] = true;
                }

                $this->logger->debug('AUDIT IMPORT: batch snapshot saved', [
                    'product_id' => (int)$snapshot['product_id'],
                    'sku' => (string)$snapshot['sku']
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Unable to create product import snapshot', [
                    'sku' => $sku,
                    'entity_id' => $entityId,
                    'message' => $e->getMessage()
                ]);
            }
        }

        return [$entityRowsIn, $entityRowsUp];
    }

    public function afterSaveProductEntity(
        ImportProduct $subject,
        $result,
        array $entityRowsIn,
        array $entityRowsUp
    ) {
        $this->registry->unregister(ImportAuditContext::REGISTRY_KEY);
        $this->processed = [];

        return $result;
    }

    private function registerImportContext(): void
    {
        if (!$this->registry->registry(ImportAuditContext::REGISTRY_KEY)) {
            $this->registry->register(ImportAuditContext::REGISTRY_KEY, true);
        }
    }
}
