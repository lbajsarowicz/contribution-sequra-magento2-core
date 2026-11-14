<?php

namespace Sequra\Core\Test\Unit\Observer;

use Exception;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use SeQura\Core\BusinessLogic\AdminAPI\OrderManagement\Requests\OrderUpdateRequest;
use SeQura\Core\BusinessLogic\Domain\Order\Models\OrderUpdateData;
use SeQura\Core\BusinessLogic\Domain\Order\Models\SeQuraOrder;
use SeQura\Core\Infrastructure\Logger\Logger;
use Sequra\Core\Controller\Webhook\Index as WebhookController;
use Sequra\Core\Model\Api\OrderUpdater;
use Sequra\Core\Model\Ui\ConfigProvider;
use Sequra\Core\Observer\OrderCancellationObserver;
use Sequra\Core\Services\BusinessLogic\Utility\SeQuraTranslationProvider;

/**
 * Class OrderCancellationObserverTest
 *
 * When a SeQura order is cancelled in Magento the cancellation has to reach SeQura, unless the order
 * was in payment review, where SeQura still has to decide and the cancellation is refused.
 *
 * The observer used to act only on Magento\Sales\Model\Order\Interceptor, so on stores where a module
 * declares a preference for Magento\Sales\Model\Order it never ran. The orders below are mocks of the
 * base class, which are not that interceptor either.
 */
class OrderCancellationObserverTest extends TestCase
{
    /**
     * @var MockObject&OrderUpdater
     */
    private $orderUpdater;

    /**
     * @var MockObject&AdapterInterface
     */
    private $connection;

