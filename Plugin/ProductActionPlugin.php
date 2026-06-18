<?php

namespace LeanCommerce\ProductAudit\Plugin;

use LeanCommerce\ProductAudit\Model\AuditContextResolver;
use LeanCommerce\ProductAudit\ResourceModel\Logger as AuditLogger;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Action;
use Psr\Log\LoggerInterface;

class ProductActionPlugin
{
    /**
     * @var AuditLogger
     */
    private $auditLogger;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var AuditContextResolver
     */
    private $auditContextResolver;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var string[]
     */
    private $watchedAttributes = [
        'status',
        'price',
        'special_price',
        'al_pagar_label',
        'al_pagar_precio'
    ];

    public function __construct(
        AuditLogger $auditLogger,
        ProductRepositoryInterface $productRepository,
        AuditContextResolver $auditContextResolver,
        LoggerInterface $logger
    ) {
        $this->auditLogger = $auditLogger;
        $this->productRepository = $productRepository;
        $this->auditContextResolver = $auditContextResolver;
        $this->logger = $logger;
    }

    public function beforeUpdateAttributes(
        Action $subject,
        array $productIds,
        array $attrData,
        $storeId
    ) {
        $watched = array_intersect(array_keys($attrData), $this->watchedAttributes);

        if (empty($watched) || empty($productIds)) {
            return [$productIds, $attrData, $storeId];
        }

        $context = $this->auditContextResolver->resolve();

        foreach ($productIds as $productId) {
            try {
                $product = $this->productRepository->getById((int)$productId, false, $storeId, true);

                foreach ($watched as $attributeCode) {
                    $oldValue = $this->normalizeValue($product->getData($attributeCode), $attributeCode);
                    $newValue = $this->normalizeValue($attrData[$attributeCode], $attributeCode);

                    if ($oldValue === $newValue) {
                        continue;
                    }

                    $entry = [
                        'attribute_code' => $attributeCode,
                        'old_value' => $this->stringifyValue($oldValue),
                        'new_value' => $this->stringifyValue($newValue)
                    ];

                    // Keep Origin Detail clean. The actual changes are stored in Request Changes.
                    $originDetail = $context['origin_detail'];
                    $requestPayloadSummary = $this->buildRequestPayloadSummary($attrData, [$entry]);

                    $this->auditLogger->logChange(
                        (int)$product->getId(),
                        (string)$product->getSku(),
                        $attributeCode,
                        $this->stringifyValue($oldValue),
                        $this->stringifyValue($newValue),
                        $context['origin_detail'],
                        $context['area'],
                        $context['origin_type'],
                        $originDetail,
                        (int)$storeId,
                        null,
                        $requestPayloadSummary
                    );

                    $this->logger->info('Mass product audit change detected', [
                        'product_id' => (int)$product->getId(),
                        'sku' => (string)$product->getSku(),
                        'attribute_code' => $attributeCode,
                        'old_value' => $oldValue,
                        'new_value' => $newValue,
                        'origin_type' => $context['origin_type'],
                        'origin_detail' => $originDetail,
                        'area' => $context['area'],
                        'store_id' => (int)$storeId
                    ]);
                }
            } catch (\Throwable $e) {
                $this->logger->error('Unable to audit mass product attribute update', [
                    'product_id' => $productId,
                    'message' => $e->getMessage()
                ]);
            }
        }

        return [$productIds, $attrData, $storeId];
    }


    private function buildRequestPayloadSummary(array $attrData, array $entries): string
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
