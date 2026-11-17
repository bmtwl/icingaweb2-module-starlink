<?php

namespace Icinga\Module\Starlink;

/**
 * Persists one telemetry stream response.
 *
 * Column positions are resolved by name on every response, as Starlink
 * requires. Everything for one response is written in a single transaction.
 */
class Collector
{
    const SAMPLE_SECONDS = 15;

    /** @var array per-batch cache of terminal state rows, keyed by device id */
    protected $terminals = [];

    /** @var array device ids whose state row changed */
    protected $dirty = [];

    /** @var array alert code => name, per device type */
    protected $alertEnums = [];

    /**
     * @return array counters: rows, u, r, i, other, new_samples
     */
    /**
     * @return array counters: rows, u, r, i, other, new_samples
     */
    public function ingest(array $response): array
    {
        $data = $response['data'] ?? [];
        $columns = $data['columnNamesByDeviceType'] ?? [];
        $rows = $data['values'] ?? [];
        $this->alertEnums = $response['metadata']['enums']['AlertsByDeviceType'] ?? [];
        $stats = ['rows' => count($rows), 'u' => 0, 'r' => 0, 'i' => 0, 'other' => 0, 'new_samples' => 0];
        if (empty($rows)) {
            return $stats;
        }

        // decode positional rows into name => value maps
        $decoded = [];
        foreach ($rows as $row) {
            if (! is_array($row) || empty($row)) {
                continue;
            }
            $type = (string) $row[0];
            $names = $columns[$type] ?? null;
            if ($names === null) {
                continue;
            }
            $map = [];
            foreach ($names as $idx => $name) {
                $map[$name] = $row[$idx] ?? null;
            }
            $map['_type'] = $type;
            $map['_epoch'] = isset($map['UtcTimestampNs']) ? ((float) $map['UtcTimestampNs']) / 1e9 : microtime(true);
            $decoded[] = $map;
        }
        // samples may arrive out of order; process chronologically
        usort($decoded, function ($a, $b) {
            return $a['_epoch'] <=> $b['_epoch'];
        });

        $this->terminals = [];
        $this->dirty = [];
        Db::transaction(function () use ($decoded, $columns, &$stats) {
            Db::exec(
                'UPDATE starlink_daemon SET alert_enums = CAST(? AS jsonb), columns = CAST(? AS jsonb) WHERE id = 1',
                [Db::json((object) $this->alertEnums), Db::json((object) $columns)]
            );
            foreach ($decoded as $map) {
                switch ($map['_type']) {
                    case 'u':
                        $stats['u']++;
                        $stats['new_samples'] += $this->terminalRow($map);
                        break;
                    case 'r':
                        $stats['r']++;
                        $this->routerRow($map);
                        break;
                    case 'i':
                        $stats['i']++;
                        $this->ipRow($map);
                        break;
                    default:
                        $stats['other']++;
                        Db::exec(
                            'INSERT INTO starlink_other_row (device_type, device_id, ts, row_data)'
                            . ' VALUES (?, ?, ?, CAST(? AS jsonb))',
                            [$map['_type'], $map['DeviceId'] ?? null, Db::ts($map['_epoch']), Db::json($this->clean($map))]
                        );
                }
            }
            $this->flushTerminals();
            Db::exec('UPDATE starlink_daemon SET rows_total = rows_total + ? WHERE id = 1', [$stats['rows']]);
        });

        return $stats;
    }
    protected function clean(array $map): array
    {
        unset($map['_type'], $map['_epoch']);

        return $map;
    }

