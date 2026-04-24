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

                    $this->auditLogger->logChange(
                        (int)$product->getId(),
                        (string)$product->getSku(),
                        $attributeCode,
                        $this->stringifyValue($oldValue),
                        $this->stringifyValue($newValue),
                        $context['origin_detail'],
                        $context['area'],
                        $context['origin_type'],
                        $context['origin_detail']
                    );

                    $this->logger->info('Mass product audit change detected', [
                        'product_id' => (int)$product->getId(),
                        'sku' => (string)$product->getSku(),
                        'attribute_code' => $attributeCode,
                        'old_value' => $oldValue,
                        'new_value' => $newValue,
                        'origin_type' => $context['origin_type'],
                        'origin_detail' => $context['origin_detail'],
                        'area' => $context['area']
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