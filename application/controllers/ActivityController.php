<?php

namespace Icinga\Module\Starlink\Controllers;

use Icinga\Module\Starlink\Db;
use Icinga\Module\Starlink\Format;
use Icinga\Module\Starlink\Settings;
use Icinga\Module\Starlink\Web\Controller;
use ipl\Html\Html;
use ipl\Html\Table;

class ActivityController extends Controller
{
    public function indexAction()
    {
        $this->addTitleTab($this->translate('Starlink Activity'));
        $this->setAutorefreshInterval(30);
        if (! $this->backendReady()) {
            return;
        }
        $days = max(1, min(400, (int) $this->params->get('days', 30)));
        $rows = Db::all(
            'SELECT a.*, t.nickname, EXTRACT(EPOCH FROM a.started_at) AS s_e, EXTRACT(EPOCH FROM a.ended_at) AS e_e,'
            . ' EXTRACT(EPOCH FROM a.last_active_at) AS l_e'
            . ' FROM starlink_activity a JOIN starlink_terminal t ON t.device_id = a.device_id'
            . ' WHERE a.started_at > now() - (CAST(? AS integer) * interval \'1 day\')'
            . ' ORDER BY a.started_at DESC LIMIT 500',
            [$days]
        );
        $this->addContent(Html::tag('p', sprintf(
            $this->translate('Periods where a terminal carried more than %s combined, last %d days.'
                . ' For failover dishes, each row is a failover event.'),
            Format::mbps(Settings::activityThresholdMbps()),
            $days
        )));
        if (empty($rows)) {
            $this->addContent(Html::tag('p', $this->translate('No activity recorded')));
            return;
        }
        $table = $this->table([
            $this->translate('Terminal'), $this->translate('Started'), $this->translate('Ended'),
            $this->translate('Duration'), $this->translate('Down'), $this->translate('Up'), $this->translate('Peak'),
        ]);
        foreach ($rows as $a) {
            $end = $a['e_e'] === null ? (float) $a['l_e'] : (float) $a['e_e'];
            $table->getBody()->addHtml(Table::row([
                $this->terminalLink($a['device_id'], $a['nickname']),
                Format::dateTime((float) $a['s_e']),
                $a['e_e'] === null ? $this->translate('ongoing') : Format::dateTime((float) $a['e_e']),
                Format::duration($end - (float) $a['s_e'] + 15),
                Format::bytes((float) $a['rx_bytes']),
                Format::bytes((float) $a['tx_bytes']),
                Format::mbps((float) $a['peak_down_mbps']) . ' / ' . Format::mbps((float) $a['peak_up_mbps']),
            ]));
        }
        $this->addContent($table);
    }
}
