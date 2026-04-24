<?php

namespace LeanCommerce\ProductAudit\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ProductChangeLog extends AbstractDb
{
    protected function _construct()
    {
        $this->_init('leancommerce_product_change_log', 'log_id');
    }
}