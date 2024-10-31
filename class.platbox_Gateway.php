<?php
//@codingStandardsIgnoreFile
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Created by PlatBox.
 * User: Platbox Team
 * Date: 15.05.17
 * Time: 10:00
 */
class PlatboxGateway extends WC_Payment_Gateway
{
    /**
     * Константы адресов для iframe, который общается с системой Platbox
     */
    const PLATBOX_URL_PROD = 'https://paybox-global.platbox.com/paybox';
    const PLATBOX_URL_TEST = 'https://playground.platbox.com/paybox';

    /**
     * Ошибки, которые можно вернуть Platbox сервису. Разные коды принадлежат разным запросам(см. документацию)
     *
     * @var array
     */
    private static $requestErrorList
        = [
            400  => 'Неверный формат сообщения',
            401  => 'Некорректная подпись запроса',
            406  => 'Неверные данные запроса',
            409  => 'Значение полей запроса не соответствуют значениям в системе мерчанта',
            1000 => 'Общая техническая ошибка',
            1001 => 'Учетная запись пользователя не найдена или заблокирована',
            1002 => 'Неверная валюта платежа',
            1003 => 'Неверная сумма платежа',
            1005 => 'Запрашиваемые товары или услуги недоступны',
            2000 => 'Платеж с указанным идентификатором уже зарезервирован',
            2001 => 'Платеж с указанным идентификатором уже проведен',
            2002 => 'Платеж с указанным идентификатором отменен',
            3000 => 'Зарезервированная ранее транзакция устарела',
        ];

    /**
     * Открытый ключ, совпадает с Merchant id
     *
     * @var string
     */
    public $openKey = '';

    /**
     * Закрытый ключ, используется для подписи запросов
     *
     * @var string
     */
    public $secretKey = '';

    /**
     * Название проекта в системе Platbox
     *
     * @var string
     */
    public $projectName = '';

    /**
     * Url для кнопки "вернуться" при удачной транзакции
     *
     * @var string
     */
    public $redirectUrl = '';


    /**
     * Конструктор класса
     */
    public function __construct()
    {
        global $woocommerce;

        $this->id                 = 'platbox';
        $this->icon               = plugin_dir_url(__FILE__).'images/paybox_logo.png';
        $this->method_title       = 'Platbox';
        $this->method_description = 'Платежи Онлайн';
        $this->has_fields         = false;

        // Load the form fields.
        $this->initFormFields();

        // Load the settings.
        $this->init_settings();

        $this->openKey     = $this->settings['open_key'];
        $this->secretKey   = $this->settings['secret_key'];
        $this->projectName = $this->settings['project_name'];
        $this->redirectUrl = $this->settings['redirect_url'];

        // Define user set variables
        $this->title = 'Platbox';

        $this->description
            = 'Платеж через PlatBox полностью безопасен, данные платежа шифруются с помощью 256-битного протокола TLS или SSl';

        // Actions
        add_action('init', array($this, 'successfulRequest'));
        add_action('woocommerce_api_woocommerce_platbox', array($this, 'successfulRequest'));
        add_action('woocommerce_receipt_platbox', array($this, 'receiptPage'));
        add_action('woocommerce_update_options_payment_gateways', array($this, 'process_admin_options'));
        add_action('woocommerce_update_options_payment_gateways_'.$this->id, array($this, 'process_admin_options'));
    }


    /**
     * Вывод настроек в админ панели
     *
     * @since 1.0.0
     */
    public function admin_options()
    {

        ?>
        <h3>Platbox Payment Iframe</h3>
        <p>Platbox Iframe - платежный агрегатор, помогающий вам произвести оплату удобно и быстро.</p>
        <table class="form-table">
            <?php
            // Generate the HTML For the settings form.
            $this->generate_settings_html();

            ?>
        </table>
        <?php
    }

