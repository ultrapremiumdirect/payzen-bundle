<?php

namespace Antilop\SyliusPayzenBundle\Controller;

use Antilop\SyliusPayzenBundle\Factory\PayzenSdkClientFactory;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManager;
use Payum\Core\Payum;
use SM\Factory\FactoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class IpnController
{
    /** @var Payum */
    protected $payum;

    /** @var OrderRepositoryInterface */
    protected $orderRepository;

    /** @var PayzenSdkClient */
    protected $payzenSdkClientFactory;

    /** @var FactoryInterface */
    protected $factory;

    /** @var SubscriptionService */
    protected $subscriptionService;

    /** @var EntityManager */
    protected $em;

    public function __construct(
        Payum $payum,
        OrderRepositoryInterface $orderRepository,
        PayzenSdkClientFactory $payzenSdkClientFactory,
        FactoryInterface $factory,
        SubscriptionService $subscriptionService,
        EntityManager $em
    ) {
        $this->payum = $payum;
        $this->orderRepository = $orderRepository;
        $this->payzenSdkClientFactory = $payzenSdkClientFactory;
        $this->factory = $factory;
        $this->subscriptionService = $subscriptionService;
        $this->em = $em;
    }

    public function completeOrderAction(Request $request, $orderId): Response
    {
        /** @var OrderInterface|null $order */
        $order = $this->orderRepository->findCartById($orderId);

        if (null === $order) {
            throw new NotFoundHttpException(sprintf('Order with id "%s" does not exist.', $orderId));
        }

        $token = $this->payum->getHttpRequestVerifier()->verify($request);
        if (empty($token)) {
            throw new NotFoundHttpException(sprintf('Invalid security token for order with id "%s".', $orderId));
        }

        $payzenClient = $this->payzenSdkClientFactory->create();
        if (!$payzenClient->checkSignature()) {
            throw new NotFoundHttpException(sprintf('Invalid signature for Order "%s".', $order->getId()));
        }

        $rawAnswer = $payzenClient->getFormAnswer();
        if (!empty($rawAnswer)) {
            $formAnswer = $rawAnswer['kr-answer'];
            $orderStatus = $formAnswer['orderStatus'];

            if ($orderStatus === 'PAID') {
                $payment = $order->getLastPayment(PaymentInterface::STATE_CART);
                if (!empty($payment)) {
                    $stateMachine = $this->factory->get($payment, PaymentTransitions::GRAPH);
                    $stateMachine->apply(PaymentTransitions::TRANSITION_CREATE);

                    $stateMachine = $this->factory->get($payment, PaymentTransitions::GRAPH);
                    $stateMachine->apply(PaymentTransitions::TRANSITION_COMPLETE);
    
                    $stateMachine = $this->factory->get($order, OrderCheckoutTransitions::GRAPH);
                    $stateMachine->apply(OrderCheckoutTransitions::TRANSITION_COMPLETE);

                    $paymentDetails = $this->makeUniformPaymentDetails($formAnswer);
                    $payment->setDetails($paymentDetails);

                    $this->em->persist($payment);
                    $this->em->persist($order);
                    $this->em->flush();

                    $this->payum->getHttpRequestVerifier()->invalidate($token);

                    return new Response('OK');
                }
            }
        }

        return new Response('Invalid form answer from payzen');
    }

    public function updateSubscriptionBankDetailsAction(Request $request, $orderId): Response
    {
        /** @var SubscriptionDraftOrder|null $order */
        $order = $this->orderRepository->findCartById($orderId);

        if (null === $order) {
            throw new NotFoundHttpException(sprintf('Order with id "%s" does not exist.', $orderId));
        }

        $payzenClient = $this->payzenSdkClientFactory->create();
        if (!$payzenClient->checkSignature()) {
            throw new NotFoundHttpException(sprintf('Invalid signature for Order "%s".', $order->getId()));
        }

        $token = $this->payum->getHttpRequestVerifier()->verify($request);
        if (empty($token)) {
            throw new NotFoundHttpException(sprintf('Invalid security token for order with id "%s".', $orderId));
        }

        $rawAnswer = $payzenClient->getFormAnswer();
        if (!empty($rawAnswer)) {
            $formAnswer = $rawAnswer['kr-answer'];
            $orderStatus = $formAnswer['orderStatus'];

            if ($orderStatus === 'PAID') {
                $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
                $paymentDetails = $this->makeUniformPaymentDetails($formAnswer);
                $payment->setDetails($paymentDetails);

                $subscription = $order->getSubscription();
                if (!empty($subscription)) {
                    $this->subscriptionService->updateCardExpiration(
                        $subscription,
                        intval($paymentDetails['vads_expiry_month']),
                        intval($paymentDetails['vads_expiry_year'])
                    );

                    $this->em->persist($payment);
                    $this->em->persist($order);
                    $this->em->persist($subscription);
                    $this->em->flush();

                    $this->payum->getHttpRequestVerifier()->invalidate($token);

                    return new Response('OK');
                }
            }
        }

        return new Response('Invalid form answer from payzen');
    }

    protected function makeUniformPaymentDetails($formAnswer)
    {
        if (empty($formAnswer) || !is_array($formAnswer)) {
            return [];
        }

        $details = $formAnswer;
        if (array_key_exists('transactions', $formAnswer)) {
            $transaction = current($formAnswer['transactions']);
            $details['vads_trans_uuid'] = $transaction['uuid'];

            if (array_key_exists('transactionDetails', $transaction)) {
                $transactionDetails = $transaction['transactionDetails'];
                $details['vads_trans_id'] = $transactionDetails['cardDetails']['legacyTransId'];
                $details['vads_expiry_month'] = $transactionDetails['cardDetails']['expiryMonth'];
                $details['vads_expiry_year'] = $transactionDetails['cardDetails']['expiryYear'];
            }
        }

        return $details;
    }
}