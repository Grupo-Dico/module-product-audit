<?php

namespace LeanCommerce\ProductAudit\Plugin;

use LeanCommerce\ProductAudit\Model\AuditContextResolver;
use LeanCommerce\ProductAudit\Model\ImportAuditContext;
use LeanCommerce\ProductAudit\ResourceModel\Logger as AuditLogger;
use Magento\Catalog\Model\ResourceModel\Product;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Registry;
use Psr\Log\LoggerInterface;

class ProductResourcePlugin
{
    /**
     * @var AuditLogger
     */
    private $auditLogger;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var AuditContextResolver
     */
    private $auditContextResolver;

    /**
     * @var Registry
     */
    private $registry;

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
        AuditLogger $auditLogger,
        LoggerInterface $logger,
        AuditContextResolver $auditContextResolver,
        Registry $registry
    ) {
        $this->auditLogger = $auditLogger;
        $this->logger = $logger;
        $this->auditContextResolver = $auditContextResolver;
        $this->registry = $registry;
    }

    public function beforeSave(
        Product $subject,
        AbstractModel $product
    ) {
        if ($this->registry->registry(ImportAuditContext::REGISTRY_KEY)) {
            return [$product];
        }

        if (!$product->getId()) {
            return [$product];
        }

        $context = $this->auditContextResolver->resolve();
        $sku = (string)$product->getSku();
        $productId = (int)$product->getId();

        foreach ($this->watchedAttributes as $attributeCode) {
            $oldValue = $this->normalizeValue($product->getOrigData($attributeCode), $attributeCode);
            $newValue = $this->normalizeValue($product->getData($attributeCode), $attributeCode);

            if ($oldValue === $newValue) {
                continue;
            }

            try {
                $this->auditLogger->logChange(
                    $productId,
                    $sku,
                    $attributeCode,
                    $this->stringifyValue($oldValue),
                    $this->stringifyValue($newValue),
                    $context['origin_detail'],
                    $context['area'],
                    $context['origin_type'],
                    $context['origin_detail']
                );

                $this->logger->info('Product audit change detected', [
                    'product_id' => $productId,
                    'sku' => $sku,
                    'attribute_code' => $attributeCode,
                    'old_value' => $oldValue,
                    'new_value' => $newValue,
                    'origin_type' => $context['origin_type'],
                    'origin_detail' => $context['origin_detail'],
                    'area' => $context['area']
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Unable to persist product audit log', [
                    'product_id' => $productId,
                    'sku' => $sku,
                    'attribute_code' => $attributeCode,
                    'message' => $e->getMessage()
                ]);
            }
        }

        return [$product];
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