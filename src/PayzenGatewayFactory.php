<?php

namespace Antilop\SyliusPayzenBundle;

use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;
use Payum\Core\GatewayFactoryInterface;
use Payum\Core\Model\GatewayConfigInterface;

/**
 * Class PayzenGatewayFactory
 *
 * @package Antilop\SyliusPayzenBundle
 * @author  Etienne Dauvergne <contact@ekyna.com>
 */
class PayzenGatewayFactory extends GatewayFactory
{
    /**
     * Builds a new factory.
     *
     * @param array                   $defaultConfig
     * @param GatewayFactoryInterface $coreGatewayFactory
     *
     * @return PayzenGatewayFactory
     */
    public static function build(array $defaultConfig, GatewayFactoryInterface $coreGatewayFactory = null)
    {
        return new static($defaultConfig, $coreGatewayFactory);
    }

    /**
     * @inheritDoc
     */
    protected function populateConfig(ArrayObject $config)
    {
        $apiConfig = false != $config['payum.api_config']
            ? (array)$config['payum.api_config']
            : [];

        $config->defaults([
            'payum.factory_name' => 'payzen',
            'payum.factory_title' => 'Payzen',
            'payum.action.capture' => new Action\CaptureAction(),
            'payum.action.convert_payment' => new Action\ConvertPaymentAction(),
            'payum.action.api_request' => new Action\Api\ApiRequestAction(),
            'payum.action.api_response' => new Action\Api\ApiResponseAction(),
            'payum.action.sync' => new Action\SyncAction(),
            'payum.action.refund' => new Action\RefundAction(),
            'payum.action.status' => new Action\StatusAction(),
            'payum.action.notify' => new Action\NotifyAction(),
        ]);

        $defaultOptions = [];
        $requiredOptions = [];

        if (false == $config['payum.api']) {
            $defaultOptions['api'] = array_replace([
                'site_id' => $config['site_id'],
                'certificate' => $config['certificate'],
                'ctx_mode' => $config['ctx_mode'],
                'directory' => $config['directory'],
                'debug' => $config['debug'],
                'n_times' => $config['n_times'],
                'count' => intval($config['count']),
                'period' => intval($config['period']),
                'endpoint' => $config['endpoint'],
                'webservice_endpoint' => $config['webservice_endpoint'],
                'timer_success_return' => $config['timer_success_return'],
                'rest_endpoint' => $config['rest_endpoint'],
                'rest_password' => $config['rest_password'],
                'rest_pubkey' => $config['rest_pubkey'],
            ], $apiConfig);

            $requiredOptions[] = 'api';

            $config['payum.api'] = function (ArrayObject $config) {
                $config->validateNotEmpty($config['payum.required_options']);

                $api = new Api\Api();
                $api->setConfig($config['api']);

                return $api;
            };
        }

        $config['payum.default_options'] = $defaultOptions;
        $config['payum.required_options'] = $requiredOptions;

        $config->defaults($config['payum.default_options']);
    }
}
