<?php

namespace retailcrm\service;

class SettingsManager extends \retailcrm\Base {
    private $settings;

    public function getSettings() {
        if (!$this->settings) {
            $this->settings = $this->model_setting_setting->getSetting($this->retailcrm->getModuleTitle());
        }

        return $this->settings;
    }

    public function getSetting($key) {
        if (!empty($this->getSettings()[$this->retailcrm->getModuleTitle() . '_' . $key])) {
            return $this->getSettings()[$this->retailcrm->getModuleTitle() . '_' . $key];
        }

        return null;
    }

    public function getPaymentSettings() {
        return $this->getSettings()[$this->retailcrm->getModuleTitle() . '_payment'];
    }

    public function getDeliverySettings() {
        return $this->getSettings()[$this->retailcrm->getModuleTitle() . '_delivery'];
    }

    public function getStatusSettings() {
        return $this->getSettings()[$this->retailcrm->getModuleTitle() . '_status'];
    }
}