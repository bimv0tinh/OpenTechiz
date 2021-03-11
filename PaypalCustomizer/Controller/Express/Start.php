<?php
namespace OpenTechiz\PaypalCustomizer\Controller\Express;
/**
 * Class Start
 * @package OpenTechiz\PaypalCustomizer\Controller\Express
 */
class Start extends GetToken
{
    /**
     * Config mode type
     *
     * @var string
     */
    protected $_configType = \Magento\Paypal\Model\Config::class;

    /**
     * Config method type
     *
     * @var string
     */
    protected $_configMethod = \Magento\Paypal\Model\Config::METHOD_WPP_EXPRESS;

    /**
     * Checkout mode type
     *
     * @var string
     */
    protected $_checkoutType = \Magento\Paypal\Model\Express\Checkout::class;

    /**
     * Start Express Checkout by requesting initial token and dispatching customer to PayPal
     *
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function execute()
    {
        try {
            $token = $this->getToken();
            if ($token === null) {
                return;
            }

            $url = $this->_checkout->getRedirectUrl();
            if ($token && $url) {
                $this->_initToken($token);
                $this->getResponse()->setRedirect($url);

                return;
            }
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->messageManager->addExceptionMessage($e, $e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage(
                $e,
                __('We can\'t start Express Checkout.')
            );
        }

        $this->_redirect('checkout/cart');
    }
}