<?php

namespace Icinga\Module\Starlink\Controllers;

use Icinga\Module\Starlink\Db;
use Icinga\Module\Starlink\Format;
use Icinga\Module\Starlink\Web\Controller;
use Icinga\Module\Starlink\Location;
use ipl\Html\Table;

class TerminalsController extends Controller
{
    public function indexAction()
    {
        $this->addTitleTab($this->translate('Starlink Terminals'));
        $this->setAutorefreshInterval(15);
        if (! $this->backendReady()) {
            return;
        }

        $rows = Db::all(
            'SELECT t.*, EXTRACT(EPOCH FROM t.last_seen) AS last_seen_e,'
            . ' EXTRACT(EPOCH FROM t.online_since) AS online_since_e,'
            . ' h.d_avg, h.u_avg'
            . ' FROM starlink_terminal t'
            . ' LEFT JOIN LATERAL (SELECT avg(downlink_mbps) AS d_avg, avg(uplink_mbps) AS u_avg'
            . '   FROM starlink_sample s WHERE s.device_id = t.device_id AND s.ts > now() - interval \'1 hour\') h ON true'
            . ' ORDER BY (t.last_seen > now() - interval \'2 minutes\') DESC,'
            . ' (t.nickname IS NULL), lower(t.nickname), t.device_id'
        );
        if (empty($rows)) {
            $this->addContent($this->h2($this->translate('No terminals have reported telemetry yet')));
            return;
        }

        $locations = Location::forTerminals();
        $features = [];
        foreach ($rows as $t) {
            if (isset($locations[$t['device_id']])) {
                $features[] = $this->mapFeature($t, $locations[$t['device_id']], true);
            }
        }
        if ($features) {
            $this->addContent($this->mapWidget($features, 'overview', 'overview'));
        } elseif (Location::$error !== null) {
            $this->addContent($this->mapUnavailable());
        }

        $table = $this->table([
            $this->translate('Terminal'),
            $this->translate('Location'),
            $this->translate('Status'),
            $this->translate('Last telemetry'),
            $this->translate('Now (down / up)'),
            $this->translate('1h avg (down / up)'),
            $this->translate('Latency'),
            $this->translate('Ping drop'),
            $this->translate('Uptime'),
            $this->translate('Software'),
        ]);
        foreach ($rows as $t) {
            $loc = $locations[$t['device_id']] ?? null;
            $table->getBody()->addHtml(Table::row([
                $this->terminalLink($t['device_id'], $t['nickname']),
                $loc === null ? '-' : sprintf('%.3F, %.3F', $loc['lat'], $loc['lng']),
                $this->status($t),
                Format::ago($t['last_seen_e'] === null ? null : (float) $t['last_seen_e']),
                Format::mbps($t['downlink_mbps'] === null ? null : (float) $t['downlink_mbps'])
                    . ' / ' . Format::mbps($t['uplink_mbps'] === null ? null : (float) $t['uplink_mbps']),
                Format::mbps($t['d_avg'] === null ? null : (float) $t['d_avg'])
                    . ' / ' . Format::mbps($t['u_avg'] === null ? null : (float) $t['u_avg']),
                $t['latency_ms'] === null ? '-' : sprintf('%.0f ms', $t['latency_ms']),
                Format::pct($t['ping_drop'] === null ? null : (float) $t['ping_drop']),
                Format::duration($t['uptime_s'] === null ? null : (float) $t['uptime_s']),
                $t['software_version'] ?? '-',
            ]));
        }
        $this->addContent($table);
    }
}