    /**
     * Определение полей настройки
     *
     * @return void
     */
    public function initFormFields()
    {
        $this->form_fields = array(
            'enabled'      => array(
                'title'   => 'Включить данный вид оплаты',
                'type'    => 'checkbox',
                'label'   => 'Enable Paybox',
                'default' => 'yes',
            ),
            'open_key'     => array(
                'title'       => 'Открытый ключ',
                'type'        => 'text',
                'description' => 'Совпадает со значением merchant_id',
            ),
            'secret_key'   => array(
                'title'       => 'Закрытый ключ',
                'type'        => 'text',
                'description' => 'Используется для шифрования содержимого запросов',
            ),
            'project_name' => array(
                'title'       => 'Проект',
                'type'        => 'text',
                'description' => 'Название проекта в системе Paybox',
            ),
            'redirect_url' => array(
                'title'       => 'URL Paybox',
                'type'        => 'text',
                'description' => 'Адрес(с вашей стороны) для Paybox для инициации транзакции',
                'default'     => site_url().'/wp-content/plugins/platbox/',
                к,
            ),
            'is_prod'      => array(
                'title'       => 'Production режим',
                'type'        => 'checkbox',
                'description' => 'Настоящие транзакции и списания. Если не выбрано, то транзакции будут направляться на тестовый сервер Platbox',
            ),
        );
    }

    /**
     * Вывод описания
     *
     * @return void
     **/
    public function payment_fields()
    {
        echo $this->description;
    }

    /**
     * Генерирует iframe для оплаты
     *
     * @param int|object|WC_Order $orderId Order to read.
     *
     * @return string
     */
    public function generatePlatboxForm($orderId)
    {

        global $woocommerce;

        $order = new WC_Order($orderId);

        // стоимость в минорных единицах
        $data = [
            'merchant_id'  => (string) $this->openKey,
            'account'      => json_encode(
                [
                    'id' => $order->get_user_id(),
                ]
            ),
            'amount'       => (string) ($order->get_total() * 100),
            'currency'     => $order->get_order_currency(),
            'order'        => json_encode(
                [
                    'type'      => 'item_list',
                    'item_list' => [
                        [
                            'id'    => (string) $orderId,
                            'name'  => (string) $orderId,
                            'count' => (string) 1,
                        ],
                    ],
                ]
            ),
            'project'      => $this->projectName,
            'comment'      => '',
            'redirect_url' => $this->get_return_url($order),
            'additional'   => '',
            'timestamp'    => (string) microtime(),
        ];
        ksort($data);
        $data['sign'] = $this->getSignature(json_encode($data));

        $platboxUrl = $this->settings['is_prod'] == 'yes' ? self::PLATBOX_URL_PROD : self::PLATBOX_URL_TEST;

        $resultCode = '<iframe width="900" height="500" frameBorder="0" scrolling="no" src="'.$platboxUrl.'?';
        $resultCode .= http_build_query($data).'"></iframe>';

        return $resultCode;
    }

    /**
     * Процесс оплаты и возвращение результата
     *
     * @param string $orderId - id заказа
     *
     * @return array
     **/
    public function process_payment($orderId)
    {
        global $woocommerce;
        $order = new WC_Order($orderId);

        // Reduce stock levels
        $order->reduce_order_stock();

        // Remove cart
        $woocommerce->cart->empty_cart();

        $returnResult = ['result' => 'success', 'redirect' => $order->get_checkout_payment_url(true)];

        return $returnResult;
    }


    /**
     * Запрос успешен
     *
     * @return void
     */
    public function successfulRequest()
    {
        global $woocommerce;

        wp_redirect(get_permalink(get_option('woocommerce_checkout_page_id')));
        exit;
    }

    /**
     * Выводит код страницы оплаты
     *
     * @param mixed $order - заказ
     *
     * @return void
     */
    public function receiptPage($order)
    {
        echo $this->generatePlatboxForm($order);
    }

