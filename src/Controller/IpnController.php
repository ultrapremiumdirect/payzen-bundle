<?php

namespace Antilop\SyliusPayzenBundle\Controller;

use Antilop\SyliusPayzenBundle\Factory\PayzenSdkClientFactory;
use App\Entity\Subscription\Subscription;
use App\Entity\Subscription\SubscriptionState;
use App\Service\SubscriptionService;
use App\StateMachine\OrderCheckoutStates;
use Doctrine\ORM\EntityManager;
use Payum\Core\Payum;
use SM\Factory\FactoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Core\Payment\Provider\OrderPaymentProviderInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\StateResolver\StateResolverInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class IpnController
{
    const OPERATION_TYPE_VERIFICATION = 'VERIFICATION';
    const OPERATION_TYPE_DEBIT = 'DEBIT';

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

    /** @var OrderPaymentProviderInterface */
    private $orderPaymentProvider;

    /** @var StateResolverInterface */
    private $orderPaymentStateResolver;

    public function __construct(
        Payum                         $payum,
        OrderRepositoryInterface      $orderRepository,
        PayzenSdkClientFactory        $payzenSdkClientFactory,
        FactoryInterface              $factory,
        SubscriptionService           $subscriptionService,
        EntityManager                 $em,
        OrderPaymentProviderInterface $orderPaymentProvider,
        StateResolverInterface        $orderPaymentStateResolver
    )
    {
        $this->payum = $payum;
        $this->orderRepository = $orderRepository;
        $this->payzenSdkClientFactory = $payzenSdkClientFactory;
        $this->factory = $factory;
        $this->subscriptionService = $subscriptionService;
        $this->em = $em;
        $this->orderPaymentProvider = $orderPaymentProvider;
        $this->orderPaymentStateResolver = $orderPaymentStateResolver;
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

            /** @var PaymentInterface $payment */
            $payment = $order->getLastPayment(PaymentInterface::STATE_CART);
            $stateMachine = $this->factory->get($payment, PaymentTransitions::GRAPH);
            if ($stateMachine->can(PaymentTransitions::TRANSITION_CREATE)) {
                $stateMachine->apply(PaymentTransitions::TRANSITION_CREATE);
            }

            if ($orderStatus === 'PAID' && !empty($payment)) {
                $payzenTotal = (int)$formAnswer['orderDetails']['orderTotalAmount'];
                if ($payzenTotal != $order->getTotal()) {
                    $payment->setAmount($payzenTotal);
                    $this->orderPaymentStateResolver->resolve($order);
                }

                $this->markComplete($payment);

                $stateMachine = $this->factory->get($order, OrderCheckoutTransitions::GRAPH);
                $stateMachine->apply(OrderCheckoutTransitions::TRANSITION_COMPLETE);

                $paymentDetails = $this->makeUniformPaymentDetails($formAnswer);
                $payment->setDetails($paymentDetails);

                $this->em->persist($payment);
                $this->em->persist($order);
                $this->em->flush();

                return new Response('SUCCESS');
            }

            if ($orderStatus === 'UNPAID' && !empty($payment)) {
                $this->markFailed($payment, $order);
                $this->em->persist($payment);
                $this->em->flush();

                return new Response('FAIL');
            }
        }

        $this->payum->getHttpRequestVerifier()->invalidate($token);

        return new Response('Invalid form answer from payzen');
    }

    public function updateSubscriptionBankDetailsAction(Request $request, $orderId): Response
    {
        /** @var SubscriptionDraftOrder|null $order */
        $order = $this->orderRepository->findOneBy([
            'id' => $orderId,
            'checkoutState' => OrderCheckoutStates::STATE_DRAFT
        ]);

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
            /** @var Subscription $subscription */
            $subscription = $order->getSubscription();

            $formAnswer = $rawAnswer['kr-answer'];
            $orderStatus = $formAnswer['orderStatus'];

            if ($orderStatus === 'PAID') {
                $expiryMonth = 0;
                $expiryYear = 0;
                $cardToken = '';

                if (array_key_exists('transactions', $formAnswer)) {
                    $transaction = current($formAnswer['transactions']);
                    $cardToken = $transaction['paymentMethodToken'];

                    if (array_key_exists('transactionDetails', $transaction)) {
                        $transactionDetails = $transaction['transactionDetails'];
                        $expiryMonth = $transactionDetails['cardDetails']['expiryMonth'];
                        $expiryYear = $transactionDetails['cardDetails']['expiryYear'];
                    }
                }

                if (!empty($subscription) && !empty($expiryMonth) && !empty($expiryYear) && !empty($cardToken)) {
                    $this->subscriptionService->updateCard(
                        $subscription,
                        intval($expiryMonth),
                        intval($expiryYear),
                        $cardToken
                    );

                    $this->em->persist($subscription);
                }

                $this->em->flush();

                return new Response('SUCCESS');
            }

            if ($orderStatus === 'UNPAID') {
                return new Response('FAIL');
            }
        }

        $this->payum->getHttpRequestVerifier()->invalidate($token);

        return new Response('Invalid form answer from payzen');
    }

    protected function markComplete($payment)
    {
        if (empty($payment)) {
            return false;
        }

        $stateMachine = $this->factory->get($payment, PaymentTransitions::GRAPH);
        $stateMachine->apply(PaymentTransitions::TRANSITION_PROCESS);

        $stateMachine = $this->factory->get($payment, PaymentTransitions::GRAPH);
        $stateMachine->apply(PaymentTransitions::TRANSITION_COMPLETE);

        return true;
    }

    protected function markFailed($payment, $order)
    {
        if (empty($payment)) {
            return false;
        }

        $stateMachine = $this->factory->get($payment, PaymentTransitions::GRAPH);
        $stateMachine->apply(PaymentTransitions::TRANSITION_PROCESS);

        $stateMachine = $this->factory->get($payment, PaymentTransitions::GRAPH);
        $stateMachine->apply(PaymentTransitions::TRANSITION_FAIL);

        $newPayment = $this->orderPaymentProvider->provideOrderPayment($order, PaymentInterface::STATE_CART);
        $order->addPayment($newPayment);

        $stateMachine = $this->factory->get($newPayment, PaymentTransitions::GRAPH);
        if ($stateMachine->can(PaymentTransitions::TRANSITION_CREATE)) {
            $stateMachine->apply(PaymentTransitions::TRANSITION_CREATE);
        }

        return true;
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
