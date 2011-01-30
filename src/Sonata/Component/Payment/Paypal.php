<?php

/*
 * This file is part of the Sonata package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\Component\Payment;

use Application\PaymentBundle\Entity\Transaction;
use Sonata\Component\Order\OrderInterface;

/**
 * A free delivery method, used this only for testing
 *
 */
class Paypal extends BasePaypal
{

    // PayPal pending reason
    // From PP_OrderManagement_IntegrationGuide, March 2008 p58
    const PENDING_REASON_ADRESS = 'address';
    const PENDING_REASON_AUTHORIZATION = 'authorization';
    const PENDING_REASON_ECHECK = 'echeck';
    const PENDING_REASON_INTL = 'intl';
    const PENDING_REASON_MULTICURRENCY = 'multi-currency';
    const PENDING_REASON_UNILATERAL = 'unilateral';
    const PENDING_REASON_UPGRADE = 'upgrade';
    const PENDING_REASON_VERIFY = 'verify';
    const PENDING_REASON_OTHER = 'other';

    /**
     *
     * @param  $basket
     * @param  $user
     * @return Response object
     */
    public function callbank($order)
    {

        $params = array(
            'order' => $order->getReference(),
            'bank'  => $this->getCode(),
            'check' => $this->generateUrlCheck($order),
        );

        $fields = array(
            // paypal specific
            'cmd'           => '_xclick',
            'charset'       => 'utf-8',
            'business'      => $this->getOption('account'),
            'cert_id'       => $this->getOption('cert_id'),
            'no_shipping'   => '1', // client cannot add shipping address
            'lc'            => 'EN', // user interface language
            'no_note'       => '1', // no comment on paypal

            // invoice information
            'invoice'       => $order->getReference(),
            'amount'        => $order->getTotalInc(),
            'currency_code' => $order->getCurrency(),
            'item_name'     => 'Order ' . $order->getReference(),
            'bn'            => 'Sonata/1.0', // Assign Build Notation for PayPal Support

            // user information, for prepopulated form (paypal side)
            'first_name'    => $order->getBillingName(),
            'last_name'     => '',
            'address1'      => $order->getBillingAddress1(),
            'address2'      => $order->getBillingAddress2(),
            'city'          => $order->getBillingCity(),
            'zip'           => $order->getBillingPostcode(),
            'country'       => $order->getBillingCountryCode(),

            // Callback information
            'custom'        => $this->generateUrlCheck($order),
            'notify_url'    => $this->router->generate($this->getOption('url_callback'), $params, true),

            // user link
            'cancel_return' => $this->router->generate($this->getOption('url_return_ko'), $params, true),
            'return'        => $this->router->generate($this->getOption('url_return_ok'), $params, true),

        );

        if ($this->getOption('debug', false)) {

            $html = '<html><body>' . "\n";
        }
        else {
            $html = '<html><body onload="document.getElementById(\'submit_button\').disabled = \'disabled\'; document.getElementById(\'formPaiement\').submit();">' . "\n";
        }

        $method = $this->getOption('method', 'encrypt');

        $html .= sprintf('<form action="%s" method="%s" id="formPaiement" >' . "\n", $this->getOption('url_action'), 'POST');
        $html .= '<input type="hidden" name="cmd" value="_s-xclick">' . "\n";
        $html .= sprintf('<input type="hidden" name="encrypted" value="%s" />', call_user_func(array($this, $method), $fields));

        $html .= '<p>' . $this->translator->trans('process_to_paiement_bank_page', array(), 'PaymentBundle') . '</p>';
        $html .= '<input type="submit" id="submit_button" value="' . $this->translator->trans('process_to_paiement_btn', array(), 'PaymentBundle') . '" />';
        $html .= '</form>';


        $html .= '</body></html>';

        if ($this->getOption('debug', false)) {
            echo "<!-- Encrypted Array : \n" . print_r($fields, 1) . "-->";
        }

        $response = new \Symfony\Component\HttpFoundation\Response($html, 200, array(
            'Content-Type' => 'text/html'
        ));
        $response->setPrivate(true);

        return $response;
    }

