<?php

/**
 * Class ControllerModule
 *
 * @category RetailCrm
 * @package  RetailCrm
 * @author   RetailCrm <integration@retailcrm.ru>
 * @license  https://opensource.org/licenses/MIT MIT License
 * @link     http://www.retailcrm.ru/docs/Developers/ApiVersion5
 */
class ControllerExtensionModuleRetailcrm extends Controller
{
    private $_error = array();
    protected $log, $statuses, $payments, $deliveryTypes, $retailcrmApiClient;
    public $children, $template;

    public function __construct($registry)
    {
        parent::__construct($registry);
        $this->load->library('retailcrm/retailcrm');
        $this->moduleTitle = $this->retailcrm->getModuleTitle();
    }

    /**
     * Install method
     *
     * @return void
     */
    public function install()
    {
        $this->load->model('setting/setting');

        $this->model_setting_setting
            ->editSetting($this->moduleTitle, array(
                    $this->moduleTitle . '_status' => 1,
                    $this->moduleTitle . '_country' => array($this->config->get('config_country_id'))
                )
            );

        $this->addEvents();
    }

    /**
     * Uninstall method
     *
     * @return void
     */
    public function uninstall()
    {
        $this->uninstall_collector();
        $this->load->model('setting/setting');
        $this->model_setting_setting
            ->editSetting($this->moduleTitle, array($this->moduleTitle . '_status' => 0));
        $this->model_setting_setting->deleteSetting('retailcrm_history');
        $this->deleteEvents();
    }

    /**
     * Install Demon Collector method
     *
     * @return void
     */
    public function install_collector()
    {   
        $collector = $this->getCollectorTitle();
        $this->loadModels();
        $this->load->model('setting/setting');
        $this->{'model_' . $this->modelExtension}->install('analytics', 'daemon_collector');
        $this->model_setting_setting->editSetting($collector, array($collector . '_status' => 1));
    }

    /**
     * Uninstall Demon Collector method
     *
     * @return void
     */
    public function uninstall_collector()
    {   
        $collector = $this->getCollectorTitle();
        $this->loadModels();
        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting($collector, array($collector . '_status' => 0));
        $this->{'model_' . $this->modelExtension}->uninstall('analytics', 'daemon_collector');
    }

