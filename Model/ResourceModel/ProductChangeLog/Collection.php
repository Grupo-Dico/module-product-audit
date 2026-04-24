<?php

namespace LeanCommerce\ProductAudit\Model\ResourceModel\ProductChangeLog;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct()
    {
        $this->_init(
            \LeanCommerce\ProductAudit\Model\ProductChangeLog::class,
            \LeanCommerce\ProductAudit\Model\ResourceModel\ProductChangeLog::class
        );
    }
}