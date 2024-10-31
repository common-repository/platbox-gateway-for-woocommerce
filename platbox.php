<?php
//@codingStandardsIgnoreFile
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin Name: Platbox Gateway for WooCommerce
 * Description: WooCommerce Plugin for accepting payment through Platbox Form Gateway.
 * Version: 1.0.1
 * Author: Platbox Team
 * Author URI: http://www.platbox.com
 * Requires at least: 4.6
 * Tested up to: 4.7.4
 *
 * @package Platbox Gateway for WooCommerce
 * @author  Platbox Team
 */

/**
 * Текст ошибки при неизвестном действии
 */
define('PLATBOX_ERROR_ACTION', "Общая техническая ошибка");

/**
 * Путь до директории плагина
 */
define('PLATBOX_PLUGIN_PATH', plugin_dir_path(__FILE__));

add_action('plugins_loaded', 'initWoocommercePlatbox', 0);

/**
 * Инициализация плагина
 *
 * @return void
 */
function initWoocommercePlatbox()
{

    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    require_once PLATBOX_PLUGIN_PATH.'class.platbox_Gateway.php';

    /**
     * Добавление метода PlatBox
     *
     * @param array $methods - Методы оплаты
     *
     * @return array
     */
    function addPlatboxGateway($methods)
    {
        $methods[] = 'PlatboxGateway';

        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'addPlatboxGateway');

    /**
     * Очистка правил
     *
     * @return void
     */
    function flush_platbox_rules()
    {
        $rules = get_option('rewrite_rules');

        if (!isset($rules['platbox/'])) {
            global $wp_rewrite;
            $wp_rewrite->flush_rules();
        }
    }

    /**
     * Добавление нового правила
     *
     * @param array $rules - правила
     *
     * @return array
     */
    function insert_platbox_rewrite_rules($rules)
    {
        $newrules             = array();
        $newrules['platbox/'] = PLATBOX_PLUGIN_PATH;

        return $newrules + $rules;
    }
}

add_action('rest_api_init', 'registerPlatboxRoute');

/**
 * Регистрация роутинга шлюза
 *
 * @return void
 */
function registerPlatboxRoute()
{
    register_rest_route(
        'platbox',
        '/gateway/',
        array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => 'platboxGateway',
                'schema'              => null,
                'permission_callback' => 'getPermissionPlatboxPlugin',
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => 'platboxGateway',
                'schema'              => null,
                'permission_callback' => 'getPermissionPlatboxPlugin',
            ),
        )
    );
}

/**
 * Обработка пришедшей информации
 *
 * @return array|null
 */
function platboxGateway()
{
    $request  = getRawDataPlatbox();
    $response = null;
    $params   = json_decode($request, true);

    if ($params && !empty($params['action'])) {
        try {
            $platboxGateway = new PlatboxGateway();
            $signature      = $_SERVER['HTTP_X_SIGNATURE'];
            switch ($params['action']) {
                case 'check':
                    $response = $platboxGateway->checkOrder($params, $signature, $request);
                    break;
                case 'pay':
                    $response = $platboxGateway->payOrder($params, $signature, $request);
                    break;
                case 'cancel':
                    $response = $platboxGateway->cancelPayment($params, $signature, $request);
                    break;
                default:
                    throw new Exception(PLATBOX_ERROR_ACTION, 1000);
                    break;
            }
            $resultResponse = addSignaturePlatbox($response);
        } catch (Exception $e) {
            $resultResponse = [
                'status'      => 'error',
                'code'        => (string) $e->getCode(),
                'description' => $e->getMessage(),
            ];
        }

        return $resultResponse;
    }
}

/**
 * Проверка прав на доступ к шлюзу
 *
 * @return boolean
 */
function getPermissionPlatboxPlugin()
{
    return true;
}

/**
 * Отправка подписи в заголовок
 *
 * @param array $response - параметры ответа
 *
 * @return array
 */
function addSignaturePlatbox($response)
{
    header('Content-Type: application/json');
    header('X-Signature: '.$response['signature']);

    return $response['response'];
}


/**
 * Получаем тело запроса
 *
 * @return string
 */
function getRawDataPlatbox() {
    // $HTTP_RAW_POST_DATA is deprecated on PHP 5.6
    if ( function_exists( 'phpversion' ) && version_compare( phpversion(), '5.6', '>=' ) ) {
        return file_get_contents( 'php://input' );
    }

    global $HTTP_RAW_POST_DATA;

    // A bug in PHP < 5.2.2 makes $HTTP_RAW_POST_DATA not set by default,
    // but we can do it ourself.
    if ( ! isset( $HTTP_RAW_POST_DATA ) ) {
        $HTTP_RAW_POST_DATA = file_get_contents( 'php://input' );
    }

    return $HTTP_RAW_POST_DATA;
}