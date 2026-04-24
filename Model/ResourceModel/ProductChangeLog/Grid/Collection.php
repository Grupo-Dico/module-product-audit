<?php

namespace LeanCommerce\ProductAudit\Model\ResourceModel\ProductChangeLog\Grid;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

class Collection extends SearchResult
{
    protected $eventPrefix = 'leancommerce_productaudit_log_grid_collection';
    protected $eventObject = 'productaudit_log_grid_collection';

    public function __construct(
        \Magento\Framework\Data\Collection\EntityFactoryInterface $entityFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\Data\Collection\Db\FetchStrategyInterface $fetchStrategy,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        ResourceConnection $resourceConnection,
        $mainTable = null,
        $resourceModel = \LeanCommerce\ProductAudit\Model\ResourceModel\ProductChangeLog::class,
        $identifierName = 'log_id',
        $connectionName = null
    ) {
        $mainTable = $mainTable ?: $resourceConnection->getTableName('leancommerce_product_change_log');

        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $mainTable,
            $resourceModel,
            $identifierName,
            $connectionName
        );
    }

    protected function _initSelect()
    {
        parent::_initSelect();
    }
}