    /**
     * @var string[]
     */
    private $loggedMessages = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->orderUpdater = $this->createMock(OrderUpdater::class);
        $this->replaceLogger();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->setStatic(WebhookController::class, 'isWebhookProcessing', false);
        Logger::resetInstance();
    }

    /**
     * An order cancelled from any status other than payment review is cancelled in SeQura with empty carts.
     *
     * @return void
     */
    public function testSendsTheCancellationToSequra(): void
    {
        $observer = $this->observer(Order::STATE_PENDING_PAYMENT, Order::STATE_PENDING_PAYMENT);

        $this->connection->expects($this->once())->method('fetchRow');
        $this->orderUpdater->expects($this->once())
            ->method('update')
            ->with('1', $this->callback(static function (OrderUpdateRequest $request) {
                /** @var OrderUpdateData $data */
                $data = $request->transformToDomainModel();

                return $data->getOrderShopReference() === '100000001'
                    && $data->getShippedCart() !== null && $data->getShippedCart()->getItems() === []
                    && $data->getUnshippedCart() !== null && $data->getUnshippedCart()->getItems() === [];
            }))
            ->willReturn($this->createStub(SeQuraOrder::class));

        $observer->execute($this->event($this->cancelledOrder()));
    }

    /**
     * An order cancelled while SeQura has it in payment review is refused and nothing is sent.
     *
     * @return void
     */
    public function testRefusesToCancelAnOrderInPaymentReview(): void
    {
        $observer = $this->observer(Order::STATE_NEW, Order::STATE_PAYMENT_REVIEW);

        $this->orderUpdater->expects($this->never())->method('update');

        $this->expectException(LocalizedException::class);

        $observer->execute($this->event($this->cancelledOrder()));
    }

    /**
     * Saving an order that was already cancelled (a comment, an ERP sync) does not send it again.
     *
     * @return void
     */
    public function testIgnoresAnOrderThatWasAlreadyCancelled(): void
    {
        $observer = $this->observer(Order::STATE_CANCELED, Order::STATE_CANCELED);

        $this->orderUpdater->expects($this->never())->method('update');

        $observer->execute($this->event($this->cancelledOrder()));
    }

    /**
     * The status Magento gives a cancelled order is the default status of the canceled state, which the merchant
     * can replace with a custom one. The cancellation still has to reach SeQura.
     *
     * @return void
     */
    public function testSendsTheCancellationWhenTheCanceledStateHasACustomStatus(): void
    {
        $observer = $this->observer(Order::STATE_PENDING_PAYMENT, Order::STATE_PENDING_PAYMENT);

        $this->orderUpdater->expects($this->once())
            ->method('update')
            ->willReturn($this->createStub(SeQuraOrder::class));

        $observer->execute($this->event($this->order(Order::STATE_CANCELED, 'canceled_by_merchant')));
    }

    /**
     * Saving again an order that was cancelled with a custom status does not send it again.
     *
     * @return void
     */
    public function testIgnoresAnOrderThatWasAlreadyCancelledWithACustomStatus(): void
    {
        $observer = $this->observer(Order::STATE_CANCELED, 'canceled_by_merchant');

        $this->orderUpdater->expects($this->never())->method('update');

        $observer->execute($this->event($this->order(Order::STATE_CANCELED, 'canceled_by_merchant')));
    }

    /**
     * An order whose status is set to canceled while the state is not changed, as some imports and REST clients do,
     * is cancelled in SeQura too.
     *
     * @return void
     */
    public function testSendsTheCancellationWhenOnlyTheStatusIsSetToCanceled(): void
    {
        $observer = $this->observer(Order::STATE_PENDING_PAYMENT, Order::STATE_PENDING_PAYMENT);

        $this->orderUpdater->expects($this->once())
            ->method('update')
            ->willReturn($this->createStub(SeQuraOrder::class));

        $observer->execute($this->event($this->order(Order::STATE_PENDING_PAYMENT, Order::STATE_CANCELED)));
    }

    /**
     * An order already stored in the canceled state is not sent again, whatever its stored status.
     *
     * @return void
     */
    public function testIgnoresAnOrderStoredInTheCanceledStateWithAnotherStatus(): void
    {
        $observer = $this->observer(Order::STATE_CANCELED, 'pending');

        $this->orderUpdater->expects($this->never())->method('update');

        $observer->execute($this->event($this->cancelledOrder()));
    }

    /**
     * An order already stored with a status of the canceled state is not sent again, whatever its stored state.
     *
     * @return void
     */
    public function testIgnoresAnOrderStoredWithACanceledStatusInAnotherState(): void
    {
        $observer = $this->observer(Order::STATE_PENDING_PAYMENT, 'canceled_by_merchant');

        $this->orderUpdater->expects($this->never())->method('update');

        $observer->execute($this->event($this->cancelledOrder()));
    }

    /**
     * Saving an order of another payment method, even a cancelled one, reads neither the canceled statuses nor the
     * stored order.
     *
     * @return void
     */
    public function testDoesNotLookUpStatusesOrTheStoredOrderForOtherPaymentMethods(): void
    {
        $observer = $this->observer(Order::STATE_PENDING_PAYMENT, Order::STATE_PENDING_PAYMENT);
        $orderConfig = $this->createMock(OrderConfig::class);

        $orderConfig->expects($this->never())->method('getStateStatuses');
        $this->connection->expects($this->never())->method('fetchRow');
        $this->orderUpdater->expects($this->never())->method('update');

        $observer->execute(
            $this->event($this->order(Order::STATE_CANCELED, 'canceled_by_merchant', 'checkmo', $orderConfig))
        );
    }

    /**
     * Saving a SeQura order that is not being cancelled reads neither the canceled statuses nor the stored order.
     *
     * @return void
     */
    public function testDoesNotLookUpStatusesOrTheStoredOrderForAnOrderThatIsNotCancelled(): void
    {
        $observer = $this->observer(Order::STATE_NEW, 'pending');
        $orderConfig = $this->createMock(OrderConfig::class);

        $orderConfig->expects($this->never())->method('getStateStatuses');
        $this->connection->expects($this->never())->method('fetchRow');
        $this->orderUpdater->expects($this->never())->method('update');

        $order = $this->order(Order::STATE_PROCESSING, Order::STATE_PROCESSING, ConfigProvider::CODE, $orderConfig);

        $observer->execute($this->event($order));
    }

    /**
     * Orders paid with another method are left alone, without reading their previous status.
     *
     * @return void
     */
    public function testIgnoresOtherPaymentMethods(): void
    {
        $observer = $this->observer(Order::STATE_PENDING_PAYMENT, Order::STATE_PENDING_PAYMENT);

        $this->connection->expects($this->never())->method('fetchRow');
        $this->orderUpdater->expects($this->never())->method('update');

        $observer->execute($this->event($this->cancelledOrder('checkmo')));
    }

    /**
     * A cancellation that comes from a SeQura webhook is not sent back to SeQura.
     *
     * @return void
     */
    public function testSendsNothingWhileProcessingAWebhook(): void
    {
        $observer = $this->observer(Order::STATE_PENDING_PAYMENT, Order::STATE_PENDING_PAYMENT);
        $this->setStatic(WebhookController::class, 'isWebhookProcessing', true);

        $this->connection->expects($this->never())->method('fetchRow');
        $this->orderUpdater->expects($this->never())->method('update');

        $observer->execute($this->event($this->cancelledOrder()));
    }

    /**
     * When SeQura cannot be reached the error is logged and the cancellation goes on in Magento.
     *
     * @return void
     */
    public function testLogsAndKeepsTheCancellationWhenSequraFails(): void
    {
        $observer = $this->observer(Order::STATE_PENDING_PAYMENT, Order::STATE_PENDING_PAYMENT);

        $this->orderUpdater->method('update')->willThrowException(new Exception('timeout'));

        $observer->execute($this->event($this->cancelledOrder()));

        $this->assertSame(['Order synchronization for cancellation failed. timeout'], $this->loggedMessages);
    }

    /**
     * @param string $previousState State stored in sales_order before this save.
     * @param string $previousStatus Status stored in sales_order before this save.
     *
     * @return OrderCancellationObserver
     */
    private function observer(string $previousState, string $previousStatus): OrderCancellationObserver
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn(['state' => $previousState, 'status' => $previousStatus]);

        $resource = $this->createMock(OrderResource::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getMainTable')->willReturn('sales_order');
        $resource->method('getIdFieldName')->willReturn('entity_id');

        $translationProvider = $this->createMock(SeQuraTranslationProvider::class);
        $translationProvider->method('translate')->willReturn(new Phrase('sequra.error.cannotCancel'));

        return new OrderCancellationObserver($translationProvider, $this->orderUpdater, $resource);
    }

    /**
     * @param string $paymentMethod
     *
     * @return MockObject&Order
     */
    private function cancelledOrder(string $paymentMethod = ConfigProvider::CODE): Order
    {
        return $this->order(Order::STATE_CANCELED, Order::STATE_CANCELED, $paymentMethod);
    }

    /**
     * @param string $state
     * @param string $status
     * @param string $paymentMethod
     * @param OrderConfig|null $orderConfig
     *
     * @return MockObject&Order
     */
    private function order(
        string $state,
        string $status,
        string $paymentMethod = ConfigProvider::CODE,
        ?OrderConfig $orderConfig = null
    ): Order {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn($paymentMethod);

        if ($orderConfig === null) {
            $orderConfig = $this->createStub(OrderConfig::class);
            $orderConfig->method('getStateStatuses')->willReturn([Order::STATE_CANCELED, 'canceled_by_merchant']);
        }

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getState',
                'getStatus',
                'getConfig',
                'getId',
                'getPayment',
                'getStoreId',
                'getIncrementId',
                'getOrderCurrencyCode'
            ])
            ->getMock();
        $order->method('getState')->willReturn($state);
        $order->method('getStatus')->willReturn($status);
        $order->method('getConfig')->willReturn($orderConfig);
        $order->method('getId')->willReturn('42');
        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('100000001');
        $order->method('getOrderCurrencyCode')->willReturn('EUR');

        return $order;
    }

    /**
     * @param Order $order
     *
     * @return Observer
     */
    private function event(Order $order): Observer
    {
        return new Observer(['order' => $order]);
    }

    /**
     * Replaces the integration-core logger, which needs the shop logger and configuration services,
     * with one that keeps the messages for the assertions.
     *
     * @return void
     */
    private function replaceLogger(): void
    {
        $messages = &$this->loggedMessages;
        $logger = new class ($messages) extends Logger {
            /**
             * @var string[]
             */
            private $messages;

            /**
             * @param string[] $messages
             */
            public function __construct(array &$messages)
            {
                $this->messages = &$messages;
            }

            /**
             * @param int $level
             * @param string $message
             * @param string $component
             * @param mixed[] $context
             *
             * @return void
             */
            protected function logMessage(int $level, string $message, string $component, array $context = []): void
            {
                $this->messages[] = $message;
            }
        };

        $this->setStatic(Logger::class, 'instance', $logger);
    }

    /**
     * @param string $class
     * @param string $property
     * @param mixed $value
     *
     * @return void
     */
    private function setStatic(string $class, string $property, $value): void
    {
        $reflection = new ReflectionProperty($class, $property);
        $reflection->setAccessible(true);
        $reflection->setValue(null, $value);
    }
}