    protected function num($value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    protected function alertNames(string $type, $codes): array
    {
        $names = [];
        foreach ((array) $codes as $code) {
            $names[] = $this->alertEnums[$type][(string) $code] ?? ('alert_' . $code);
        }
        sort($names);

        return array_values(array_unique($names));
    }

    protected function loadTerminal(string $id, float $epoch): array
    {
        if (isset($this->terminals[$id])) {
            return $this->terminals[$id];
        }
        $row = Db::row(
            'SELECT device_id, EXTRACT(EPOCH FROM last_sample_ts) AS last_sample, EXTRACT(EPOCH FROM online_since) AS online_since,'
            . ' active_streak, inactive_streak, activity_id, active_alerts FROM starlink_terminal WHERE device_id = ?',
            [$id]
        );
        if ($row === null) {
            Db::exec(
                'INSERT INTO starlink_terminal (device_id, first_seen, last_seen) VALUES (?, ?, ?) ON CONFLICT DO NOTHING',
                [$id, Db::ts($epoch), Db::ts($epoch)]
            );
            $row = [
                'device_id' => $id, 'last_sample' => null, 'online_since' => null,
                'active_streak' => 0, 'inactive_streak' => 0, 'activity_id' => null, 'active_alerts' => '[]',
            ];
        }
        $row['last_sample'] = $row['last_sample'] === null ? null : (float) $row['last_sample'];
        $row['online_since'] = $row['online_since'] === null ? null : (float) $row['online_since'];
        $row['active_streak'] = (int) $row['active_streak'];
        $row['inactive_streak'] = (int) $row['inactive_streak'];
        $row['activity_id'] = $row['activity_id'] === null ? null : (int) $row['activity_id'];
        $row['alerts'] = json_decode((string) $row['active_alerts'], true) ?: [];
        $row['set'] = [];

        return $this->terminals[$id] = $row;
    }

    /** @return int 1 if a new (not previously stored) sample was written */
    protected function terminalRow(array $m): int
    {
        $id = (string) ($m['DeviceId'] ?? '');
        if ($id === '') {
            return 0;
        }
        $epoch = $m['_epoch'];
        $t = $this->loadTerminal($id, $epoch);

        $down = $this->num($m['DownlinkThroughput'] ?? null);
        $up = $this->num($m['UplinkThroughput'] ?? null);
        $alerts = $this->alertNames('u', $m['ActiveAlerts'] ?? ($m['ActiveAlert'] ?? []));

        $inserted = Db::exec(
            'INSERT INTO starlink_sample (device_id, ts, downlink_mbps, uplink_mbps, ping_drop, latency_ms,'
            . ' obstruction, signal_quality, uptime_s, alerts) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CAST(? AS jsonb))'
            . ' ON CONFLICT DO NOTHING',
            [
                $id, Db::ts($epoch), $down, $up,
                $this->num($m['PingDropRateAvg'] ?? null),
                $this->num($m['PingLatencyMsAvg'] ?? null),
                $this->num($m['ObstructionPercentTime'] ?? null),
                $this->num($m['SignalQuality'] ?? null),
                isset($m['Uptime']) ? (int) $m['Uptime'] : null,
                Db::json($alerts),
            ]
        );

        // late / duplicate samples are stored but don't drive state
        if ($inserted === 0 || ($t['last_sample'] !== null && $epoch <= $t['last_sample'])) {
            return $inserted;
        }

        // online tracking
        $gap = $t['last_sample'] === null ? INF : $epoch - $t['last_sample'];
        if ($t['online_since'] === null || $gap > Settings::offlineGapSeconds()) {
            $t['online_since'] = $epoch;
            if ($t['activity_id'] !== null) {
                // went dark mid-activity: close at last active sample
                Db::exec(
                    'UPDATE starlink_activity SET ended_at = last_active_at WHERE id = ? AND ended_at IS NULL',
                    [$t['activity_id']]
                );
                $t['activity_id'] = null;
            }
            $t['active_streak'] = 0;
            $t['inactive_streak'] = 0;
        }

        $this->trackActivity($t, $epoch, (float) $down, (float) $up);
        $this->trackAlerts($t, $epoch, $alerts);

        $t['last_sample'] = $epoch;
        $t['set'] = [
            'last_seen'          => Db::ts($epoch),
            'last_sample_ts'     => Db::ts($epoch),
            'online_since'       => Db::ts($t['online_since']),
            'downlink_mbps'      => $down,
            'uplink_mbps'        => $up,
            'ping_drop'          => $this->num($m['PingDropRateAvg'] ?? null),
            'latency_ms'         => $this->num($m['PingLatencyMsAvg'] ?? null),
            'obstruction'        => $this->num($m['ObstructionPercentTime'] ?? null),
            'signal_quality'     => $this->num($m['SignalQuality'] ?? null),
            'uptime_s'           => isset($m['Uptime']) ? (int) $m['Uptime'] : null,
            'h3_cell'            => isset($m['H3CellId']) ? (int) $m['H3CellId'] : null,
            'swupdate_reboot_s'  => isset($m['SecondsUntilSwupdateRebootPossible'])
                ? (int) $m['SecondsUntilSwupdateRebootPossible'] : null,
            'software_version'   => $m['RunningSoftwareVersion'] ?? null,
            'country_code'       => $m['CountryCode'] ?? null,
            'in_territorial_waters' => isset($m['InTerritorialWaters'])
                ? ($m['InTerritorialWaters'] ? 'true' : 'false') : null,
            'active_alerts'      => Db::json($alerts),
            'last_row'           => Db::json($this->clean($m)),
            'active_streak'      => $t['active_streak'],
            'inactive_streak'    => $t['inactive_streak'],
            'activity_id'        => $t['activity_id'],
        ];
        $this->terminals[$id] = $t;
        $this->dirty[$id] = true;

        return 1;
    }

    protected function trackActivity(array &$t, float $epoch, float $down, float $up): void
    {
        $active = ($down + $up) >= Settings::activityThresholdMbps();
        $rx = $down * 1e6 / 8 * self::SAMPLE_SECONDS;
        $tx = $up * 1e6 / 8 * self::SAMPLE_SECONDS;

        if ($active) {
            $t['active_streak']++;
            $t['inactive_streak'] = 0;
        } else {
            $t['inactive_streak']++;
            $t['active_streak'] = 0;
        }

        if ($t['activity_id'] === null) {
            if ($active && $t['active_streak'] >= Settings::activityStartSamples()) {
                $streak = $t['active_streak'];
                $t['activity_id'] = (int) Db::value(
                    'INSERT INTO starlink_activity (device_id, started_at, last_active_at, samples, rx_bytes, tx_bytes,'
                    . ' peak_down_mbps, peak_up_mbps) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                    [
                        $t['device_id'],
                        Db::ts($epoch - ($streak - 1) * self::SAMPLE_SECONDS),
                        Db::ts($epoch),
                        $streak, $rx * $streak, $tx * $streak, $down, $up,
                    ]
                );
            }

            return;
        }

        Db::exec(
            'UPDATE starlink_activity SET samples = samples + 1, rx_bytes = rx_bytes + ?, tx_bytes = tx_bytes + ?,'
            . ' peak_down_mbps = GREATEST(peak_down_mbps, ?), peak_up_mbps = GREATEST(peak_up_mbps, ?)'
            . ($active ? ', last_active_at = ?' : '') . ' WHERE id = ?',
            $active
                ? [$rx, $tx, $down, $up, Db::ts($epoch), $t['activity_id']]
                : [$rx, $tx, $down, $up, $t['activity_id']]
        );

        if (! $active && $t['inactive_streak'] >= Settings::activityEndSamples()) {
            Db::exec(
                'UPDATE starlink_activity SET ended_at = last_active_at WHERE id = ? AND ended_at IS NULL',
                [$t['activity_id']]
            );
            $t['activity_id'] = null;
        }
    }