    /**
     * Проверка данных Platbox
     *
     * @param array  $request     - массив с данными запроса
     * @param string $signature   - подпись
     * @param string $jsonRequest - json данные запроса
     *
     * @return array
     */
    public function checkOrder($request, $signature, $jsonRequest)
    {
        global $woocommerce;
        $result = [
            'status' => 'error',
        ];

        try {
            // проверяем сигнатуру
            if (!$this->checkSignature($jsonRequest, $signature)) {
                throw new Exception($this->requestErrorList[401], 401);
            }
            // проверяем, что все поля заполнены(пришли из запроса)
            $requestData = [
                'order_id' => $request['order']['item_list'][0]['id'],
                'currency' => $request['payment']['currency'],
                'amount'   => ($request['payment']['amount'] / (pow(10, $request['payment']['exponent']))),
            ];

            if (count(array_filter($requestData)) == count($requestData)) {
                $order = new WC_Order($requestData['order_id']);
                // проверка существования заказа осуществляется через проверку его статуса. Ага.
                $status = $order->get_status();
                if (!empty($status)) {
                    switch ($status) {
                        case 'pending':
                            // заказ в статусе ожидании оплаты
                            $orderData = [
                                'currency' => $order->get_order_currency(),
                                'amount'   => $order->get_total(),
                            ];
                            // проверяем, что сумма и валюта совпадают
                            if ($orderData['currency'] == $requestData['currency']) {
                                if ($orderData['amount'] == $requestData['amount']) {
                                    // резервируем товар
                                    $order->update_status('on-hold', 'Awaiting payment');
                                    $result = [
                                        'status'         => 'ok',
                                        'merchant_tx_id' => $requestData['order_id'],
                                    ];
                                } else {
                                    throw new Exception($this->requestErrorList[1003], 1003);
                                }
                            } else {
                                throw new Exception($this->requestErrorList[1002], 1002);
                            }
                            break;
                        case 'on-hold':
                            // заказ уже зарезервирован
                            throw new Exception($this->requestErrorList[2000], 2000);
                            break;
                        case 'processing':
                            // заказ уже обрабатывается(получена оплата, идет отгрузка)
                            throw new Exception($this->requestErrorList[2001], 2001);
                            break;
                        case 'completed':
                            // заказ завершен
                            throw new Exception($this->requestErrorList[2001], 2001);
                            break;
                        case 'cancelled':
                            // заказ отменен
                            throw new Exception($this->requestErrorList[2002], 2002);
                            break;
                        default:
                            // заказ имеет непонятный статус
                            throw new Exception($this->requestErrorList[1000], 1000);
                            break;
                    }
                } else {
                    throw new Exception($this->requestErrorList[1005], 1005);
                }
            } else {
                throw new Exception($this->requestErrorList[1000], 1000);
            }
        } catch (Exception $e) {
            $result['code']        = (string) $e->getCode();
            $result['description'] = (string) $e->getMessage();
        }
        ksort($result);
        $newSign      = $this->getSignature(json_encode($result));
        $returnResult = ['response' => $result, 'signature' => $newSign];

        return $returnResult;
    }

