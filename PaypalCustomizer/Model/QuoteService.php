<?php
namespace OpenTechiz\PaypalCustomizer\Model;

use Magento\Framework\App\ObjectManager;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote as QuoteEntity;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * Class QuoteService
 * @package OpenTechiz\PaypalCustomizer\Model
 */
class QuoteService extends \Magento\Quote\Model\QuoteManagement
{

    /**
     * @var \Magento\Customer\Api\AddressRepositoryInterface
     */
    private $addressRepository;

    /**
     * @var array
     */
    private $addressesToSync = [];

    /**
     * @param Quote $quote
     * @param bool $place
     * @return \Magento\Framework\Model\AbstractExtensibleModel|\Magento\Sales\Api\Data\OrderInterface|object|null
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Validator\Exception
     */
    public function createOrder(Quote $quote)
    {
        if (!$quote->getAllVisibleItems()) {
            $quote->setIsActive(false);
            return null;
        }
        $order = $this->orderFactory->create();
        $this->_submitQuoteOrder($quote, $order);
        $order->setState(Order::STATE_PENDING_PAYMENT)->setStatus(Order::STATE_PENDING_PAYMENT);
        $this->_saveQuoteOrder($quote, $order);
        return $order;
    }

    /**
     * @param Quote $quote
     * @param  Order $order
     * @return Order $order
     */
    public function updatePlaceOrder(Quote $quote, $order)
    {
        if (!$quote->getAllVisibleItems()) {
            $quote->setIsActive(false);
            return null;
        }
        $this->_submitQuoteOrder($quote, $order);
        $this->_placeQuoteOrder($quote, $order);
        return $order;
    }

    /**
     * @return OrderRepositoryInterface
     */
    public function getOrderRepository()
    {
        return ObjectManager::getInstance()->create(OrderRepositoryInterface::class);
    }

    /**
     * Submit quote
     *
     * @param Quote $quote
     * @param array $orderData
     * @param Order $order
     * @param bool $place
     * @return \Magento\Framework\Model\AbstractExtensibleModel|\Magento\Sales\Api\Data\OrderInterface|object
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Validator\Exception
     */
    protected function _submitQuoteOrder(QuoteEntity $quote, $order, $orderData = [])
    {
        $this->quoteValidator->validateBeforeSubmit($quote);
        if (!$quote->getCustomerIsGuest()) {
            if ($quote->getCustomerId()) {
                $this->_prepareCustomerQuote($quote);
            }
            $this->customerManagement->populateCustomerInfo($quote);
        }
        $addresses = [];
        if ($order->getIncrementId() && $order->getIncrementId() != $quote->getReservedOrderId()) {
            $quote->reserveOrderId();
        }
        if ($quote->isVirtual()) {
            $this->dataObjectHelper->mergeDataObjects(
                \Magento\Sales\Api\Data\OrderInterface::class,
                $order,
                $this->quoteAddressToOrder->convert($quote->getBillingAddress(), $orderData)
            );
        } else {
            $this->dataObjectHelper->mergeDataObjects(
                \Magento\Sales\Api\Data\OrderInterface::class,
                $order,
                $this->quoteAddressToOrder->convert($quote->getShippingAddress(), $orderData)
            );
            $shippingAddress = $this->quoteAddressToOrderAddress->convert(
                $quote->getShippingAddress(),
                [
                    'address_type' => 'shipping',
                    'email' => $quote->getCustomerEmail()
                ]
            );
            $shippingAddress->setData('quote_address_id', $quote->getShippingAddress()->getId());
            $addresses[] = $shippingAddress;
            $order->setShippingAddress($shippingAddress);
            $order->setShippingMethod($quote->getShippingAddress()->getShippingMethod());
        }
        $billingAddress = $this->quoteAddressToOrderAddress->convert(
            $quote->getBillingAddress(),
            [
                'address_type' => 'billing',
                'email' => $quote->getCustomerEmail()
            ]
        );
        $billingAddress->setData('quote_address_id', $quote->getBillingAddress()->getId());
        $addresses[] = $billingAddress;
        $order->setBillingAddress($billingAddress);
        $order->setAddresses($addresses);

        $payment = $this->quotePaymentToOrderPayment->convert($quote->getPayment());
        if ($order->getPayment() && $order->getPayment()->getId()) {
            $payment->setId($order->getPayment()->getId());
        }
        $order->setPayment($payment);
        $orderItems = [];
        foreach ($this->resolveItems($quote) as $orderItem) {
            /** @var \Magento\Sales\Model\Order\Item $orderItem */
            $existItem = $order->getItemByQuoteItemId($orderItem->getQuoteItemId());
            if ($existItem) {
                $orderItem->setId($existItem->getId());
            }
            $orderItems[] = $orderItem;
        }
        $order->setItems($orderItems);
        if ($quote->getCustomer()) {
            $order->setCustomerId($quote->getCustomer()->getId());
        }
        $order->setQuoteId($quote->getId());
        $order->setCustomerEmail($quote->getCustomerEmail());
        $order->setCustomerFirstname($quote->getCustomerFirstname());
        $order->setCustomerMiddlename($quote->getCustomerMiddlename());
        $order->setCustomerLastname($quote->getCustomerLastname());
        return $order;
    }

    /**
     * @param $quote
     * @param $order
     * @throws \Exception
     */
    protected function _saveQuoteOrder($quote, $order)
    {
        try {
            $this->getOrderRepository()->save($order);
            $this->quoteRepository->save($quote);
        } catch (\Exception $e) {
            $this->rollbackAddresses($quote, $order, $e);
            throw $e;
        }
    }

    /**
     * @param QuoteEntity $quote
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \Exception $e
     * @throws \Exception
     */
    protected function _placeQuoteOrder($quote, $order)
    {
        $this->eventManager->dispatch(
            'sales_model_service_quote_submit_before',
            [
                'order' => $order,
                'quote' => $quote
            ]
        );
        try {
            $order = $this->orderManagement->place($order);
            $quote->setIsActive(false);
            $this->eventManager->dispatch(
                'sales_model_service_quote_submit_success',
                [
                    'order' => $order,
                    'quote' => $quote
                ]
            );
            $this->quoteRepository->save($quote);
        } catch (\Exception $e) {
            $this->rollbackAddresses($quote, $order, $e);
            throw $e;
        }
    }

    /**
     * Remove related to order and quote addresses and submit exception to further processing.
     *
     * @param Quote $quote
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \Exception $e
     * @throws \Exception
     * @return void
     */
    private function rollbackAddresses(
        QuoteEntity $quote,
        \Magento\Sales\Api\Data\OrderInterface $order,
        \Exception $e
    ) {
        try {
            if (!empty($this->addressesToSync)) {
                foreach ($this->addressesToSync as $addressId) {
                    $this->addressRepository->deleteById($addressId);
                }
            }
            $this->eventManager->dispatch(
                'sales_model_service_quote_submit_failure',
                [
                    'order' => $order,
                    'quote' => $quote,
                    'exception' => $e,
                ]
            );
            // phpcs:ignore Magento2.Exceptions.ThrowCatch
        } catch (\Exception $consecutiveException) {
            $message = sprintf(
                "An exception occurred on 'sales_model_service_quote_submit_failure' event: %s",
                $consecutiveException->getMessage()
            );
            // phpcs:ignore Magento2.Exceptions.DirectThrow
            throw new \Exception($message, 0, $e);
        }
    }

}