<?php

namespace LeanCommerce\ProductAudit\Plugin;

use LeanCommerce\ProductAudit\Model\AuditContextResolver;
use LeanCommerce\ProductAudit\Model\ImportAuditContext;
use LeanCommerce\ProductAudit\ResourceModel\Logger as AuditLogger;
use Magento\Catalog\Model\ResourceModel\Product;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class ProductResourcePlugin
{
    private $auditLogger;
    private $logger;
    private $auditContextResolver;
    private $registry;
    private $storeManager;
    private $request;

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
        Registry $registry,
        StoreManagerInterface $storeManager,
        RequestInterface $request
    ) {
        $this->auditLogger = $auditLogger;
        $this->logger = $logger;
        $this->auditContextResolver = $auditContextResolver;
        $this->registry = $registry;
        $this->storeManager = $storeManager;
        $this->request = $request;
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

        $postedProductData = $this->request->getParam('product', []);
        $useDefault = $this->request->getParam('use_default', []);

        if (!is_array($postedProductData)) {
            $postedProductData = [];
        }

        if (!is_array($useDefault)) {
            $useDefault = [];
        }

        $context = $this->auditContextResolver->resolve();
        $sku = (string)$product->getSku();
        $productId = (int)$product->getId();
        $storeId = $this->resolveStoreId($product);

        foreach ($this->watchedAttributes as $attributeCode) {
            if ($this->shouldSkipAttribute($attributeCode, $postedProductData, $useDefault)) {
                continue;
            }

            $oldValue = $this->normalizeValue(
                $product->getOrigData($attributeCode),
                $attributeCode
            );

            $newValue = $this->normalizeValue(
                $postedProductData[$attributeCode],
                $attributeCode
            );

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
                    $context['origin_detail'],
                    $storeId
                );
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

    private function shouldSkipAttribute(
        string $attributeCode,
        array $postedProductData,
        array $useDefault
    ): bool {
        if (!array_key_exists($attributeCode, $postedProductData)) {
            return true;
        }

        if (array_key_exists($attributeCode, $useDefault)) {
            $value = $useDefault[$attributeCode];

            if ($value === '1' || $value === 1 || $value === true) {
                return true;
            }
        }

        return false;
    }

    private function resolveStoreId(AbstractModel $product): ?int
    {
        $storeId = $this->request->getParam('store');

        if ($storeId !== null && $storeId !== '') {
            return (int)$storeId;
        }

        $storeId = $product->getStoreId();

        if ($storeId !== null && $storeId !== '') {
            return (int)$storeId;
        }

        try {
            return (int)$this->storeManager->getStore()->getId();
        } catch (\Throwable $e) {
            return null;
        }
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
