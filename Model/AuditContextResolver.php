<?php

namespace LeanCommerce\ProductAudit\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Backend\Model\Auth\Session as AdminSession;
use Magento\Framework\App\Area;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Psr\Log\LoggerInterface;

class AuditContextResolver
{
    /** @var AdminSession */
    private $adminSession;

    /** @var State */
    private $appState;

    /** @var RequestInterface */
    private $request;

    /** @var UserContextInterface */
    private $userContext;

    /** @var LoggerInterface */
    private $logger;

    /** @var ResourceConnection */
    private $resourceConnection;

    public function __construct(
        AdminSession $adminSession,
        State $appState,
        RequestInterface $request,
        UserContextInterface $userContext,
        LoggerInterface $logger,
        ResourceConnection $resourceConnection
    ) {
        $this->adminSession = $adminSession;
        $this->appState = $appState;
        $this->request = $request;
        $this->userContext = $userContext;
        $this->logger = $logger;
        $this->resourceConnection = $resourceConnection;
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
                'origin_detail' => $this->buildCliDetail('crontab'),
                'area' => $area,
            ];
        }

        if ($area === Area::AREA_WEBAPI_REST || $area === Area::AREA_WEBAPI_SOAP) {
            return $this->resolveWebApiContext($area);
        }

        if (PHP_SAPI === 'cli') {
            return [
                'origin_type' => 'cli',
                'origin_detail' => $this->buildCliDetail('cli'),
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
            'origin_detail' => $this->limitDetail($fullActionName !== '' ? $fullActionName : $fallbackOriginDetail),
            'area' => $area,
        ];
    }

    private function resolveWebApiContext(string $area): array
    {
        $userType = 0;
        $userId = 0;

        try {
            $userType = (int)$this->userContext->getUserType();
            $userId = (int)$this->userContext->getUserId();
        } catch (\Throwable $e) {
            $this->logger->debug('Unable to resolve webapi user context', [
                'message' => $e->getMessage()
            ]);
        }

        $originType = $area === Area::AREA_WEBAPI_REST ? 'api_rest' : 'api_soap';
        $actor = 'unknown';

        if ($userType === UserContextInterface::USER_TYPE_INTEGRATION) {
            $integrationName = $this->getIntegrationName($userId);
            $actor = 'external_integration:' . $userId;
            if ($integrationName !== '') {
                $actor .= ':' . $integrationName;
            }
        } elseif ($userType === UserContextInterface::USER_TYPE_ADMIN) {
            $actor = 'admin_token:' . $userId;
        } elseif ($userType === UserContextInterface::USER_TYPE_CUSTOMER) {
            $actor = 'customer_token:' . $userId;
        } elseif ($userType === UserContextInterface::USER_TYPE_GUEST) {
            $actor = 'guest';
        }

        $detailParts = [
            $actor,
            $this->getRequestMethod(),
            $this->getRequestPath(),
            $this->getClientIp()
        ];

        return [
            'origin_type' => $originType,
            'origin_detail' => $this->limitDetail(implode(' | ', array_filter($detailParts))),
            'area' => $area,
        ];
    }

    private function getIntegrationName(int $consumerId): string
    {
        if ($consumerId <= 0) {
            return '';
        }

        try {
            $connection = $this->resourceConnection->getConnection();
            $table = $this->resourceConnection->getTableName('oauth_consumer');

            if (!$connection->isTableExists($table)) {
                return '';
            }

            $name = $connection->fetchOne(
                $connection->select()
                    ->from($table, ['name'])
                    ->where('entity_id = ?', $consumerId)
                    ->limit(1)
            );

            return $name ? (string)$name : '';
        } catch (\Throwable $e) {
            $this->logger->debug('Unable to resolve integration name for product audit', [
                'consumer_id' => $consumerId,
                'message' => $e->getMessage()
            ]);
            return '';
        }
    }

    private function getRequestMethod(): string
    {
        try {
            return (string)$this->request->getMethod();
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function getRequestPath(): string
    {
        try {
            $path = (string)$this->request->getRequestUri();
            if ($path === '') {
                $path = (string)$this->request->getPathInfo();
            }
            return $path;
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function getClientIp(): string
    {
        try {
            return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function buildCliDetail(string $default): string
    {
        $scriptName = isset($_SERVER['argv'][0]) ? basename((string)$_SERVER['argv'][0]) : $default;
        $command = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? implode(' ', $_SERVER['argv']) : $scriptName;

        return $this->limitDetail($command);
    }

    private function limitDetail(string $detail): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($detail, 0, 255);
        }

        return substr($detail, 0, 255);
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
