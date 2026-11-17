<?php

namespace Icinga\Module\Starlink\Controllers;

use Icinga\Module\Starlink\Db;
use Icinga\Module\Starlink\Format;
use Icinga\Module\Starlink\Web\Controller;
use ipl\Html\Html;
use ipl\Html\Table;

class AlertsController extends Controller
{
    public function indexAction()
    {
        $this->addTitleTab($this->translate('Starlink Alerts'));
        $this->setAutorefreshInterval(30);
        if (! $this->backendReady()) {
            return;
        }
        $rows = Db::all(
            'SELECT h.device_id, t.nickname, h.alert, EXTRACT(EPOCH FROM h.raised_at) AS r_e,'
            . ' EXTRACT(EPOCH FROM h.cleared_at) AS c_e'
            . ' FROM starlink_alert_history h JOIN starlink_terminal t ON t.device_id = h.device_id'
            . ' WHERE h.cleared_at IS NULL OR h.raised_at > now() - interval \'14 days\''
            . ' ORDER BY (h.cleared_at IS NULL) DESC, h.raised_at DESC LIMIT 500'
        );
        if (empty($rows)) {
            $this->addContent(Html::tag('p', $this->translate('No alerts in the last 14 days')));
            return;
        }
        $table = $this->table([
            $this->translate('Terminal'), $this->translate('Alert'), $this->translate('Raised'),
            $this->translate('Cleared'), $this->translate('Duration'),
        ]);
        foreach ($rows as $a) {
            $end = $a['c_e'] === null ? time() : (float) $a['c_e'];
            $table->getBody()->addHtml(Table::row([
                $this->terminalLink($a['device_id'], $a['nickname']),
                $a['alert'],
                Format::dateTime((float) $a['r_e']),
                $a['c_e'] === null ? $this->translate('still active') : Format::dateTime((float) $a['c_e']),
                Format::duration($end - (float) $a['r_e']),
            ]));
        }
        $this->addContent($table);
    }
}
