<?php

namespace LeanCommerce\ProductAudit\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{

    const ADMIN_RESOURCE = 'LeanCommerce_ProductAudit::logs';

    protected $resultPageFactory;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }

    public function execute()
    {

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('LeanCommerce_ProductAudit::logs');
        $resultPage->getConfig()->getTitle()->prepend(__('Product Change Log'));

        return $resultPage;

    }

}