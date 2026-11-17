<?php

namespace Icinga\Module\Starlink\Controllers;

use Icinga\Module\Starlink\Db;
use Icinga\Module\Starlink\Format;
use Icinga\Module\Starlink\Settings;
use Icinga\Module\Starlink\Web\Controller;
use ipl\Html\Html;

class HealthController extends Controller
{
    public function indexAction()
    {
        $this->addTitleTab($this->translate('Starlink Collector'));
        $this->setAutorefreshInterval(15);
        if (! $this->backendReady()) {
            return;
        }
        $d = Db::row(
            'SELECT *, EXTRACT(EPOCH FROM started_at) AS start_e, EXTRACT(EPOCH FROM last_success_at) AS ok_e,'
            . ' EXTRACT(EPOCH FROM last_poll_at) AS poll_e, EXTRACT(EPOCH FROM last_error_at) AS err_e'
            . ' FROM starlink_daemon WHERE id = 1'
        );
        $counts = Db::row(
            'SELECT (SELECT count(*) FROM starlink_terminal) AS terminals,'
            . ' (SELECT count(*) FROM starlink_router) AS routers,'
            . ' (SELECT count(*) FROM starlink_sample) AS samples,'
            . ' (SELECT EXTRACT(EPOCH FROM min(ts)) FROM starlink_sample) AS oldest'
        );
        $num = function ($v) {
            return $v === null ? null : (float) $v;
        };
        $this->addContent($this->nameValue([
            $this->translate('Account')          => trim(($d['account_name'] ?? '') . ' ' . ($d['account_number'] ?? '')),
            $this->translate('Daemon host / pid') => ($d['hostname'] ?? '-') . ' / ' . ($d['pid'] ?? '-'),
            $this->translate('Started')          => Format::dateTime($num($d['start_e'])),
            $this->translate('Last successful poll') => Format::ago($num($d['ok_e'])),
            $this->translate('Last poll attempt') => Format::ago($num($d['poll_e'])),
            $this->translate('Last error')       => $d['last_error']
                ? Format::ago($num($d['err_e'])) . ': ' . $d['last_error'] : $this->translate('none'),
            $this->translate('Rows received')    => (string) $d['rows_total'],
            $this->translate('Terminals / routers') => $counts['terminals'] . ' / ' . $counts['routers'],
            $this->translate('Raw samples kept') => sprintf(
                '%d (oldest %s, retention %dh)',
                $counts['samples'], Format::ago($num($counts['oldest'])), Settings::retentionHours()
            ),
            $this->translate('Activity threshold') => Format::mbps(Settings::activityThresholdMbps()) . sprintf(
                ' (%d samples to start, %d to end)',
                Settings::activityStartSamples(), Settings::activityEndSamples()
            ),
        ]));
        $columns = json_decode((string) $d['columns'], true) ?: [];
        if ($columns) {
            $this->addContent($this->h2($this->translate('Telemetry columns (last response)')));
            $pairs = [];
            foreach ($columns as $type => $names) {
                $pairs[$type] = implode(', ', (array) $names);
            }
            $this->addContent($this->nameValue($pairs));
        }
        $enums = json_decode((string) $d['alert_enums'], true) ?: [];
        if ($enums) {
            $this->addContent($this->h2($this->translate('Known alerts')));
            $pairs = [];
            foreach ($enums as $type => $map) {
                $pairs[$type] = implode(', ', array_values((array) $map));
            }
            $this->addContent($this->nameValue($pairs));
        }
    }
}