    /**
     * Setup page
     *
     * @return void
     */
    public function index()
    {   
        $this->loadModels();
        $this->load->model('localisation/country');
        $this->load->model('setting/setting');
        $this->load->model('extension/retailcrm/references');
        $this->load->language('extension/module/retailcrm');
        $this->document->setTitle($this->language->get('heading_title'));
        $this->document->addStyle('/admin/view/stylesheet/retailcrm.css');

        $tokenTitle = $this->retailcrm->getTokenTitle();
        $collector = $this->getCollectorTitle();
        $history_setting = $this->model_setting_setting->getSetting('retailcrm_history');
        
        if ($this->request->server['REQUEST_METHOD'] == 'POST' && $this->validate()) {
            if ($this->checkEvents() === false) {
                $this->deleteEvents();
                $this->addEvents();
            }

            $analytics = $this->{'model_' . $this->modelExtension}->getInstalled('analytics');
            
            if ($this->request->post[$this->moduleTitle . '_collector_active'] == 1 && 
                !in_array($collector, $analytics)) {
                $this->install_collector();
            } elseif ($this->request->post[$this->moduleTitle . '_collector_active'] == 0 &&
                in_array($collector, $analytics)) {
                $this->uninstall_collector();
            }

            if (parse_url($this->request->post[$this->moduleTitle . '_url'])) {
                $crm_url = parse_url($this->request->post[$this->moduleTitle . '_url'], PHP_URL_HOST);
                $this->request->post[$this->moduleTitle . '_url'] = 'https://'.$crm_url;
            }
            
            if (isset($this->request->post[$this->moduleTitle . '_custom_field_active']) &&
               $this->request->post[$this->moduleTitle . '_custom_field_active'] == 0
            ) {
                unset($this->request->post[$this->moduleTitle . '_custom_field']);
            }

            $this->model_setting_setting->editSetting(
                $this->moduleTitle,
                $this->request->post
            );

            if ($this->request->post[$this->moduleTitle . '_apiversion'] != 'v3') {
                if (!isset($history_setting['retailcrm_history_orders']) && !isset($history_setting['retailcrm_history_customers'])) {
                    $api = $this->retailcrm->getApiClient(
                        $this->request->post[$this->moduleTitle . '_url'],
                        $this->request->post[$this->moduleTitle . '_apikey'],
                        $this->request->post[$this->moduleTitle . '_apiversion']
                    );

                    $ordersHistory = $api->ordersHistory();

                    if ($ordersHistory->isSuccessful()) {
                        $ordersHistory = $api->ordersHistory(array(), $ordersHistory['pagination']['totalPageCount']);

                        if ($ordersHistory->isSuccessful()) {
                            $ordersHistoryArr = $ordersHistory['history'];
                            $lastChangeOrders = end($ordersHistoryArr);
                            $sinceIdOrders = $lastChangeOrders['id'];
                            $generatedAt = $ordersHistory['generatedAt'];
                        }
                    }

                    $customersHistory = $api->customersHistory();

                    if ($customersHistory->isSuccessful()) {
                        $customersHistory = $api->customersHistory(array(), $customersHistory['pagination']['totalPageCount']);

                        if ($customersHistory->isSuccessful()) {
                            $customersHistoryArr = $customersHistory['history'];
                            $lastChangeCustomers = end($customersHistoryArr);
                            $sinceIdCustomers = $lastChangeCustomers['id'];
                        }
                    }

                    $this->model_setting_setting->editSetting(
                        'retailcrm_history', 
                        array(
                            'retailcrm_history_orders' => $sinceIdOrders, 
                            'retailcrm_history_customers' => $sinceIdCustomers,
                            'retailcrm_history_datetime' => $generatedAt
                        )
                    );
                }
            }

            $this->session->data['success'] = $this->language->get('text_success');
            $redirect = $this->url->link(
                'extension/module/retailcrm', $tokenTitle . '=' . $this->session->data[$tokenTitle],
                'SSL'
            );

            $this->response->redirect($redirect);
        }

        $text_strings = array(
            'heading_title',
            'text_enabled',
            'text_disabled',
            'button_save',
            'button_cancel',
            'text_notice',
            'retailcrm_title',
            'retailcrm_apiversion',
            'retailcrm_url',
            'retailcrm_apikey',
            'retailcrm_base_settings',
            'retailcrm_dict_settings',
            'retailcrm_dict_delivery',
            'retailcrm_dict_status',
            'retailcrm_dict_payment',
            'retailcrm_countries_settings',
            'text_success_export',
            'text_success_export_order',
            'text_button_export',
            'text_button_export_order',
            'text_button_catalog',
            'text_success_catalog',
            'retailcrm_upload_order',
            'text_error_order',
            'text_error_order_id',
            'daemon_collector',
            'general_tab_text',
            'references_tab_text',
            'collector_tab_text',
            'logs_tab_text',
            'text_yes',
            'text_no',
            'collector_site_key',
            'text_collector_activity',
            'text_collector_form_capture',
            'text_collector_period',
            'text_label_promo',
            'text_label_send',
            'collector_custom_text',
            'text_require',
            'custom_fields_tab_text',
            'text_error_custom_field',
            'text_error_cf_opencart',
            'text_error_cf_retailcrm',
            'retailcrm_dict_custom_fields',
            'text_payment',
            'text_shipping',
            'retailcrm_dict_default',
            'text_custom_field_activity',
            'text_orders_custom_fields',
            'text_customers_custom_fields',
            'text_confirm_log',
            'text_error_delivery',
            'retailcrm_missing_status'
        );

        $_data = &$data;

        foreach ($text_strings as $text) {
            $_data[$text] = $this->language->get($text);
        }

        $_data['retailcrm_errors'] = array();
        $_data['saved_settings'] = $this->model_setting_setting
            ->getSetting($this->moduleTitle);

        $url = isset($_data['saved_settings'][$this->moduleTitle . '_url'])
            ? $_data['saved_settings'][$this->moduleTitle . '_url']
            : null;
        $key = isset($_data['saved_settings'][$this->moduleTitle . '_apikey'])
            ? $_data['saved_settings'][$this->moduleTitle . '_apikey']
            : null;
        $apiVersion = isset($_data['saved_settings'][$this->moduleTitle . '_apiversion'])
            ? $_data['saved_settings'][$this->moduleTitle . '_apiversion']
            : null;

        if (!empty($url) && !empty($key)) {

            $_data['delivery'] = $this->model_extension_retailcrm_references
                ->getDeliveryTypes();
            $_data['statuses'] = $this->model_extension_retailcrm_references
                ->getOrderStatuses();
            $_data['payments'] = $this->model_extension_retailcrm_references
                ->getPaymentTypes();
            
            if ($apiVersion == 'v5') {
                $_data['customFields'] = $this->model_extension_retailcrm_references
                    ->getCustomFields();
            }
        }

        $config_data = array(
            $this->moduleTitle . '_status'
        );

        foreach ($config_data as $conf) {
            if (isset($this->request->post[$conf])) {
                $_data[$conf] = $this->request->post[$conf];
            } else {
                $_data[$conf] = $this->config->get($conf);
            }
        }

        if (isset($this->_error['warning'])) {
            $_data['error_warning'] = $this->_error['warning'];
        } else {
            $_data['error_warning'] = '';
        }

        $_data['breadcrumbs'] = array();

        $_data['breadcrumbs'][] = array(
            'text'      => $this->language->get('text_home'),
            'href'      => $this->url->link(
                'common/home',
                $tokenTitle . '=' . $this->session->data[$tokenTitle], 'SSL'
            ),
            'separator' => false
        );

        $_data['breadcrumbs'][] = array(
            'text'      => $this->language->get('text_module'),
            'href'      => $this->url->link(
                'extension/extension/module',
                $tokenTitle . '=' . $this->session->data[$tokenTitle], 'SSL'
            ),
            'separator' => ' :: '
        );

        $_data['breadcrumbs'][] = array(
            'text'      => $this->language->get('retailcrm_title'),
            'href'      => $this->url->link(
                'extension/module/retailcrm',
                $tokenTitle . '=' . $this->session->data[$tokenTitle], 'SSL'
            ),
            'separator' => ' :: '
        );

        $_data['action'] = $this->url->link(
            'extension/module/retailcrm',
            $tokenTitle . '=' . $this->session->data[$tokenTitle], 'SSL'
        );

        $_data['cancel'] = $this->url->link(
            version_compare(VERSION, '3.0', '<') ? 'extension/extension' : 'marketplace/extension',
            $tokenTitle . '=' . $this->session->data[$tokenTitle], 'SSL'
        );

        $_data['modules'] = array();

        if (isset($this->request->post['retailcrm_module'])) {
            $_data['modules'] = $this->request->post['retailcrm_module'];
        } elseif ($this->config->get('retailcrm_module')) {
            $_data['modules'] = $this->config->get('retailcrm_module');
        }

        $this->load->model('design/layout');
        $_data['layouts'] = $this->model_design_layout->getLayouts();

        $_data['header'] = $this->load->controller('common/header');
        $_data['column_left'] = $this->load->controller('common/column_left');
        $_data['footer'] = $this->load->controller('common/footer');
        $_data['countries'] = $this->model_localisation_country->getCountries();
        $_data['catalog'] = $this->request->server['HTTPS'] ? HTTPS_CATALOG : HTTP_CATALOG;
        $_data[$tokenTitle] = $this->request->get[$tokenTitle];

        if(file_exists(DIR_SYSTEM . '/cron/export_done')) {
            $_data['export_file'] = false;
        } else {
            $_data['export_file'] = true;
        }

        $collectorFields = array(
            'name' => $this->language->get('field_name'),
            'email' => $this->language->get('field_email'),
            'phone' => $this->language->get('field_phone')
        );

        $_data['collectorFields'] = $collectorFields;
        $_data['api_versions'] = array('v3', 'v4', 'v5');
        $_data['default_apiversion'] = 'v4';

        $retailcrmLog = file_exists(DIR_SYSTEM . 'storage/logs/retailcrm.log') ? DIR_SYSTEM . 'storage/logs/retailcrm.log' : false;
        $ocApiLog = file_exists(DIR_SYSTEM . 'storage/logs/opencartapi.log') ? DIR_SYSTEM . 'storage/logs/opencartapi.log' : false;

        if ($this->checkLogFile($retailcrmLog) !== false) {
            $_data['logs']['retailcrm_log'] = $this->checkLogFile($retailcrmLog);
        } else {
            $_data['logs']['retailcrm_error'] = $this->language->get('text_error_log');
        }

        if ($this->checkLogFile($ocApiLog) !== false) {
            $_data['logs']['oc_api_log'] = $this->checkLogFile($ocApiLog);
        } else {
            $_data['logs']['oc_error'] = $this->language->get('text_error_log');
        }

        $_data['clear_retailcrm'] = $this->url->link('extension/module/retailcrm/clear_retailcrm', $tokenTitle . '=' . $this->session->data[$tokenTitle], true);
        $_data['clear_opencart'] = $this->url->link('extension/module/retailcrm/clear_opencart', $tokenTitle . '=' . $this->session->data[$tokenTitle], true);
        $_data['button_clear'] = $this->language->get('button_clear');

        $this->response->setOutput(
            $this->load->view('extension/module/retailcrm', $_data)
        );

    }

