<?php

namespace Icinga\Module\Starlink\ProvidedHook\Director;

use Icinga\Module\Director\Hook\ImportSourceHook;
use Icinga\Module\Director\Web\Form\QuickForm;
use Icinga\Module\Starlink\Db;
use Icinga\Module\Starlink\Format;

/**
 * Terminals known from telemetry. Role (failover/primary) is intentionally
 * not provided: set it in Director (data list / property modifier).
 */
class ImportSource extends ImportSourceHook
{
    public function getName()
    {
        return 'Starlink terminals (telemetry)';
    }

    public static function addSettingsFormFields(QuickForm $form)
    {
        $form->addElement('text', 'seen_within_days', [
            'label'       => 'Seen within (days)',
            'description' => 'Only import terminals that reported telemetry within this many days.'
                . ' 0 imports everything ever seen.',
            'value'       => '0',
        ]);
    }
    public function listColumns()
    {
        return [
            'device_id', 'nickname', 'first_seen', 'last_seen', 'online', 'software_version', 'country_code',
            'ipv4', 'ipv4_list', 'ipv6_ue_list', 'ipv6_cpe_list', 'account_number', 'account_name',
            'latitude', 'longitude', 'location_radius_km', 'h3_cell',
        ];
    }

    public function fetchData()
    {
        $days = (int) $this->getSetting('seen_within_days', 0);
        $account = Db::row('SELECT account_number, account_name FROM starlink_daemon WHERE id = 1') ?? [];
        $locations = Location::forTerminals();
        $sql = 'SELECT device_id, nickname, software_version, country_code, ipv4, ipv6_ue, ipv6_cpe,'
            . ' EXTRACT(EPOCH FROM first_seen) AS first_e, EXTRACT(EPOCH FROM last_seen) AS last_e'
            . ' FROM starlink_terminal';
        $params = [];
        if ($days > 0) {
            $sql .= ' WHERE last_seen > now() - (CAST(? AS integer) * interval \'1 day\')';
            $params[] = $days;
        }
        $result = [];
        foreach (Db::all($sql . ' ORDER BY device_id', $params) as $t) {
            $ipv4 = Format::jsonList($t['ipv4']);
            $loc = $locations[$t['device_id']] ?? null;
            $result[] = (object) [
                'device_id'          => $t['device_id'],
                'nickname'           => $t['nickname'],
                'first_seen'         => date('c', (int) $t['first_e']),
                'last_seen'          => date('c', (int) $t['last_e']),
                'online'             => time() - (float) $t['last_e'] <= 120,
                'software_version'   => $t['software_version'],
                'country_code'       => $t['country_code'],
                'ipv4'               => $ipv4[0] ?? null,
                'ipv4_list'          => $ipv4,
                'ipv6_ue_list'       => Format::jsonList($t['ipv6_ue']),
                'ipv6_cpe_list'      => Format::jsonList($t['ipv6_cpe']),
                'account_number'     => $account['account_number'] ?? null,
                'account_name'       => $account['account_name'] ?? null,
                'latitude'           => $loc === null ? null : round($loc['lat'], 5),
                'longitude'          => $loc === null ? null : round($loc['lng'], 5),
                'location_radius_km' => $loc === null ? null : round($loc['radius_km'], 1),
                'h3_cell'            => $loc === null ? null : $loc['h3'],
            ];
        }

        return $result;
    }
}
