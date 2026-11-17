<?php

namespace Icinga\Module\Starlink\Controllers;

use Icinga\Exception\NotFoundError;
use Icinga\Module\Starlink\Db;
use Icinga\Module\Starlink\Format;
use Icinga\Module\Starlink\Forms\NicknameForm;
use Icinga\Module\Starlink\Location;
use Icinga\Module\Starlink\Settings;
use Icinga\Module\Starlink\Web\Controller;
use ipl\Html\Html;
use ipl\Html\Table;
use ipl\Web\Url;
use ipl\Web\Widget\Link;

class TerminalController extends Controller
{
    public function indexAction()
    {
        $id = $this->params->getRequired('id');
        $this->setAutorefreshInterval(15);
        if (! $this->backendReady()) {
            $this->addTitleTab($id);
            return;
        }

        $t = Db::row(
            'SELECT *, EXTRACT(EPOCH FROM last_seen) AS last_seen_e, EXTRACT(EPOCH FROM first_seen) AS first_seen_e,'
            . ' EXTRACT(EPOCH FROM online_since) AS online_since_e, EXTRACT(EPOCH FROM ip_seen_at) AS ip_seen_e'
            . ' FROM starlink_terminal WHERE device_id = ?',
            [$id]
        );
        if ($t === null) {
            throw new NotFoundError('Terminal %s has never reported telemetry', $id);
        }
        $this->addTitleTab($this->terminalName($t));

        $num = function ($v) {
            return $v === null ? null : (float) $v;
        };
        $lastSeen = $num($t['last_seen_e']);
        $online = $lastSeen !== null && time() - $lastSeen <= 120;
        $loc = Location::forTerminals($id)[$id] ?? null;

        $nickname = [($t['nickname'] ?? '') !== '' ? $t['nickname'] : '-'];
        if ($this->hasPermission('starlink/nickname')) {
            $nickname[] = ' ';
            $nickname[] = new Link(
                $this->translate('edit'),
                Url::fromPath('starlink/terminal/nickname', ['id' => $id]),
                ['data-base-target' => '_self']
            );
        }

        $this->addContent($this->nameValue([
            $this->translate('Nickname')          => Html::tag('span', null, $nickname),
            $this->translate('Device ID')         => $id,
            $this->translate('Status')            => $this->status($t),
            $this->translate('Last telemetry')    => Format::dateTime($lastSeen) . ' (' . Format::ago($lastSeen) . ')',
            $this->translate('Online since')      => $online ? Format::dateTime($num($t['online_since_e'])) : '-',
            $this->translate('First seen')        => Format::dateTime($num($t['first_seen_e'])),
            $this->translate('Throughput (last)') => Format::mbps($num($t['downlink_mbps'])) . ' down / '
                . Format::mbps($num($t['uplink_mbps'])) . ' up',
            $this->translate('PoP latency')       => $t['latency_ms'] === null ? null : sprintf('%.0f ms', $t['latency_ms']),
            $this->translate('Ping drop')         => Format::pct($num($t['ping_drop'])),
            $this->translate('Obstruction (raw API value)') => $t['obstruction'] === null ? null
                : sprintf('%.6g', $t['obstruction']),
            $this->translate('Signal quality')    => $t['signal_quality'] === null ? null
                : sprintf('%.2f', $t['signal_quality']),
            $this->translate('Uptime')            => Format::duration($num($t['uptime_s'])),
            $this->translate('Software')          => $t['software_version'],
            $this->translate('Reboot for update') => $t['swupdate_reboot_s'] === null || (int) $t['swupdate_reboot_s'] < 0
                ? $this->translate('not deferred') : Format::duration((float) $t['swupdate_reboot_s']),
            $this->translate('Country')           => $t['country_code'],
            $this->translate('Public IPv4')       => implode(', ', Format::jsonList($t['ipv4'])),
            $this->translate('IPv6 (UE)')         => implode(', ', Format::jsonList($t['ipv6_ue'])),
            $this->translate('IPv6 (CPE)')        => implode(', ', Format::jsonList($t['ipv6_cpe'])),
            $this->translate('Location')          => $loc === null ? null : Html::tag('span', null, [
                sprintf('%.4F, %.4F ', $loc['lat'], $loc['lng']),
                sprintf($this->translate('(within ~%.0f km, H3 res %d) '), $loc['radius_km'], $loc['resolution']),
                Html::tag('a', [
                    'href'   => Location::osmUrl($loc['lat'], $loc['lng']),
                    'target' => '_blank',
                    'rel'    => 'noopener noreferrer',
                ], 'OpenStreetMap'),
            ]),
            $this->translate('H3 cell')           => $loc === null ? null : $loc['h3'],
        ]));

        // location map
        $this->addContent($this->h2($this->translate('Location')));
        if ($loc !== null) {
            $this->addContent($this->mapWidget([$this->mapFeature($t, $loc, false)], 'terminal:' . $id, 'detail'));
        } elseif (Location::$error !== null) {
            $this->addContent($this->mapUnavailable());
        } else {
            $this->addContent(Html::tag('p', $this->translate('This terminal has not reported a location')));
        }

        // last hour throughput sparkline
        $samples = Db::all(
            'SELECT EXTRACT(EPOCH FROM ts) AS e, downlink_mbps, uplink_mbps, latency_ms, ping_drop, alerts'
            . ' FROM starlink_sample WHERE device_id = ? AND ts > now() - interval \'1 hour\' ORDER BY ts',
            [$id]
        );
        $this->addContent($this->h2($this->translate('Last hour')));
        if ($samples) {
            $this->addContent($this->sparkline($samples));
            $sum = ['d' => 0.0, 'u' => 0.0, 'dmax' => 0.0, 'umax' => 0.0];
            foreach ($samples as $s) {
                $sum['d'] += (float) $s['downlink_mbps'];
                $sum['u'] += (float) $s['uplink_mbps'];
                $sum['dmax'] = max($sum['dmax'], (float) $s['downlink_mbps']);
                $sum['umax'] = max($sum['umax'], (float) $s['uplink_mbps']);
            }
            $this->addContent($this->nameValue([
                $this->translate('Average') => Format::mbps($sum['d'] / count($samples)) . ' down / '
                    . Format::mbps($sum['u'] / count($samples)) . ' up',
                $this->translate('Peak')    => Format::mbps($sum['dmax']) . ' down / ' . Format::mbps($sum['umax']) . ' up',
                $this->translate('Transferred') => Format::bytes($sum['d'] * 1e6 / 8 * 15) . ' down / '
                    . Format::bytes($sum['u'] * 1e6 / 8 * 15) . ' up',
                $this->translate('Samples') => sprintf('%d of %d expected', count($samples), 240),
            ]));
        } else {
            $this->addContent(Html::tag('p', $this->translate('No samples in the last hour')));
        }

        // activity periods
        $this->addContent($this->h2(sprintf(
            $this->translate('Activity periods (above %s combined)'),
            Format::mbps(Settings::activityThresholdMbps())
        )));
        $this->addContent($this->activityTable(Db::all(
            'SELECT *, EXTRACT(EPOCH FROM started_at) AS s_e, EXTRACT(EPOCH FROM ended_at) AS e_e,'
            . ' EXTRACT(EPOCH FROM last_active_at) AS l_e FROM starlink_activity WHERE device_id = ?'
            . ' ORDER BY started_at DESC LIMIT 25',
            [$id]
        )));

        // alert history
        $this->addContent($this->h2($this->translate('Alert history')));
        $alerts = Db::all(
            'SELECT alert, EXTRACT(EPOCH FROM raised_at) AS r_e, EXTRACT(EPOCH FROM cleared_at) AS c_e'
            . ' FROM starlink_alert_history WHERE device_id = ? ORDER BY raised_at DESC LIMIT 25',
            [$id]
        );
        $table = $this->table([
            $this->translate('Alert'), $this->translate('Raised'), $this->translate('Cleared'), $this->translate('Duration'),
        ]);
        foreach ($alerts as $a) {
            $end = $a['c_e'] === null ? time() : (float) $a['c_e'];
            $table->getBody()->addHtml(Table::row([
                $a['alert'],
                Format::dateTime((float) $a['r_e']),
                $a['c_e'] === null ? $this->translate('still active') : Format::dateTime((float) $a['c_e']),
                Format::duration($end - (float) $a['r_e']),
            ]));
        }
        $this->addContent($alerts ? $table : Html::tag('p', $this->translate('No alerts recorded')));

        // raw last row, for anything not modelled above
        $this->addContent($this->h2($this->translate('Last raw telemetry row')));
        $raw = json_decode((string) $t['last_row'], true) ?: [];
        $pairs = [];
        foreach ($raw as $k => $v) {
            $pairs[$k] = is_array($v) ? Db::json($v) : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v);
        }
        $this->addContent($this->nameValue($pairs));
    }

    public function nicknameAction()
    {
        $this->assertPermission('starlink/nickname');
        $id = $this->params->getRequired('id');
        $t = Db::row('SELECT device_id, nickname FROM starlink_terminal WHERE device_id = ?', [$id]);
        if ($t === null) {
            throw new NotFoundError('Terminal %s has never reported telemetry', $id);
        }
        // no autorefresh here, it would wipe the input while typing
        $this->addTitleTab(sprintf($this->translate('Nickname: %s'), $id));

        $form = (new NicknameForm($id, $t['nickname']))
            ->on(NicknameForm::ON_SUCCESS, function () use ($id) {
                $this->redirectNow('starlink/terminal?id=' . rawurlencode($id));
            })
            ->handleRequest($this->getServerRequest());
        $this->addContent($form);
    }

    protected function activityTable(array $rows)
    {
        if (empty($rows)) {
            return Html::tag('p', $this->translate('No activity recorded'));
        }
        $table = $this->table([
            $this->translate('Started'), $this->translate('Ended'), $this->translate('Duration'),
            $this->translate('Down'), $this->translate('Up'), $this->translate('Peak'),
        ]);
        foreach ($rows as $a) {
            $end = $a['e_e'] === null ? (float) $a['l_e'] : (float) $a['e_e'];
            $table->getBody()->addHtml(Table::row([
                Format::dateTime((float) $a['s_e']),
                $a['e_e'] === null ? $this->translate('ongoing') : Format::dateTime((float) $a['e_e']),
                Format::duration($end - (float) $a['s_e'] + 15),
                Format::bytes((float) $a['rx_bytes']),
                Format::bytes((float) $a['tx_bytes']),
                Format::mbps((float) $a['peak_down_mbps']) . ' / ' . Format::mbps((float) $a['peak_up_mbps']),
            ]));
        }

        return $table;
    }

    protected function sparkline(array $samples)
    {
        $w = 600;
        $h = 80;
        $max = 0.001;
        foreach ($samples as $s) {
            $max = max($max, (float) $s['downlink_mbps'], (float) $s['uplink_mbps']);
        }
        $start = time() - 3600;
        $points = ['down' => [], 'up' => []];
        foreach ($samples as $s) {
            $x = round(((float) $s['e'] - $start) / 3600 * $w, 1);
            $points['down'][] = $x . ',' . round($h - (float) $s['downlink_mbps'] / $max * ($h - 4) - 2, 1);
            $points['up'][] = $x . ',' . round($h - (float) $s['uplink_mbps'] / $max * ($h - 4) - 2, 1);
        }

        return Html::tag('div', ['class' => 'starlink-spark'], [
            Html::tag('svg', ['viewBox' => "0 0 $w $h", 'preserveAspectRatio' => 'none'], [
                Html::tag('polyline', ['class' => 'down', 'points' => implode(' ', $points['down'])]),
                Html::tag('polyline', ['class' => 'up', 'points' => implode(' ', $points['up'])]),
            ]),
            Html::tag('p', ['class' => 'starlink-legend'], [
                Html::tag('span', ['class' => 'down'], $this->translate('down')),
                ' ',
                Html::tag('span', ['class' => 'up'], $this->translate('up')),
                ' ',
                sprintf($this->translate('scale 0 - %s, 60 minutes'), Format::mbps($max)),
            ]),
        ]);
    }
}