    /**
     * History method
     *
     * @return void
     */
    public function history()
    {
        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting->getSetting($this->moduleTitle);

        if ($settings[$this->moduleTitle . '_apiversion'] == 'v3') {
            if (file_exists(DIR_APPLICATION . 'model/extension/retailcrm/custom/history/v3.php')) {
                $this->load->model('extension/retailcrm/custom/history/v3');
                $this->model_extension_retailcrm_custom_history_v3->request();
            } else {
                $this->load->model('extension/retailcrm/history/v3');
                $this->model_extension_retailcrm_history_v3->request();
            }
        } else {
            if (file_exists(DIR_APPLICATION . 'model/extension/retailcrm/custom/history/v4-5.php')) {
                $this->load->model('extension/retailcrm/custom/history/v4-5');
                $this->model_extension_retailcrm_custom_history_v4_5->request();
            } else {
                $this->load->model('extension/retailcrm/history/v4_5');
                $this->model_extension_retailcrm_history_v4_5->request();
            }
        }
    }

    /**
     * ICML generation
     *
     * @return void
     */
    public function icml()
    {
        if (file_exists(DIR_APPLICATION . 'model/extension/retailcrm/custom/icml.php')) {
            $this->load->model('extension/retailcrm/custom/icml');
            $this->model_extension_retailcrm_custom_icml->generateICML();
        } else {
            $this->load->model('extension/retailcrm/icml');
            $this->model_extension_retailcrm_icml->generateICML();
        }
    }

