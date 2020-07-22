<?php

namespace retailcrm\service;

class CorporateCustomerBuilder {
    private $data = array();
    private $company;

    public static function create($init = true) {
        $self = new self();

        if ($init) {
            $self->initData();
        }

        return $self;
    }

    public function initData() {
        $this->data = array(
            'customerContacts' => array(
                array(
                    'isMain' => true,
                    'customer' => array()
                )
            ),
            'companies' => array()
        );

        return $this;
    }

    public function build() {
        $this->data['nickName'] = $this->company;

        return $this->data;
    }

    public function setCompany($company) {
        $this->company = $company;

        return $this;
    }

    public function setCustomerId($customer_id) {
        $this->setCustomer($customer_id);

        return $this;
    }

    public function setCustomerExternalId($customer_external_id) {
        $this->setCustomer($customer_external_id, 'externalId');

        return $this;
    }

    public function buildAddress($data, $corp_client) {
        if (!empty($data['address_id'])) {
            $address = array(
                'externalId' => AddressIdentifier::createAddressExternalId($corp_client, $data),
                'index' => $data['postcode'],
                'countryIso' => $data['iso_code_2'],
                'region' => $data['zone'],
                'city' => $data['city'],
                'name' => htmlspecialchars_decode($data['company']),
                'text' => $data['address_1'] . ' ' . $data['address_2']
            );
        } else {
            $address = array(
                'index' => $data['shipping_postcode'],
                'countryIso' => $data['shipping_iso_code_2'],
                'region' => $data['shipping_zone'],
                'city' => $data['shipping_city'],
                'name' => $data['payment_company'],
                'text' => $data['shipping_address_1'] . ' ' . $data['shipping_address_2']
            );
        }

        return $address;
    }

    public function addAddress($data, $corp_client) {
        $this->data['addresses'][] = $this->buildAddress($data, $corp_client);

        return $this;
    }

    public function buildLegalAddress($data) {
        if (!empty($data['address_id'])) {
            $legalAddress = sprintf(
                "%s %s %s %s %s",
                $data['postcode'],
                $data['zone'],
                $data['city'],
                $data['address_1'],
                $data['address_2']
            );
        } else {
            $legalAddress = sprintf(
                "%s %s %s %s %s",
                $data['payment_postcode'],
                $data['payment_zone'],
                $data['payment_city'],
                $data['payment_address_1'],
                $data['payment_address_2']
            );
        }

        return $legalAddress;
    }

    public function buildCompany($data) {
        return array(
            'isMain' => true,
            'name' => $this->company,
            'contragent' => array(
                'legalAddress' => $this->buildLegalAddress($data)
            )
        );
    }

    public function addCompany($data) {
        $this->data['companies'][] = $this->buildCompany($data);

        return $this;
    }

    private function setCustomer($id, $field = 'id') {
        foreach ($this->data['customerContacts'] as $key => $customerContact) {
            if ($customerContact['isMain']) {
                $this->data['customerContacts'][$key]['customer'][$field] = $id;
            }
        }
    }
}
