<?php

namespace Sequra\Core\Observer;

use Exception;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Sequra\Core\Controller\Webhook\Index as WebhookController;
use SeQura\Core\BusinessLogic\AdminAPI\OrderManagement\Requests\OrderUpdateRequest;
use SeQura\Core\BusinessLogic\Domain\Order\Models\OrderRequest\Cart as SeQuraCart;
use SeQura\Core\Infrastructure\Logger\Logger;
use Sequra\Core\Model\Api\OrderUpdater;
use Sequra\Core\Model\Ui\ConfigProvider;
use Sequra\Core\Services\BusinessLogic\Utility\SeQuraTranslationProvider;

class OrderCancellationObserver implements ObserverInterface
{
    /**
     * @var SeQuraTranslationProvider
     */
    private $translationProvider;

    /**
     * @var OrderUpdater
     */
    private $orderUpdater;

    /**
     * @var OrderResource
     */
    private $orderResource;

    /**
     * @param SeQuraTranslationProvider $translationProvider
     * @param OrderUpdater $orderUpdater
     * @param OrderResource $orderResource
     */
    public function __construct(
        SeQuraTranslationProvider $translationProvider,
        OrderUpdater $orderUpdater,
        OrderResource $orderResource
    ) {
        $this->translationProvider = $translationProvider;
        $this->orderUpdater = $orderUpdater;
        $this->orderResource = $orderResource;
    }

    /**
     * @inheritDoc
     *
     * @throws LocalizedException
     */
    public function execute(Observer $observer): void
    {
        $orderData = $observer->getData('order');
        if (WebhookController::isWebhookProcessing() || ! $orderData instanceof Order ||
            ($orderData->getState() !== Order::STATE_CANCELED && $orderData->getStatus() !== Order::STATE_CANCELED) ||
            !$orderData->getPayment() ||
            $orderData->getPayment()->getMethod() !== ConfigProvider::CODE) {
            return;
        }

        $previous = $this->getPreviousStateAndStatus($orderData);
        if ($this->isCanceled($orderData, $previous['state'], $previous['status'])) {
            return;
        }

        try {
            $this->handleCancellation($orderData, $previous['status']);
        } catch (Exception $e) {
            $this->handleCancellationError($e);
        }
    }

    /**
     * Handles order cancellation.
     *
     * @param Order $orderData
     * @param string|null $previousStatus Status stored for the order before this save.
     *
     * @return void
     *
     * @throws Exception
     */
    private function handleCancellation(Order $orderData, ?string $previousStatus): void
    {
        if ($previousStatus === Order::STATE_PAYMENT_REVIEW) {
            throw new LocalizedException($this->translationProvider->translate('sequra.error.cannotCancel'));
        }

        $this->orderUpdater->update(
            (string)$orderData->getStoreId(),
            new OrderUpdateRequest(
                $orderData->getIncrementId(),
                new SeQuraCart($orderData->getOrderCurrencyCode() ?? ''),
                new SeQuraCart($orderData->getOrderCurrencyCode() ?? '')
            )
        );
    }

    /**
     * Checks whether the order is in the canceled state or has one of the statuses assigned to that state.
     *
     * @param Order $orderData
     * @param string|null $state
     * @param string|null $status
     *
     * @return bool
     */
    private function isCanceled(Order $orderData, ?string $state, ?string $status): bool
    {
        return $state === Order::STATE_CANCELED || $status === Order::STATE_CANCELED ||
            in_array($status, $orderData->getConfig()->getStateStatuses(Order::STATE_CANCELED, false), true);
    }

    /**
     * Returns the state and the status stored for the order before this save.
     *
     * Reads them from the database instead of the original data: orders saved through the REST API have no original
     * data, and the original data is not refreshed when the same order is saved twice in one request.
     *
     * @param Order $orderData
     *
     * @return array{state: string|null, status: string|null}
     */
    private function getPreviousStateAndStatus(Order $orderData): array
    {
        $previous = ['state' => null, 'status' => null];
        $orderId = $orderData->getId();
        if (!is_numeric($orderId)) {
            return $previous;
        }

        $connection = $this->orderResource->getConnection();
        if (!$connection) {
            return $previous;
        }

        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->orderResource->getMainTable(), ['state', 'status'])
                ->where($this->orderResource->getIdFieldName() . ' = ?', (int)$orderId)
        );

        if (!is_array($row)) {
            return $previous;
        }

        return [
            'state' => isset($row['state']) && is_string($row['state']) ? $row['state'] : null,
            'status' => isset($row['status']) && is_string($row['status']) ? $row['status'] : null,
        ];
    }

    /**
     * Handles the order cancellation errors.
     *
     * @param Exception $e
     *
     * @return void
     *
     * @throws LocalizedException
     */
    private function handleCancellationError(Exception $e): void
    {
        Logger::logError('Order synchronization for cancellation failed. ' . $e->getMessage(), 'Integration');

        if ($e instanceof LocalizedException) {
            throw $e;
        }
    }
}