    /**
     * Create order on event
     *
     * @param int $order_id order identificator
     *
     * @return void
     */
    public function order_create($order_id)
    {
        $this->load->model('checkout/order');
        $this->load->model('account/order');

        $data = $this->model_checkout_order->getOrder($order_id);
        $data['products'] = $this->model_account_order->getOrderProducts($order_id);

        if (!isset($data['fromApi'])) {
            $this->load->model('setting/setting');
            $status = $this->model_setting_setting->getSetting('retailcrm');
            $data['order_status'] = $status['retailcrm_status'][$data['order_status_id']];

            $this->load->model('extension/retailcrm/order');
            $this->model_extension_retailcrm_order->sendToCrm($data, $data['order_id']);
        }
    }

    /**
     * Update customer on event
     *
     * @param int $customer_id customer identificator
     *
     * @return void
     */
    public function customer_edit($route, $customer)
    {   
        $this->load->model('localisation/country');
        $this->load->model('localisation/zone');
        $this->load->model('customer/customer');

        $customerId = $customer[0];
        $customer = $customer[1];
        $addresses = $customer['address'];
        unset($customer);

        $customer = $this->model_customer_customer->getCustomer($customerId);

        foreach ($addresses as $address) {
            $country = $this->model_localisation_country->getCountry($address['country_id']);
            $zone = $this->model_localisation_zone->getZone($address['zone_id']);

            $customer['address'] = array(
                'address_1' => $address['address_1'],
                'address_2' => $address['address_2'],
                'city' => $address['city'],
                'postcode' => $address['postcode'],
                'iso_code_2' => $country['iso_code_2'],
                'zone' => $zone['name']
            );    
        }

        $this->load->model('extension/retailcrm/customer');
        $this->model_extension_retailcrm_customer->changeInCrm($customer);
    }

