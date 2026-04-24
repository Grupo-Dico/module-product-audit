<?php

namespace LeanCommerce\ProductAudit\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\Area;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Psr\Log\LoggerInterface;

class AuditContextResolver
{
    /**
     * @var AdminSession
     */
    private $adminSession;

    /**
     * @var State
     */
    private $appState;

    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var UserContextInterface
     */
    private $userContext;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        AdminSession $adminSession,
        State $appState,
        RequestInterface $request,
        UserContextInterface $userContext,
        LoggerInterface $logger
    ) {
        $this->adminSession = $adminSession;
        $this->appState = $appState;
        $this->request = $request;
        $this->userContext = $userContext;
        $this->logger = $logger;
    }

    public function resolve(string $fallbackOriginType = 'system', string $fallbackOriginDetail = 'unknown'): array
    {
        $area = $this->getArea();

        if ($area === Area::AREA_ADMINHTML) {
            try {
                $user = $this->adminSession->getUser();
                if ($user && $user->getId()) {
                    return [
                        'origin_type' => 'admin',
                        'origin_detail' => (string)$user->getUserName(),
                        'area' => $area,
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->debug('Unable to resolve admin audit context', [
                    'message' => $e->getMessage()
                ]);
            }

            return [
                'origin_type' => 'admin',
                'origin_detail' => 'unknown_admin',
                'area' => $area,
            ];
        }

        if ($area === Area::AREA_CRONTAB) {
            return [
                'origin_type' => 'cron',
                'origin_detail' => 'crontab',
                'area' => $area,
            ];
        }

        if ($area === Area::AREA_WEBAPI_REST || $area === Area::AREA_WEBAPI_SOAP) {
            $userType = (int)$this->userContext->getUserType();
            $userId = (int)$this->userContext->getUserId();

            $detail = 'webapi_unknown';
            if ($userType === UserContextInterface::USER_TYPE_INTEGRATION) {
                $detail = 'integration:' . $userId;
            } elseif ($userType === UserContextInterface::USER_TYPE_ADMIN) {
                $detail = 'admin_api:' . $userId;
            } elseif ($userType === UserContextInterface::USER_TYPE_CUSTOMER) {
                $detail = 'customer_api:' . $userId;
            } elseif ($userType === UserContextInterface::USER_TYPE_GUEST) {
                $detail = 'guest_api';
            }

            return [
                'origin_type' => $area === Area::AREA_WEBAPI_REST ? 'webapi_rest' : 'webapi_soap',
                'origin_detail' => $detail,
                'area' => $area,
            ];
        }

        if (PHP_SAPI === 'cli') {
            $scriptName = isset($_SERVER['argv'][0]) ? basename((string)$_SERVER['argv'][0]) : 'cli';
            return [
                'origin_type' => 'cli',
                'origin_detail' => $scriptName,
                'area' => $area,
            ];
        }

        $fullActionName = '';
        try {
            $fullActionName = (string)$this->request->getFullActionName();
        } catch (\Throwable $e) {
            $fullActionName = '';
        }

        return [
            'origin_type' => $fallbackOriginType,
            'origin_detail' => $fullActionName !== '' ? $fullActionName : $fallbackOriginDetail,
            'area' => $area,
        ];
    }

    private function getArea(): string
    {
        try {
            return (string)$this->appState->getAreaCode();
        } catch (\Throwable $e) {
            return 'global';
        }
    }
}