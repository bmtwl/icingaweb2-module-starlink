<?php

namespace Icinga\Module\Starlink\Forms\Config;

use Icinga\Data\ResourceFactory;
use Icinga\Forms\ConfigForm;

class BackendConfigForm extends ConfigForm
{
    public function init()
    {
        $this->setName('form_config_starlink_backend');
        $this->setSubmitLabel($this->translate('Save Changes'));
    }

    public function createElements(array $formData)
    {
        $resources = [];
        foreach (ResourceFactory::getResourceConfigs('db') as $name => $config) {
            if ($config->get('db') === 'pgsql') {
                $resources[$name] = $name;
            }
        }
        $this->addElement('select', 'db_resource', [
            'label'        => $this->translate('Database resource'),
            'description'  => $this->translate('PostgreSQL resource holding the Starlink data'),
            'multiOptions' => array_merge(['' => $this->translate('(please choose)')], $resources),
            'required'     => true,
        ]);
        $this->addElement('text', 'api_credentials_file', [
            'label'       => $this->translate('API credentials file'),
            'description' => $this->translate('KEY=VALUE file with CLIENT_ID and CLIENT_SECRET of a v2 service'
                . ' account (Device telemetry: View). Only the daemon user needs to read it.'),
            'placeholder' => '/etc/icinga-starlink/credentials.env',
        ]);
        $this->addElement('text', 'collector_retention_hours', [
            'label'       => $this->translate('Raw sample retention (hours)'),
            'description' => $this->translate('Long-term history comes from check perfdata'),
            'placeholder' => '48',
        ]);
        $this->addElement('text', 'collector_activity_threshold_mbps', [
            'label'       => $this->translate('Activity threshold (Mbps, down+up)'),
            'description' => $this->translate('Used for activity periods and as default --active-threshold'),
            'placeholder' => '0.5',
        ]);
        $this->addElement('text', 'collector_activity_start_samples', [
            'label'       => $this->translate('Samples above threshold to open an activity period'),
            'description' => $this->translate('15s each'),
            'placeholder' => '2',
        ]);
        $this->addElement('text', 'collector_activity_end_samples', [
            'label'       => $this->translate('Samples below threshold to close an activity period'),
            'description' => $this->translate('15s each'),
            'placeholder' => '8',
        ]);
        $this->addElement('text', 'collector_offline_gap_seconds', [
            'label'       => $this->translate('Telemetry gap that counts as offline (seconds)'),
            'placeholder' => '120',
        ]);
    }
}
