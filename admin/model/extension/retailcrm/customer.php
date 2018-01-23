<?php

class ModelExtensionRetailcrmCustomer extends Model {
    protected $settings;
    protected $moduleTitle;
    protected $retailcrmApiClient;

    public function __construct($registry)
    {
        parent::__construct($registry);
        $this->load->model('setting/setting');
        $this->load->library('retailcrm/retailcrm');

        $this->moduleTitle = $this->retailcrm->getModuleTitle();
        $this->settings = $this->model_setting_setting->getSetting($this->moduleTitle);
        $this->retailcrmApiClient = $this->retailcrm->getApiClient();
    }

    /**
     * Upload customers
     * 
     * @param array $customers
     * 
     * @return void
     */
    public function uploadToCrm($customers) 
    {
        if ($this->retailcrmApiClient === false || empty($customers)) {
            return;
        }

        $customersToCrm = array();

        foreach($customers as $customer) {
            $customersToCrm[] = $this->process($customer);
        }

        $chunkedCustomers = array_chunk($customersToCrm, 50);

        foreach($chunkedCustomers as $customersPart) {
            $this->retailcrmApiClient->customersUpload($customersPart);
        }
    }

    /**
     * Edit customer
     * 
     * @param array $customer
     * 
     * @return void
     */
    public function changeInCrm($customer) 
    {
        if ($this->retailcrmApiClient === false || empty($customer)) {
            return;
        }

        $customerToCrm = $this->process($customer);
        
        $this->retailcrmApiClient->customersEdit($customerToCrm);
    }

    /**
     * Process customer
     * 
     * @param array $customer
     * 
     * @return array $customerToCrm
     */
    private function process($customer)
    {
        
        $customerToCrm = array(
            'externalId' => $customer['customer_id'],
            'firstName' => $customer['firstname'],
            'lastName' => $customer['lastname'],
            'email' => $customer['email'],
            'phones' => array(
                array(
                    'number' => $customer['telephone']
                )
            ),
            'createdAt' => $customer['date_added']
        );

        if (isset($customer['address'])) {
            $customerToCrm['address'] = array(
                'index' => $customer['address']['postcode'],
                'countryIso' => $customer['address']['iso_code_2'],
                'region' => $customer['address']['zone'],
                'city' => $customer['address']['city'],
                'text' => $customer['address']['address_1'] . ' ' . $customer['address']['address_2'] 
            );
        }
        
        if (isset($this->settings[$this->moduleTitle . '_custom_field']) && $customer['custom_field']) {
            $customFields = json_decode($customer['custom_field']);
            
            foreach ($customFields as $key => $value) {
                if (isset($this->settings[$this->moduleTitle . '_custom_field']['c_' . $key])) {
                    $customFieldsToCrm[$this->settings[$this->moduleTitle . '_custom_field']['c_' . $key]] = $value;
                }
            }

            if (isset($customFieldsToCrm)) {
                $customerToCrm['customFields'] = $customFieldsToCrm;
            }
        }
        
        return $customerToCrm;
    }
}
