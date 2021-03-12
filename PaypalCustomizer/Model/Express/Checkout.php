<?php
namespace OpenTechiz\PaypalCustomizer\Model\Express;

use Magento\Customer\Model\AccountManagement;
use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;

/**
 * Class Checkout
 * @package OpenTechiz\PaypalCustomizer\Model
 */
class Checkout extends \Magento\Paypal\Model\Express\Checkout
{
    /**
     * @return \OpenTechiz\PaypalCustomizer\Model\QuoteService
     */
    protected function getQuoteService()
    {
        return ObjectManager::getInstance()->get(\OpenTechiz\PaypalCustomizer\Model\QuoteService::class);
    }

    /**
     * @return \Magento\Sales\Model\OrderFactory
     */
    protected function getOrderFactory()
    {
        return ObjectManager::getInstance()->get(\Magento\Sales\Model\OrderFactory::class);
    }

    /**
     * @param null $shippingMethodCode
     */
    public function createOrder($shippingMethodCode = null)
    {
        if ($shippingMethodCode) {
            $this->updateShippingMethod($shippingMethodCode);
        }

        if ($this->getCheckoutMethod() == \Magento\Checkout\Model\Type\Onepage::METHOD_GUEST) {
            $this->prepareGuestQuote();
        }

        $this->ignoreAddressValidation();
        $this->_quote->collectTotals();
        $order = $this->getQuoteService()->createOrder($this->_quote, false);

        if (!$order) {
            return;
        }
        $this->_checkoutSession->setLastQuoteId($this->_quote->getId())
            ->setLastOrderId($order->getId())
            ->setLastRealOrderId($order->getIncrementId());
        $this->_order = $order;
    }

    /**
     * Place the order when customer returned from PayPal until this moment all quote data must be valid.
     *
     * @param string $token
     * @param string|null $shippingMethodCode
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function place($token, $shippingMethodCode = null)
    {
        if (!$this->allowCreateOrderBeforePay()) {
            parent::place($token, $shippingMethodCode);
            return;
        }
        if ($shippingMethodCode) {
            $this->updateShippingMethod($shippingMethodCode);
        }

        if ($this->getCheckoutMethod() == \Magento\Checkout\Model\Type\Onepage::METHOD_GUEST) {
            $this->prepareGuestQuote();
        }

        $this->ignoreAddressValidation();
        $this->_quote->collectTotals();

        $order = null;
        if ($orderId = $this->_checkoutSession->getLastOrderId()) {
            $order = $this->getOrderFactory()->create()->load($orderId);
        }
        if ($order) {
            $this->getQuoteService()->updatePlaceOrder($this->_quote, $order);
        } else {
            $order = $this->quoteManagement->submit($this->_quote);
        }

        if (!$order) {
            return;
        }

        // commence redirecting to finish payment, if paypal requires it
        if ($order->getPayment()->getAdditionalInformation(self::PAYMENT_INFO_TRANSPORT_REDIRECT)) {
            $this->_redirectUrl = $this->_config->getExpressCheckoutCompleteUrl($token);
        }

        switch ($order->getState()) {
            // even after placement paypal can disallow to authorize/capture, but will wait until bank transfers money
            case \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT:
                // TODO
                break;
            // regular placement, when everything is ok
            case \Magento\Sales\Model\Order::STATE_PROCESSING:
            case \Magento\Sales\Model\Order::STATE_COMPLETE:
            case \Magento\Sales\Model\Order::STATE_PAYMENT_REVIEW:
                $this->orderSender->send($order);
                $this->_checkoutSession->start();
                break;
            default:
                break;
        }
        $this->_order = $order;
    }

    /**
     * Make sure addresses will be saved without validation errors
     *
     * @return void
     */
    private function ignoreAddressValidation()
    {
        $this->_quote->getBillingAddress()->setShouldIgnoreValidation(true);
        if (!$this->_quote->getIsVirtual()) {
            $this->_quote->getShippingAddress()->setShouldIgnoreValidation(true);
            if (!$this->_config->getValue('requireBillingAddress')
                && !$this->_quote->getBillingAddress()->getEmail()
            ) {
                $this->_quote->getBillingAddress()->setSameAsBilling(1);
            }
        }
    }

    /**
     * @return bool
     */
    public function allowCreateOrderBeforePay()
    {
        return (bool)($this->canSkipOrderReviewStep() && $this->_storeManager->getStore()->getConfig("payment/paypal_express/create_order_before"));
    }
}