    /**
     *
     * From paypal documentation:
     *
     * 1. A customer payment or a refund triggers IPN. This payment can be via Website Payments
     * Standard FORMs or via the PayPal Web Services APIs for Express Checkout, MassPay, or
     * RefundTransaction. If the payment has a “Pending” status, you receive another IPN when
     * the payment clears, fails, or is denied.
     *
     * 2. PayPal posts HTML FORM variables to a program at a URL you specify. You can specify
     * this URL either in your Profile or with the notify_url variable on each transaction. This
     * post is the heart of IPN. Included in the notification is the customer’s payment information
     * (such as customer name, payment amount). All possible variables in IPN posts are detailed
     * in . When your server receives a notification, it must process the incoming data.
     *
     * 3. Your server must then validate the notification to ensure that it is legitimate.
     *
     *
     *
     * @return integer the order status
     */
    public function isCallbackValid($transaction)
    {
        $order          = $transaction->getOrder();

        if (!$this->isRequestValid($transaction)) {

            $transaction->setState(Transaction::STATE_KO);
            $transaction->setStatusCode(Transaction::STATUS_WRONG_CALLBACK);
            
            return false;
        }

        if ($order->isValidated()) {

            $transaction->setState(Transaction::STATE_KO);
            $transaction->setStatusCode(Transaction::STATUS_WRONG_CALLBACK);
            
            return false;
        }

        if ($transaction->get('payment_status') === 'Pending') {

            $transaction->setState(Transaction::STATE_OK);
            $transaction->setStatusCode(Transaction::STATUS_PENDING);

            return true;
        }

        if ($transaction->get('payment_status') === 'Completed') {

            $transaction->setState(Transaction::STATE_OK);
            $transaction->setStatusCode(Transaction::STATUS_VALIDATED);

            return true;
        }

        if ($transaction->get('payment_status') === 'Cancelled') {

            $transaction->setState(Transaction::STATE_OK);
            $transaction->setStatusCode(Transaction::STATUS_CANCELLED);

            return true;
        }

        $transaction->setState(Transaction::STATE_KO);
        $transaction->setStatusCode(Transaction::STATUS_UNKNOWN);

        return false;
    }

    public function handleError($transaction)
    {
        $order = $transaction->getOrder();

        switch ($transaction->getStatusCode()) {
            case Transaction::STATUS_ORDER_UNKNOWN:

                if($this->getLogger()) {
                    $this->getLogger()->emerg('[Paypal:handlerError] ERROR_ORDER_UNKNOWN');
                }

                break;
            case Transaction::STATUS_ERROR_VALIDATION:

                if($this->getLogger()) {
                    $this->getLogger()->emerg(sprintf('[Paypal:handlerError] STATUS_ERROR_VALIDATION - Order %s - Paypal reject the postback validation', $order->getReference()));
                }
                
                break;

            case Transaction::STATUS_CANCELLED:
                // cancelled
                $order->setStatus(OrderInterface::STATUS_CANCELLED);

                if($this->getLogger()) {
                    $this->getLogger()->emerg(sprintf('[Paypal:handlerError] STATUS_CANCELLED - Order %s - The Order has been cancelled, see callback dump for more information', $order->getReference()));
                }

                break;

            case Transaction::STATUS_PENDING:
                // pending
                $order->setStatus(OrderInterface::STATUS_PENDING);

                if($this->getLogger()) {
                    $reasons = self::getPendingReasonsList();
                    $this->getLogger()->emerg(sprintf('[Paypal:handlerError] STATUS_PENDING - Order %s - reason code : %s - reason : %s', $order->getReference(), $reasons[$transaction->get('pending_reason')], $transaction->get('pending_reason')));
                }

                break;
            default:

                if($this->getLogger()) {
                    $this->getLogger()->emerg(sprintf('[Paypal:handlerError] STATUS_PENDING - uncaught error code %s', $transaction->getErrorCode()));
                }
        }

        $transaction->setState(Transaction::STATE_KO);

        if($order->getStatus() === null) {
            $order->setStatus(OrderInterface::STATUS_CANCELLED);
        }

        if($transaction->getStatusCode() == null) {
            $transaction->setStatusCode(Transaction::STATUS_UNKNOWN);
        }
    }