    protected function trackAlerts(array &$t, float $epoch, array $alerts): void
    {
        $old = $t['alerts'];
        foreach (array_diff($alerts, $old) as $raised) {
            Db::exec(
                'INSERT INTO starlink_alert_history (device_id, alert, raised_at) VALUES (?, ?, ?)',
                [$t['device_id'], $raised, Db::ts($epoch)]
            );
        }
        foreach (array_diff($old, $alerts) as $cleared) {
            Db::exec(
                'UPDATE starlink_alert_history SET cleared_at = ? WHERE device_id = ? AND alert = ? AND cleared_at IS NULL',
                [Db::ts($epoch), $t['device_id'], $cleared]
            );
        }
        $t['alerts'] = $alerts;
    }

    protected function flushTerminals(): void
    {
        foreach (array_keys($this->dirty) as $id) {
            $set = $this->terminals[$id]['set'];
            $cols = [];
            $params = [];
            foreach ($set as $col => $value) {
                if (in_array($col, ['active_alerts', 'last_row'], true)) {
                    $cols[] = "$col = CAST(? AS jsonb)";
                } elseif ($col === 'in_territorial_waters') {
                    $cols[] = "$col = CAST(? AS boolean)";
                } else {
                    $cols[] = "$col = ?";
                }
                $params[] = $value;
            }
            $params[] = $id;
            Db::exec('UPDATE starlink_terminal SET ' . implode(', ', $cols) . ' WHERE device_id = ?', $params);
        }
    }