    /**
     * Export single order
     *
     *
     */
    public function exportOrder()
    {
        $order_id = isset($this->request->get['order_id']) ? $this->request->get['order_id'] : '';
        $this->load->model('sale/order');

        $data = $this->model_sale_order->getOrder($order_id);
        $data['products'] = $this->model_sale_order->getOrderProducts($order_id);
        $data['totals'] = $this->model_sale_order->getOrderTotals($order_id);

        if (!isset($data['fromApi'])) {
            $this->load->model('setting/setting');
            $status = $this->model_setting_setting->getSetting($this->moduleTitle);
            $data['order_status'] = $status[$this->moduleTitle . '_status'][$data['order_status_id']];

            $this->load->model('extension/retailcrm/order');
            $response = $this->model_extension_retailcrm_order->uploadOrder($data);
        }

        if (!$response->isSuccessful()) {
            if (isset($response['errors'])) {
                $error = implode("\n", $response['errors']);
            } else {
                $error = $response->getErrorMsg();
            }

            $this->response->setOutput(
                json_encode(
                    array(
                        'status_code' => $response->getStatusCode(),
                        'error_msg' => $error
                    )
                )
            );
        } else {
            $this->response->setOutPut(
                json_encode(
                    array(
                        'status_code' => $response->getStatusCode()
                    )
                )
            );
        }
    }

    /**
     * Export orders
     *
     * @return void
     */
    public function export() {

        $this->load->model('customer/customer');
        $customers = $this->model_customer_customer->getCustomers();

        $this->load->model('extension/retailcrm/customer');
        $this->model_extension_retailcrm_customer->uploadToCrm($customers);

        $this->load->model('sale/order');
        $orders = $this->model_sale_order->getOrders();

        $fullOrders = array();

        foreach($orders as $order) {
            $fullOrder = $this->model_sale_order->getOrder($order['order_id']);

            $fullOrder['order_total'] = $this->model_sale_order->getOrderTotals($order['order_id']);
            $fullOrder['products'] = $this->model_sale_order->getOrderProducts($order['order_id']);

            foreach($fullOrder['products'] as $key=>$product) {
                $fullOrder['products'][$key]['option'] = $this->model_sale_order->getOrderOptions($product['order_id'], $product['order_product_id']);
            }

            $fullOrders[] = $fullOrder;
        }

        $this->load->model('extension/retailcrm/order');
        $this->model_extension_retailcrm_order->uploadToCrm($fullOrders);
        fopen(DIR_SYSTEM . '/cron/export_done', "x");
    }

