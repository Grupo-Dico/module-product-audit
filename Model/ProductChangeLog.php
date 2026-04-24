<?php

namespace LeanCommerce\ProductAudit\Model;

use Magento\Framework\Model\AbstractModel;

class ProductChangeLog extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(\LeanCommerce\ProductAudit\Model\ResourceModel\ProductChangeLog::class);
    }
}