    public function sendConfirmationReceipt($transaction)
    {

        if(!$transaction->isValid()) {

            return new \Symfony\Component\HttpFoundation\Response('');
        }

        $params = $transaction->getParameters();
        $params['cmd'] = '_notify-validate';

        // retrieve the client
        $client = $this
            ->getWebConnectorProvider()
            ->getNamedClient($this->getOption('web_connector_name', 'default'));

        $client->request('POST', $this->getOption('url_action'), $params);

        if ($client->getResponse()->getContent() == 'VERIFIED') {
            $transaction->setState(Transaction::STATE_OK);
            $transaction->setStatusCode(Transaction::STATUS_VALIDATED);

            $transaction->getOrder()->setValidatedAt(new \DateTime);
            $transaction->getOrder()->setStatus(OrderInterface::STATUS_VALIDATED);
            $transaction->getOrder()->setPaymentStatus(Transaction::STATUS_VALIDATED);
        } else {
            $transaction->setState(Transaction::STATE_KO);
            $transaction->setStatusCode(PaymentInterface::STATUS_ERROR_VALIDATION);

            $transaction->getOrder()->setPaymentStatus(OrderInterface::STATUS_ERROR);

            if($this->getLogger()) {
                $this->getLogger()->emerg('[Paypal::sendAccuseReception] Paypal failed to check the postback');
            }
        }

        return new \Symfony\Component\HttpFoundation\Response('');
    }

    public function isBasketValid($basket)
    {
        if ($basket->countBasketElements() == 0) {

            return false;
        }

        foreach ($basket->getBasketElements() as $element) {
            $product = $element->getProduct();
            if ($product->isRecurrentPayment() === true) {

                return false;
            }
        }

        return true;
    }

    public function isAddableProduct($basket, $product)
    {
        if (!$product->isRecurrentPayment()) {

            return true;
        }

        return false;
    }

    public static function getPendingReasonsList()
    {

        return array(
            self::PENDING_REASON_ADRESS         => 'The payment is pending because your customer did not include a confirmed shipping address and your Payment Receiving Preferences is set yo allow you to manually accept or deny each of these payments. To change your preference, go to the Preferences section of your Profile.',
            self::PENDING_REASON_AUTHORIZATION  => 'You set <PaymentAction> Authorization</PaymentAction> on SetExpressCheckoutRequest and have not yet captured funds.',
            self::PENDING_REASON_ECHECK         => 'The payment is pending because it was made by an eCheck that has not yet cleared. ',
            self::PENDING_REASON_INTL           => 'The payment is pending because you hold a non-U.S. account and do not have a withdrawal mechanism. You must manually accept or deny this payment from your Account Overview.',
            self::PENDING_REASON_MULTICURRENCY  => 'You do not have a balance in the currency sent, and you do not have your Payment Receiving Preferences set to automatically convert and accept this payment. You must manually accept or deny this payment.',
            self::PENDING_REASON_UNILATERAL     => 'The payment is pending because it was made to an email address that is not yet registered or confirmed. upgrade: The payment is pending because it was made via credit card and you must upgrade your account to Business or Premier status in order to receive the funds. upgrade can also mean that you have reached the monthly limit for transactions on your account. ',
            self::PENDING_REASON_UPGRADE        => 'The payment is pending because it was made via credit card and you must upgrade your account to Business or Premier status in order to receive the funds. upgrade can also mean that you have reached the monthly limit for transactions on your account.',
            self::PENDING_REASON_VERIFY         => 'The payment is pending because you are not yet verified. You must verify your account before you can accept this payment. ',
            self::PENDING_REASON_OTHER          => 'The payment is pending for a reason other than those listed above. For more information, contact PayPal Customer Service.',
        );
    }
}
