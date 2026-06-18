<?php

namespace LeanCommerce\ProductAudit\Plugin;

use LeanCommerce\ProductAudit\Model\AuditContextResolver;
use LeanCommerce\ProductAudit\Model\ImportAuditContext;
use LeanCommerce\ProductAudit\ResourceModel\Logger as AuditLogger;
use Magento\Catalog\Model\ResourceModel\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
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
    private $stockRegistry;

    private $watchedAttributes = [
        'price',
        'status',
        'special_price',
        'al_pagar_label',
        'al_pagar_precio'
    ];

    private $watchedStockFields = [
        'qty',
        'is_in_stock'
    ];

    public function __construct(
        AuditLogger $auditLogger,
        LoggerInterface $logger,
        AuditContextResolver $auditContextResolver,
        Registry $registry,
        StoreManagerInterface $storeManager,
        RequestInterface $request,
        StockRegistryInterface $stockRegistry
    ) {
        $this->auditLogger = $auditLogger;
        $this->logger = $logger;
        $this->auditContextResolver = $auditContextResolver;
        $this->registry = $registry;
        $this->storeManager = $storeManager;
        $this->request = $request;
        $this->stockRegistry = $stockRegistry;
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
        $storeCode = $this->resolveStoreCode($storeId);

        $apiPayload = $this->getApiProductPayload($context['origin_type']);
        $payloadProductData = $this->extractPayloadProductData($apiPayload);
        $payloadStockData = $this->extractPayloadStockData($apiPayload);

        if (in_array($context['origin_type'], ['api_rest', 'api_soap'], true)) {
            // For API requests, audit only the fields explicitly sent in the payload.
            // This avoids false positives such as status:2=>NULL caused by partial API saves.
            $attributesToAudit = array_keys($payloadProductData);
        } else {
            $attributesToAudit = array_values(array_unique(array_merge(
                $this->watchedAttributes,
                array_keys($payloadProductData)
            )));
        }

        $entries = [];

        foreach ($attributesToAudit as $attributeCode) {
            if ($this->shouldSkipAttribute(
                $attributeCode,
                $postedProductData,
                $useDefault,
                $context['origin_type'],
                $product,
                $payloadProductData
            )) {
                continue;
            }

            $oldValue = $this->normalizeValue(
                $this->resolveOldValue($subject, $product, $attributeCode, $storeId),
                $attributeCode
            );

            $newValue = $this->normalizeValue(
                $this->resolveNewValue($attributeCode, $postedProductData, $product, $payloadProductData),
                $attributeCode
            );

            if ($oldValue === $newValue) {
                continue;
            }

            $entries[] = [
                'attribute_code' => $attributeCode,
                'old_value' => $this->stringifyValue($oldValue),
                'new_value' => $this->stringifyValue($newValue)
            ];
        }

        foreach ($this->buildStockChangeEntries($sku, $payloadStockData) as $stockEntry) {
            $entries[] = $stockEntry;
        }

        if (!$entries) {
            return [$product];
        }

        // Keep Origin Detail clean. The actual changes are stored in Request Changes.
        $originDetail = $context['origin_detail'];
        $requestPayloadSummary = $this->buildRequestPayloadSummary(
            $context['origin_type'],
            $postedProductData,
            $payloadProductData,
            $payloadStockData,
            $entries
        );

        if (in_array($context['origin_type'], ['api_rest', 'api_soap'], true)) {
            // API updates must create one audit row per SKU/request, not one row per field.
            $apiAuditKey = 'leancommerce_product_audit_api_logged_' . md5(
                $sku . '|' . $context['origin_detail'] . '|' . $requestPayloadSummary
            );

            if ($this->registry->registry($apiAuditKey)) {
                return [$product];
            }

            $this->registry->register($apiAuditKey, true);

            $entry = count($entries) === 1
                ? $entries[0]
                : ['attribute_code' => 'api_update', 'old_value' => null, 'new_value' => null];

            try {
                $this->auditLogger->logChange(
                    $productId,
                    $sku,
                    $entry['attribute_code'],
                    $entry['old_value'],
                    $entry['new_value'],
                    $context['origin_detail'],
                    $context['area'],
                    $context['origin_type'],
                    $originDetail,
                    $storeId,
                    $storeCode,
                    $requestPayloadSummary
                );
            } catch (\Throwable $e) {
                $this->logger->error('Unable to persist product audit log', [
                    'product_id' => $productId,
                    'sku' => $sku,
                    'attribute_code' => $entry['attribute_code'],
                    'origin_type' => $context['origin_type'],
                    'origin_detail' => $originDetail,
                    'message' => $e->getMessage()
                ]);
            }

            return [$product];
        }

        foreach ($entries as $entry) {
            try {
                $this->auditLogger->logChange(
                    $productId,
                    $sku,
                    $entry['attribute_code'],
                    $entry['old_value'],
                    $entry['new_value'],
                    $context['origin_detail'],
                    $context['area'],
                    $context['origin_type'],
                    $originDetail,
                    $storeId,
                    $storeCode,
                    $requestPayloadSummary
                );
            } catch (\Throwable $e) {
                $this->logger->error('Unable to persist product audit log', [
                    'product_id' => $productId,
                    'sku' => $sku,
                    'attribute_code' => $entry['attribute_code'],
                    'origin_type' => $context['origin_type'],
                    'origin_detail' => $originDetail,
                    'message' => $e->getMessage()
                ]);
            }
        }

        return [$product];
    }

    private function shouldSkipAttribute(
        string $attributeCode,
        array $postedProductData,
        array $useDefault,
        string $originType,
        AbstractModel $product,
        array $payloadProductData = []
    ): bool {
        if (array_key_exists($attributeCode, $useDefault)) {
            $value = $useDefault[$attributeCode];

            if ($value === '1' || $value === 1 || $value === true) {
                return true;
            }
        }

        if (array_key_exists($attributeCode, $payloadProductData)) {
            return false;
        }

        if (array_key_exists($attributeCode, $postedProductData)) {
            return false;
        }

        // En Admin normalmente viene dentro de product[attribute]. Si no viene, no lo audites
        // para evitar falsos positivos por saves parciales del formulario.
        if ($originType === 'admin') {
            return true;
        }

        // En REST/SOAP/CLI/cron no siempre existe request->getParam('product'), así que se toma
        // el dato directo del modelo si realmente fue seteado/cambiado.
        return !$product->dataHasChangedFor($attributeCode);
    }


    private function resolveOldValue(
        Product $subject,
        AbstractModel $product,
        string $attributeCode,
        ?int $storeId
    ) {
        $oldValue = $product->getOrigData($attributeCode);

        if ($oldValue !== null && $oldValue !== '') {
            return $oldValue;
        }

        $storesToCheck = [];

        if ($storeId !== null) {
            $storesToCheck[] = (int)$storeId;
        }

        $storesToCheck[] = 0;
        $storesToCheck = array_values(array_unique($storesToCheck));

        foreach ($storesToCheck as $rawStoreId) {
            try {
                $rawValue = $subject->getAttributeRawValue(
                    (int)$product->getId(),
                    $attributeCode,
                    $rawStoreId
                );
            } catch (\Throwable $e) {
                $rawValue = null;
            }

            if (is_array($rawValue)) {
                $rawValue = $rawValue[$attributeCode] ?? null;
            }

            if ($rawValue !== false && $rawValue !== null && $rawValue !== '') {
                return $rawValue;
            }
        }

        return $oldValue;
    }

    private function resolveNewValue(
        string $attributeCode,
        array $postedProductData,
        AbstractModel $product,
        array $payloadProductData = []
    ) {
        if (array_key_exists($attributeCode, $payloadProductData)) {
            return $payloadProductData[$attributeCode];
        }

        if (array_key_exists($attributeCode, $postedProductData)) {
            return $postedProductData[$attributeCode];
        }

        return $product->getData($attributeCode);
    }

    private function getApiProductPayload(string $originType): array
    {
        if (!in_array($originType, ['api_rest', 'api_soap'], true)) {
            return [];
        }

        $content = '';

        try {
            if (method_exists($this->request, 'getContent')) {
                $content = (string)$this->request->getContent();
            }
        } catch (\Throwable $e) {
            $content = '';
        }

        if ($content === '') {
            try {
                $content = (string)file_get_contents('php://input');
            } catch (\Throwable $e) {
                $content = '';
            }
        }

        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            return [];
        }

        if (isset($decoded['product']) && is_array($decoded['product'])) {
            return $decoded['product'];
        }

        return $decoded;
    }

    private function extractPayloadProductData(array $apiPayload): array
    {
        if (!$apiPayload) {
            return [];
        }

        $data = [];

        foreach ($this->watchedAttributes as $attributeCode) {
            if (array_key_exists($attributeCode, $apiPayload)) {
                $data[$attributeCode] = $apiPayload[$attributeCode];
            }
        }

        if (isset($apiPayload['custom_attributes']) && is_array($apiPayload['custom_attributes'])) {
            foreach ($apiPayload['custom_attributes'] as $customAttribute) {
                if (!is_array($customAttribute)) {
                    continue;
                }

                if (!isset($customAttribute['attribute_code'])) {
                    continue;
                }

                $attributeCode = (string)$customAttribute['attribute_code'];
                $data[$attributeCode] = array_key_exists('value', $customAttribute) ? $customAttribute['value'] : null;
            }
        }

        return $data;
    }

    private function extractPayloadStockData(array $apiPayload): array
    {
        if (!$apiPayload) {
            return [];
        }

        if (!isset($apiPayload['extension_attributes']['stock_item'])) {
            return [];
        }

        $stockItem = $apiPayload['extension_attributes']['stock_item'];

        return is_array($stockItem) ? $stockItem : [];
    }

    private function buildStockChangeEntries(string $sku, array $payloadStockData): array
    {
        if (!$payloadStockData) {
            return [];
        }

        $entries = [];
        $stockItem = null;

        try {
            $stockItem = $this->stockRegistry->getStockItemBySku($sku);
        } catch (\Throwable $e) {
            $this->logger->debug('Unable to load stock item for product audit', [
                'sku' => $sku,
                'message' => $e->getMessage()
            ]);
        }

        foreach ($this->watchedStockFields as $field) {
            if (!array_key_exists($field, $payloadStockData)) {
                continue;
            }

            $oldValue = $this->normalizeValue(
                $stockItem ? $stockItem->getData($field) : null,
                'stock.' . $field
            );
            $newValue = $this->normalizeValue(
                $payloadStockData[$field],
                'stock.' . $field
            );

            if ($oldValue === $newValue) {
                continue;
            }

            $entries[] = [
                'attribute_code' => 'stock.' . $field,
                'old_value' => $this->stringifyValue($oldValue),
                'new_value' => $this->stringifyValue($newValue)
            ];
        }

        return $entries;
    }

    private function buildRequestPayloadSummary(
        string $originType,
        array $postedProductData,
        array $payloadProductData,
        array $payloadStockData,
        array $entries
    ): string {
        // IMPORTANT:
        // This column must summarize what was explicitly received in the request/import/admin form.
        // Do NOT put old=>new comparisons here because partial API product saves can have incomplete
        // product orig data and create misleading values such as NULL=>1.
        if (in_array($originType, ['api_rest', 'api_soap'], true)) {
            $items = [];

            foreach ($payloadProductData as $attributeCode => $value) {
                $items[] = $attributeCode . '=' . $this->summarizeValue($value);
            }

            foreach ($payloadStockData as $field => $value) {
                if (!in_array((string)$field, $this->watchedStockFields, true)) {
                    continue;
                }

                $items[] = 'stock.' . $field . '=' . $this->summarizeValue($value);
            }

            return $this->limitText('payload: ' . implode(', ', $items), 2048);
        }

        if ($originType === 'admin') {
            $items = [];

            foreach ($postedProductData as $attributeCode => $value) {
                if (!in_array((string)$attributeCode, $this->watchedAttributes, true)) {
                    continue;
                }

                $items[] = $attributeCode . '=' . $this->summarizeValue($value);
            }

            return $this->limitText('admin_form: ' . implode(', ', $items), 2048);
        }

        // Imports and other sources keep the real detected changes in this summary because they do not
        // have the same partial REST payload/origData issue.
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

    private function resolveStoreCode(?int $storeId): ?string
    {
        if ($storeId === null) {
            return null;
        }

        try {
            return (string)$this->storeManager->getStore($storeId)->getCode();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeValue($value, string $attributeCode)
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (in_array($attributeCode, ['price', 'special_price', 'al_pagar_precio', 'stock.qty'], true)) {
            $value = str_replace(',', '', (string)$value);

            if (is_numeric($value)) {
                return number_format((float)$value, 4, '.', '');
            }

            return trim((string)$value);
        }

        if (in_array($attributeCode, ['status', 'stock.is_in_stock'], true)) {
            return (string)(int)$value;
        }

        return trim((string)$value);
    }

    private function stringifyValue($value): ?string
    {
        return $value === null ? null : (string)$value;
    }
}
