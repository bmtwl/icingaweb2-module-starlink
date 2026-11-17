<?php

namespace Icinga\Module\Starlink\Clicommands;

use Icinga\Cli\Command;
use Icinga\Module\Starlink\Db;
use Icinga\Module\Starlink\Format;
use Icinga\Module\Starlink\Settings;

/**
 * Icinga check plugins (read the module database only, never the API)
 */
class CheckCommand extends Command
{
    const STATES = ['ok' => 0, 'warning' => 1, 'critical' => 2, 'unknown' => 3];
    const NAMES = ['OK', 'WARNING', 'CRITICAL', 'UNKNOWN'];
    const SAMPLE_SECONDS = 15;

    /** @var int */
    protected $state = 0;

    /** @var string[] */
    protected $summary = [];

    /** @var string[] */
    protected $details = [];

    /** @var string[] */
    protected $perf = [];

    /**
     * Check one user terminal (dish)
     *
     * USAGE
     *
     *   icingacli starlink check terminal --id <ut...> [options]
     *
     * OPTIONS
     *
     *   --id <device id>          UserTerminal id as shown in telemetry (required)
     *   --role failover|primary   Default: failover
     *   --since <epoch>           Window start; pass $service.last_check$ so the
     *                             window always equals the real check interval
     *   --window <seconds>        Window when --since is missing/invalid (300)
     *   --active-threshold <Mbps> failover: avg down+up that means "carrying
     *                             traffic" (default: module activity threshold)
     *   --active-state <state>    failover: state when active (warning)
     *   --online-state <state>    failover: state when the dish came online
     *                             during the window (warning)
     *   --offline-after <s>       No telemetry for this long = offline (120)
     *   --offline-state <state>   Default: ok for failover, critical for primary
     *   --warn-drop/--crit-drop <%>          ping drop thresholds (5 / 20)
     *   --warn-latency/--crit-latency <ms>   optional
     *   --ignore-alerts <a,b>     alert names to ignore
     *   --critical-alerts <a,b>   alert names that are CRITICAL (default WARNING)
     *   --daemon-stale <s>        UNKNOWN if the collector is older than this (180)
     */
    public function terminalAction()
    {
        $this->guard(function () {
            $this->checkTerminal();
        });
    }

    /**
     * Check the collector daemon
     *
     * USAGE
     *
     *   icingacli starlink check health [--warn <s>] [--crit <s>]
     */
    public function healthAction()
    {
        $this->guard(function () {
            $this->checkHealth();
        });
    }

