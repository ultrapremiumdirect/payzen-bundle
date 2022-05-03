<?php

namespace Antilop\SyliusPayzenBundle\Api;

use Lyra\Client as LyraClient;
use Payum\Core\Payum;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Payment\PaymentTransitions;

class PayzenSdkClient
{
    /** @var LyraClient */
    protected $client;

    /** @var string */
    protected $username;

    /** @var string */
    protected $password;

    /** @var string */
    protected $endpoint;

    /** @var Payum */
    protected $payum;

    /** @var FactoryInterface */
    protected $factory;

    /**
     * Constructor
     *
     * @param string $username
     * @param string $password
     * @param string $endpoint
     * @param Payum $payum
     * @param FactoryInterface $factory
     */
    public function __construct($username, $password, $endpoint, $payum, $factory)
    {
        $this->username = $username;
        $this->password = $password;
        $this->endpoint = $endpoint;
        $this->payum = $payum;
        $this->factory = $factory;
    }

    public function checkSignature()
    {
        return $this->client->checkHash($this->password);
    }

    /**
     * Generate form token
     *
     * @param OrderInterface $order
     * @param string         $action
     *
     * @return array
     */
    public function generateFormToken(OrderInterface $order, $action = 'CreatePayment')
    {
        if (empty($action)) {
            $action = 'CreatePayment';
        }

        $params = $this->getParams($order, $action);
        $response = $this->client->post('V4/Charge/' . $action, $params);

        if ($response['status'] !== 'SUCCESS') {
            return [
                'error' => $response['answer']['errorMessage'],
                'formToken' => false,
                'success' => false,
            ];
        }

        return [
            'error' => false,
            'formToken' => $response['answer']['formToken'],
            'success' => true,
        ];
    }

    /**
     * Get form answer
     *
     * @return array
     */
    public function getFormAnswer()
    {
        if (empty($_POST['kr-answer'])) {
            return [];
        }

        return $this->client->getParsedFormAnswer();
    }

    /**
     * Get parameters
     *
     * @param OrderInterface $order
     * @param string         $action
     *
     * @return array
     */
    protected function getParams(OrderInterface $order, $action)
    {
        $customer = $order->getCustomer();
        $shippingAddress = $order->getShippingAddress();
        $billingAddress = $order->getBillingAddress();
        $lastPayment = $order->getLastPayment();
        $paymentModel = $this->payum->getStorage('App\Entity\Payment\Payment')->find($lastPayment->getId());

        $ipnUrl = 'payzen_ipn_order_url';
        if ($action == 'CreateToken') {
            $ipnUrl = 'payzen_ipn_subscription_url';
        }

        $captureToken = $this->payum->getTokenFactory()->createToken(
            'payzen',
            $paymentModel,
            $ipnUrl,
            ['orderId' => $order->getId()]
        );

        $cartItems = [];
        /** @var OrderItemInterface  $item */
        foreach ($order->getItems() as $item) {
            $cartItems[] = [
                'productLabel' => $item->getProductName(),
                'productRef' => $item->getVariant()->getCode(),
                'productQty' => $item->getQuantity(),
                'productAmount' => $item->getTotal(),
                'productVat' => $item->getTaxTotal()
            ];
        }

        $shoppingCart = [
            'cartItemInfo' => $cartItems
        ];
        if($order->getTaxTotal()) $shoppingCart['taxAmount'] = $order->getTaxTotal();
        if($order->getShippingTotal()) $shoppingCart['shippingAmount'] = $order->getShippingTotal();

        return [
            'amount' => $order->getTotal(),
            'currency' => $order->getCurrencyCode(),
            'orderId' => $order->getNumber(),
            'customer' => [
                'reference' => $customer->getId(),
                'email' => $customer->getEmail(),
                'shippingDetails' => [
                    'firstName' => $shippingAddress->getFirstName(),
                    'lastName' => $shippingAddress->getLastName(),
                    'address' => $shippingAddress->getStreet(),
                    'address2' => $shippingAddress->getCompany(),
                    'zipCode' => $shippingAddress->getPostcode(),
                    'city' => $shippingAddress->getCity(),
                    'country' => $shippingAddress->getCountryCode(),
                    'phoneNumber' => $shippingAddress->getPhoneNumber(),
                ],
                'billingDetails' => [
                    'firstName' => $billingAddress->getFirstName(),
                    'lastName' => $billingAddress->getLastName(),
                    'address' => $billingAddress->getStreet(),
                    'address2' => $billingAddress->getCompany(),
                    'zipCode' => $billingAddress->getPostcode(),
                    'city' => $billingAddress->getCity(),
                    'country' => $billingAddress->getCountryCode(),
                    'phoneNumber' => $billingAddress->getPhoneNumber(),
                ],
                'shoppingCart' => $shoppingCart
            ],
            'strongAuthentication' => 'DISABLED',
            'ipnTargetUrl' => $captureToken->getTargetUrl()
        ];
    }

    /**
     * Init keys for SDK
     *
     * @return void
     */
    public function init()
    {
        LyraClient::setDefaultUsername($this->username);
        LyraClient::setDefaultPassword($this->password);
        LyraClient::setDefaultEndpoint($this->endpoint);

        $this->client = new LyraClient();
    }
}