    /**
     * Подтверждение транзакции
     *
     * @param array  $request     - массив с данными запроса
     * @param string $signature   - подпись
     * @param string $jsonRequest - json данные запроса
     *
     * @return array
     */
    public function payOrder($request, $signature, $jsonRequest)
    {
        global $woocommerce;
        $result = [
            'status' => 'ok',
        ];

        try {
            if (!$this->checkSignature($jsonRequest, $signature)) {
                throw new Exception($this->requestErrorList[401], 401);
            }
            // проверяем, что передан номер заказа
            if (!empty($request['merchant_tx_id'])) {
                if (!empty($request['platbox_tx_succeeded_at'])) {
                    // платеж проведен успешно, отмечаем это в системе
                    $order = new WC_Order($request['merchant_tx_id']);

                    // проверка существования заказа осуществляется через проверку его статуса. Ага.
                    $status = $order->get_status();
                    if (!empty($status)) {
                        switch ($status) {
                            case 'pending':
                            case 'on-hold':
                                if ($request['platbox_tx_id']) {
                                    $order->add_order_note(
                                        sprintf(
                                            __(
                                                'Payment Completed. The Reference Number is %s.',
                                                'woo-platbox-patsatech'
                                            ),
                                            $request['platbox_tx_id']
                                        )
                                    );
                                }

                                $order->payment_complete();
                                $result['merchant_tx_timestamp'] = date('c');
                                break;
                            case 'processing':
                                // заказ уже обрабатывается(получена оплата, идет отгрузка)
                                throw new Exception($this->requestErrorList[3000], 3000);
                                break;
                            case 'completed':
                                // заказ завершен
                                throw new Exception($this->requestErrorList[3000], 3000);
                                break;
                            case 'cancelled':
                                // заказ отменен
                                throw new Exception($this->requestErrorList[2002], 2002);
                                break;
                            default:
                                // заказ имеет непонятный статус
                                throw new Exception($this->requestErrorList[1000], 1000);
                                break;
                        }
                    } else {
                        throw new Exception($this->requestErrorList[1005], 1005);
                    }
                } else {
                    throw new Exception($this->requestErrorList[406], 406);
                }
            } else {
                throw new Exception($this->requestErrorList[406], 406);
            }
        } catch (Exception $e) {
            $result = [
                'status'      => 'error',
                'code'        => (string) $e->getCode(),
                'description' => $e->getMessage(),
            ];
        }

        ksort($result);
        $newSign      = $this->getSignature(json_encode($result));
        $returnResult = ['response' => $result, 'signature' => $newSign];

        return $returnResult;
    }

    /**
     * Транзакция отклонена
     *
     * @param array  $request     - массив с данными запроса
     * @param string $signature   - подпись
     * @param string $jsonRequest - json данные запроса
     *
     * @return array
     */
    public function cancelPayment($request, $signature, $jsonRequest)
    {
        global $woocommerce;
        $result = [
            'status' => 'ok',
        ];

        try {
            // проверяем сигнатуру
            if (!$this->checkSignature($jsonRequest, $signature)) {
                throw new Exception($this->requestErrorList[401], 401);
            }
            // проверяем, что передан номер заказа
            if (!empty($request['merchant_tx_id'])) {
                if (!empty($request['platbox_tx_canceled_at'])) {
                    // пришла ошибка от платежной системы, отменяем
                    wc_add_notice(
                        sprintf(
                            __('Transaction Failed at %s', 'woo-platbox-patsatech'),
                            $request['platbox_tx_canceled_at']
                        ),
                        $noticeType = 'error'
                    );
                    $result['merchant_tx_timestamp'] = date('c');
                } else {
                    throw new Exception($this->requestErrorList[406], 406);
                }
            } else {
                throw new Exception($this->requestErrorList[406], 406);
            }
        } catch (Exception $e) {
            $result = [
                'status'      => 'error',
                'code'        => (string) $e->getCode(),
                'description' => $e->getMessage(),
            ];
        }

        ksort($result);
        $newSign      = $this->getSignature(json_encode($result));
        $returnResult = ['response' => $result, 'signature' => $newSign];

        return $returnResult;
    }


    /**
     * Проверка подписи по ключу в настройках
     *
     * @param $data              string
     * @param $requestSignature  string
     *
     * @return bool
     */
    protected function checkSignature($data, $requestSignature)
    {
        $currentSign = $this->getSignature($data);

        return strtolower($currentSign) == strtolower($requestSignature);
    }

    /**
     * Получение подписи по ключу в настройках
     *
     * @param $data string
     *
     * @return false|string
     */
    protected function getSignature($data)
    {
        $sign = hash_hmac('sha256', $data, $this->secretKey);

        return $sign;
    }
}