    protected function routerRow(array $m): void
    {
        $id = (string) ($m['DeviceId'] ?? '');
        if ($id === '') {
            return;
        }
        $m['ActiveAlerts'] = $this->alertNames('r', $m['ActiveAlerts'] ?? ($m['ActiveAlert'] ?? []));
        $ts = Db::ts($m['_epoch']);
        Db::exec(
            'INSERT INTO starlink_router (device_id, dish_id, first_seen, last_seen, last_row)'
            . ' VALUES (?, ?, ?, ?, CAST(? AS jsonb))'
            . ' ON CONFLICT (device_id) DO UPDATE SET dish_id = EXCLUDED.dish_id,'
            . ' last_seen = GREATEST(starlink_router.last_seen, EXCLUDED.last_seen),'
            . ' last_row = CASE WHEN EXCLUDED.last_seen >= starlink_router.last_seen'
            . ' THEN EXCLUDED.last_row ELSE starlink_router.last_row END',
            [$id, $m['DishId'] ?? null, $ts, $ts, Db::json($this->clean($m))]
        );
    }

    protected function ipRow(array $m): void
    {
        $id = (string) ($m['DeviceId'] ?? '');
        if (strpos($id, 'ip-') === 0) {
            $id = substr($id, 3);
        }
        if ($id === '') {
            return;
        }
        $this->loadTerminal($id, $m['_epoch']); // make sure the terminal row exists
        Db::exec(
            'UPDATE starlink_terminal SET ipv4 = CAST(? AS jsonb), ipv6_ue = CAST(? AS jsonb),'
            . ' ipv6_cpe = CAST(? AS jsonb), ip_seen_at = ?'
            . ' WHERE device_id = ? AND (ip_seen_at IS NULL OR ip_seen_at <= ?)',
            [
                Db::json(array_values((array) ($m['Ipv4'] ?? []))),
                Db::json(array_values((array) ($m['Ipv6Ue'] ?? []))),
                Db::json(array_values((array) ($m['Ipv6Cpe'] ?? []))),
                Db::ts($m['_epoch']), $id, Db::ts($m['_epoch']),
            ]
        );
    }

    /** Delete raw rows past retention. Activity and alert history are kept. */
    public static function cleanup(): array
    {
        $cut = Db::ts(time() - Settings::retentionHours() * 3600);

        return [
            'samples' => Db::exec('DELETE FROM starlink_sample WHERE ts < ?', [$cut]),
            'other'   => Db::exec('DELETE FROM starlink_other_row WHERE ts < ?', [$cut]),
        ];
    }
}