    protected function guard(callable $fn)
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->state = 3;
            $this->summary = [str_replace(["\r", "\n"], ' ', $e->getMessage())];
            $this->perf = [];
        }
        $this->finish();
    }

    protected function raise(int $state): void
    {
        $order = [0 => 0, 1 => 1, 3 => 2, 2 => 3];
        if ($order[$state] > $order[$this->state]) {
            $this->state = $state;
        }
    }

    protected function stateParam(string $name, string $default): int
    {
        $value = strtolower((string) $this->params->get($name, $default));
        if (! isset(self::STATES[$value])) {
            throw new \InvalidArgumentException("--$name must be ok, warning, critical or unknown");
        }

        return self::STATES[$value];
    }

    protected function floatParam(string $name, ?float $default): ?float
    {
        $value = $this->params->get($name);
        if ($value === null || $value === '' || $value === true) {
            return $default;
        }
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException("--$name must be numeric");
        }

        return (float) $value;
    }

    protected function listParam(string $name): array
    {
        $value = (string) $this->params->get($name, '');

        return array_values(array_filter(array_map('trim', preg_split('/[,\s]+/', $value))));
    }

    protected function addPerf(string $label, $value, string $uom = '', $warn = null, $crit = null, $min = null, $max = null)
    {
        if ($value === null) {
            return;
        }
        $fmt = function ($v) {
            if ($v === null) {
                return '';
            }
            return is_float($v) ? rtrim(rtrim(sprintf('%.6F', $v), '0'), '.') : (string) $v;
        };
        $this->perf[] = rtrim(sprintf(
            "'%s'=%s%s;%s;%s;%s;%s",
            $label, $fmt($value), $uom, $fmt($warn), $fmt($crit), $fmt($min), $fmt($max)
        ), ';');
    }

    protected function finish()
    {
        $line = 'STARLINK ' . self::NAMES[$this->state] . ' - ' . implode('; ', $this->summary);
        if ($this->perf) {
            $line .= ' | ' . implode(' ', $this->perf);
        }
        echo $line . "\n";
        foreach ($this->details as $detail) {
            echo $detail . "\n";
        }
        exit($this->state);
    }

    protected function daemonAge(): ?float
    {
        $value = Db::value('SELECT EXTRACT(EPOCH FROM now() - last_success_at) FROM starlink_daemon WHERE id = 1');

        return $value === null ? null : (float) $value;
    }

    protected function checkTerminal(): void
    {
        $id = $this->params->getRequired('id');
        $role = strtolower((string) $this->params->get('role', 'failover'));
        if (! in_array($role, ['failover', 'primary'], true)) {
            throw new \InvalidArgumentException('--role must be failover or primary');
        }
        $now = time();

        // a dead collector must never look like "all idle"
        $daemonAge = $this->daemonAge();
        $daemonStale = $this->floatParam('daemon-stale', 180.0);
        if ($daemonAge === null || $daemonAge > $daemonStale) {
            throw new \RuntimeException(sprintf(
                'Starlink collector has not succeeded for %s - data not trustworthy',
                $daemonAge === null ? 'ever' : Format::duration($daemonAge)
            ));
        }

        // window: since last check (agnostic to check_interval), clamped
        $retention = Settings::retentionHours() * 3600;
        $since = $this->floatParam('since', null);
        if ($since === null || $since <= 0 || $since >= $now) {
            $since = $now - $this->floatParam('window', 300.0);
        }
        $since = max($now - $retention, min($since, $now - 60));
        $window = $now - $since;

        $t = Db::row(
            'SELECT *, EXTRACT(EPOCH FROM last_seen) AS last_seen_e, EXTRACT(EPOCH FROM online_since) AS online_since_e,'
            . ' EXTRACT(EPOCH FROM first_seen) AS first_seen_e FROM starlink_terminal WHERE device_id = ?',
            [$id]
        );

        $agg = Db::row(
            'SELECT count(*) AS n, avg(downlink_mbps) AS d_avg, max(downlink_mbps) AS d_max, sum(downlink_mbps) AS d_sum,'
            . ' avg(uplink_mbps) AS u_avg, max(uplink_mbps) AS u_max, sum(uplink_mbps) AS u_sum,'
            . ' avg(ping_drop) AS drop_avg, avg(latency_ms) AS lat_avg, max(latency_ms) AS lat_max'
            . ' FROM starlink_sample WHERE device_id = ? AND ts > ?',
            [$id, Db::ts($since)]
        );
        $n = (int) $agg['n'];
        $num = function ($v) {
            return $v === null ? null : (float) $v;
        };
        $dAvg = $n ? $num($agg['d_avg']) : 0.0;
        $uAvg = $n ? $num($agg['u_avg']) : 0.0;
        $dMax = $n ? $num($agg['d_max']) : 0.0;
        $uMax = $n ? $num($agg['u_max']) : 0.0;
        // average over the whole window, so offline gaps count as zero
        $rxBytes = (float) $agg['d_sum'] * 1e6 / 8 * self::SAMPLE_SECONDS;
        $txBytes = (float) $agg['u_sum'] * 1e6 / 8 * self::SAMPLE_SECONDS;
        $rxBps = $rxBytes * 8 / $window;
        $txBps = $txBytes * 8 / $window;
        $dropPct = $agg['drop_avg'] === null ? null : (float) $agg['drop_avg'] * 100;
        $latAvg = $num($agg['lat_avg']);

        $offlineAfter = $this->floatParam('offline-after', 120.0);
        $lastSeen = $t === null ? null : (float) $t['last_seen_e'];
        $silentFor = $lastSeen === null ? null : $now - $lastSeen;
        $offline = $lastSeen === null || $silentFor > $offlineAfter;

        $threshold = $this->floatParam('active-threshold', Settings::activityThresholdMbps());
        $active = ($rxBps + $txBps) / 1e6 >= $threshold;

        if ($offline) {
            $state = $this->stateParam('offline-state', $role === 'failover' ? 'ok' : 'critical');
            $this->raise($state);
            $this->summary[] = $lastSeen === null
                ? 'no telemetry ever received for this terminal'
                : sprintf('offline - no telemetry for %s', Format::duration($silentFor));
        } elseif ($role === 'failover') {
            if ($active) {
                $this->raise($this->stateParam('active-state', 'warning'));
                $this->summary[] = 'FAILOVER ACTIVE - carrying traffic';
            } else {
                $this->summary[] = 'standby';
            }
            $onlineSince = $t['online_since_e'] === null ? null : (float) $t['online_since_e'];
            if ($onlineSince !== null && $onlineSince > $since && (float) $t['first_seen_e'] < $onlineSince) {
                $this->raise($this->stateParam('online-state', 'warning'));
                $this->summary[] = sprintf('came online %s', Format::ago($onlineSince));
            }
        }

        if (! $offline) {
            $warnDrop = $this->floatParam('warn-drop', 5.0);
            $critDrop = $this->floatParam('crit-drop', 20.0);
            if ($dropPct !== null && $critDrop !== null && $dropPct >= $critDrop) {
                $this->raise(2);
                $this->summary[] = sprintf('ping drop %.1f%%', $dropPct);
            } elseif ($dropPct !== null && $warnDrop !== null && $dropPct >= $warnDrop) {
                $this->raise(1);
                $this->summary[] = sprintf('ping drop %.1f%%', $dropPct);
            }
            $warnLat = $this->floatParam('warn-latency', null);
            $critLat = $this->floatParam('crit-latency', null);
            if ($latAvg !== null && $critLat !== null && $latAvg >= $critLat) {
                $this->raise(2);
                $this->summary[] = sprintf('latency %.0f ms', $latAvg);
            } elseif ($latAvg !== null && $warnLat !== null && $latAvg >= $warnLat) {
                $this->raise(1);
                $this->summary[] = sprintf('latency %.0f ms', $latAvg);
            }

            $alerts = Format::jsonList($t['active_alerts']);
            $alerts = array_values(array_diff($alerts, $this->listParam('ignore-alerts')));
            if ($alerts) {
                $critical = array_intersect($alerts, $this->listParam('critical-alerts'));
                $this->raise($critical ? 2 : 1);
                $this->summary[] = 'alerts: ' . implode(', ', $alerts);
            }
        }

        $this->summary[] = sprintf(
            '%s down / %s up avg over %s',
            Format::bps($rxBps), Format::bps($txBps), Format::duration($window)
        );
        if ($rxBytes + $txBytes > 0) {
            $this->summary[] = sprintf('%s / %s transferred', Format::bytes($rxBytes), Format::bytes($txBytes));
        }

        $this->details[] = ($t !== null && ($t['nickname'] ?? '') !== '')
            ? sprintf('Terminal: %s (%s)  Role: %s', $t['nickname'], $id, $role)
            : sprintf('Terminal: %s  Role: %s', $id, $role);

        if ($t !== null) {
            $this->details[] = sprintf(
                'Last telemetry: %s  Online since: %s  Uptime: %s',
                Format::ago($lastSeen),
                $t['online_since_e'] === null ? '-' : Format::dateTime((float) $t['online_since_e']),
                $t['uptime_s'] === null ? '-' : Format::duration((float) $t['uptime_s'])
            );
            $this->details[] = sprintf(
                'Software: %s  Country: %s  IPv4: %s',
                $t['software_version'] ?? '-', $t['country_code'] ?? '-',
                implode(', ', Format::jsonList($t['ipv4'])) ?: '-'
            );
        }
        $this->details[] = sprintf(
            'Window: %s, %d samples (%.0f%% coverage). Peak: %s down / %s up',
            Format::duration($window), $n, min(100, $n * self::SAMPLE_SECONDS * 100 / $window),
            Format::mbps($dMax), Format::mbps($uMax)
        );
        if ($role === 'failover') {
            $this->details[] = sprintf('Active threshold: %s combined', Format::mbps($threshold));
        }

        $this->addPerf('rx_bps', round($rxBps, 1), '', null, null, 0);
        $this->addPerf('tx_bps', round($txBps, 1), '', null, null, 0);
        $this->addPerf('rx_bps_max', round((float) $dMax * 1e6, 1), '', null, null, 0);
        $this->addPerf('tx_bps_max', round((float) $uMax * 1e6, 1), '', null, null, 0);
        $this->addPerf('rx_bytes', (int) round($rxBytes), 'B', null, null, 0);
        $this->addPerf('tx_bytes', (int) round($txBytes), 'B', null, null, 0);
        $this->addPerf('active', $active && ! $offline ? 1 : 0, '', null, null, 0, 1);
        $this->addPerf('online', $offline ? 0 : 1, '', null, null, 0, 1);
        if (! $offline) {
            $this->addPerf('ping_drop', $dropPct === null ? null : round($dropPct, 3), '%',
                $this->floatParam('warn-drop', 5.0), $this->floatParam('crit-drop', 20.0), 0, 100);
            $this->addPerf('latency', $latAvg === null ? null : round($latAvg, 1), 'ms',
                $this->floatParam('warn-latency', null), $this->floatParam('crit-latency', null), 0);
            if ($t !== null) {
                $this->addPerf('obstruction', $t['obstruction'] === null ? null : (float) $t['obstruction']);
                $this->addPerf('signal_quality', $t['signal_quality'] === null ? null : (float) $t['signal_quality'], '', null, null, 0, 1);
                $this->addPerf('uptime', $t['uptime_s'] === null ? null : (int) $t['uptime_s'], 's');
            }
        }
        $this->addPerf('samples', $n, '', null, null, 0);
    }

    protected function checkHealth(): void
    {
        $warn = $this->floatParam('warn', 120.0);
        $crit = $this->floatParam('crit', 300.0);
        if (! Db::hasSchema()) {
            throw new \RuntimeException('Database has no starlink schema - is the daemon running?');
        }
        $d = Db::row(
            'SELECT *, EXTRACT(EPOCH FROM now() - last_success_at) AS age,'
            . ' EXTRACT(EPOCH FROM last_error_at) AS err_e, EXTRACT(EPOCH FROM started_at) AS start_e'
            . ' FROM starlink_daemon WHERE id = 1'
        );
        $age = $d['age'] === null ? null : (float) $d['age'];
        if ($age === null || $age >= $crit) {
            $this->raise(2);
        } elseif ($age >= $warn) {
            $this->raise(1);
        }
        $this->summary[] = $age === null
            ? 'collector has never succeeded'
            : sprintf('last successful poll %s ago', Format::duration($age));
        if ($this->state !== 0 && $d['last_error']) {
            $this->summary[] = 'last error: ' . substr(str_replace("\n", ' ', $d['last_error']), 0, 200);
        }
        $terminals = Db::row(
            'SELECT count(*) AS total, count(*) FILTER (WHERE last_seen > now() - interval \'2 minutes\') AS online'
            . ' FROM starlink_terminal'
        );
        $this->summary[] = sprintf('%d/%d terminals reporting', $terminals['online'], $terminals['total']);
        $this->details[] = sprintf(
            'Daemon on %s (pid %s) started %s; last error %s',
            $d['hostname'] ?? '?', $d['pid'] ?? '?', Format::ago($d['start_e'] === null ? null : (float) $d['start_e']),
            $d['err_e'] === null ? 'never' : Format::ago((float) $d['err_e'])
        );
        $this->addPerf('poll_age', $age === null ? null : round($age, 1), 's', $warn, $crit, 0);
        $this->addPerf('terminals_online', (int) $terminals['online'], '', null, null, 0);
        $this->addPerf('terminals_total', (int) $terminals['total'], '', null, null, 0);
        $this->addPerf('rows_total', (int) $d['rows_total'], 'c');
    }
}