    /**
     * Validate
     *
     * @return bool
     */
    private function validate()
    {
        $versionsMap = array(
            'v3' => '3.0',
            'v4' => '4.0',
            'v5' => '5.0'
        );

        if (!empty($this->request->post[$this->moduleTitle . '_url']) && !empty($this->request->post[$this->moduleTitle . '_apikey'])) {
            $apiClient = $this->retailcrm->getApiClient(
                $this->request->post[$this->moduleTitle . '_url'],
                $this->request->post[$this->moduleTitle . '_apikey']
            );
        }

        $response = $apiClient->apiVersions();

        if ($response && $response->isSuccessful()) {
            if (!in_array($versionsMap[$this->request->post[$this->moduleTitle . '_apiversion']], $response['versions'])) {
                $this->_error['warning'] = $this->language->get('text_error_api');
            }
        } else {
            $this->_error['warning'] = $this->language->get('text_error_save');
        }

        if (!$this->user->hasPermission('modify', 'extension/module/retailcrm')) {
            $this->_error['warning'] = $this->language->get('error_permission');
        }

        if (isset($this->request->post[$this->moduleTitle . '_collector']['custom']) &&
            $this->request->post[$this->moduleTitle . '_collector']['custom_form'] == 1) {
            $customField = $this->request->post[$this->moduleTitle . '_collector']['custom'];

            if (empty($customField['name']) && empty($customField['email']) && empty($customField['phone'])) {
                $this->_error['fields'] = $this->language->get('text_error_collector_fields');
            }
        }

        if (!$this->_error) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Clear retailcrm log file
     * 
     * @return void
     */
    public function clear_retailcrm()
    {
        $tokenTitle = $this->getTokenTitle();

        if ($this->user->hasPermission('modify', 'extension/module/retailcrm')) {
            $file = DIR_LOGS . 'retailcrm.log';

            $handle = fopen($file, 'w+');

            fclose($handle);
        }

        $this->response->redirect($this->url->link('extension/module/retailcrm', $tokenTitle . '=' . $this->session->data[$tokenTitle], true));
    }

    /**
     * Clear opencart API log file
     * 
     * @return void
     */
    public function clear_opencart()
    {   
        $tokenTitle = $this->getTokenTitle();

        if ($this->user->hasPermission('modify', 'extension/module/retailcrm')) {
            $file = DIR_LOGS . 'opencartapi.log';

            $handle = fopen($file, 'w+');

            fclose($handle);
        }

        $this->response->redirect($this->url->link('extension/module/retailcrm', $tokenTitle . '=' . $this->session->data[$tokenTitle], true));
    }

    /**
     * Method for load modelds
     * 
     * @return void
     */
    private function loadModels()
    {
        if (version_compare(VERSION, '3.0', '<')) {
            $this->load->model('extension/event');
            $this->load->model('extension/extension');
            // $this->load->model('extension/module');

            $this->modelEvent = 'extension_event';
            $this->modelExtension = 'extension_extension';
            // $this->modelModule = 'extension_module';
        } else {
            $this->load->model('setting/event');
            $this->load->model('setting/extension');
            // $this->load->model('setting/module');

            $this->modelEvent = 'setting_event';
            $this->modelExtension = 'setting_extension';
            // $this->modelModule = 'setting_module';
        }
    }

    /**
     * Get collector module name
     * 
     * @return string
     */
    private function getCollectorTitle()
    {
        if (version_compare(VERSION, '3.0', '<')) {
            $title = 'daemon_collector';
        } else {
            $title = 'analytics_daemon_collector';
        }

        return $title;
    }

    /**
     * Check file size
     * 
     * @return string
     */
    private function checkLogFile($file)
    {
        $logs = '';

        if ($file === false) {
            return $logs;
        }

        if (filesize($file) < 2097152) {
            $logs .= file_get_contents($file, FILE_USE_INCLUDE_PATH, null);
        } else {
            return false;
        }

        return $logs;
    }

    /**
     * Add events to db
     * 
     * @return void
     */
    private function addEvents()
    {
        $this->loadModels();

        $this->{'model_' . $this->modelEvent}
            ->addEvent(
                $this->moduleTitle,
                'catalog/model/checkout/order/addOrder/after',
                'extension/module/retailcrm/order_create'
            );

        $this->{'model_' . $this->modelEvent}
            ->addEvent(
                $this->moduleTitle,
                'catalog/model/checkout/order/addOrderHistory/after',
                'extension/module/retailcrm/order_edit'
            );

        $this->{'model_' . $this->modelEvent}
            ->addEvent(
                $this->moduleTitle,
                'catalog/model/account/customer/addCustomer/after',
                'extension/module/retailcrm/customer_create'
            );

        $this->{'model_' . $this->modelEvent}
            ->addEvent(
                $this->moduleTitle,
                'catalog/model/account/customer/editCustomer/after',
                'extension/module/retailcrm/customer_edit'
            );

        $this->{'model_' . $this->modelEvent}
            ->addEvent(
                $this->moduleTitle,
                'catalog/model/account/address/editAddress/after',
                'extension/module/retailcrm/customer_edit'
            );

        $this->{'model_' . $this->modelEvent}
            ->addEvent(
                $this->moduleTitle,
                'admin/model/customer/customer/editCustomer/after',
                'extension/module/retailcrm/customer_edit'
            );
    }

    /**
     * Check events in db
     * 
     * @return boolean
     */
    private function checkEvents()
    {
        $events = $this->{'model_' . $this->modelEvent}->getEvent(
            'retailcrm',
            'catalog/model/checkout/order/addOrder/after',
            'extension/module/retailcrm/order_create'
        );

        if (!empty($events)) {
            return true;
        }

        return false;
    }

    /**
     * Delete events from db
     * 
     * @return void
     */
    private function deleteEvents()
    {
        $this->loadModels();

        if (version_compare(VERSION, '3.0', '<')) {
            $this->{'model_' . $this->modelEvent}->deleteEvent($this->moduleTitle);
        } else {
            $this->{'model_' . $this->modelEvent}->deleteEventByCode($this->moduleTitle);
        }
    }
}
