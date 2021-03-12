<?php
namespace OpenTechiz\PaypalCustomizer\Controller\Express;

use Magento\Framework\Exception\LocalizedException;

/**
 * Class GetToken
 * @package OpenTechiz\PaypalCustomizer\Controller\Express
 */
class GetToken extends \Magento\Paypal\Controller\Express\GetToken
{
    /**
     * @var \OpenTechiz\PaypalCustomizer\Model\Express\Checkout
     */
    protected $_checkout;

    /**
     * @return string|null
     * @throws LocalizedException
     */
    protected function getToken()
    {
        $token = parent::getToken();
        if ($token && $this->_checkout->getRedirectUrl()) {
            $orderId = $this->_getCheckoutSession()->getLastOrderId();
            /** @var \Magento\Sales\Model\Order $order */
            $order = $orderId ? $this->_orderFactory->create()->load($orderId) : false;
            if ($order && $order->getId() && $order->getQuoteId() == $this->_getCheckoutSession()->getQuoteId()) {
                $order->cancel()->save();
                $this->_getCheckoutSession()
                    ->unsLastQuoteId()
                    ->unsLastSuccessQuoteId()
                    ->unsLastOrderId()
                    ->unsLastRealOrderId();
            }
            if ($this->_checkout->allowCreateOrderBeforePay()) {
                $this->_checkout->createOrder();
            }
        }
        return $token;
    }
}