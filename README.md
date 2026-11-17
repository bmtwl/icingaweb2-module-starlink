# Icinga Web 2 module: starlink

Starlink Public API v2 telemetry for Icinga

| Part | What it does |
|---|---|
| `icingacli starlink daemon run` | Single stream consumer (systemd). Polls `/telemetry/stream` continuously (~4 req/min), writes PostgreSQL. |
| Web UI (`Starlink` menu) | Terminals list, terminal detail (status, IPs, last-hour sparkline, activity periods, alert history, raw row), account-wide activity + alerts, collector health. |
| `icingacli starlink check terminal` | Reads the DB only. Failover detection (avg bps over the real check window), bandwidth perfdata, offline/alerts/quality. |
| `icingacli starlink check health` | Collector freshness. |
| Director import source | "Starlink terminals (telemetry)" - one row per dish seen in telemetry. |

Requirements: Icinga Web 2 (Debian stable packages), icinga-php-library/thirdparty,
`php-pgsql php-curl php-pcntl`, PostgreSQL and the H3 module. Starlink v2 service account with
**Device telemetry: View** (and Account information: View for the account name).

## Install

```bash
# module
cp -r starlink /usr/share/icingaweb2/modules/starlink
icingacli module enable starlink

# database
sudo -u postgres createuser -P starlink
sudo -u postgres createdb -O starlink starlink
sudo -u postgres psql -d starlink -c 'CREATE EXTENSION IF NOT EXISTS h3;'
# Icinga Web: Configuration > Application > Resources > new SQL resource
#   type pgsql, name e.g. "starlink"
# Configuration > Modules > starlink > Backend: pick the resource

# daemon user: reads Icinga Web config via the icingaweb2 group
useradd -r -g icingaweb2 -d /nonexistent -s /usr/sbin/nologin icinga-starlink
install -d -o icinga-starlink -g icingaweb2 -m 0750 /etc/icinga-starlink
install -o icinga-starlink -g icingaweb2 -m 0600 /dev/null /etc/icinga-starlink/credentials.env
cat > /etc/icinga-starlink/credentials.env   # CLIENT_ID=...  CLIENT_SECRET=...  (no shell parsing, quotes optional)

install -m 0644 contrib/systemd/icinga-starlink.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now icinga-starlink
journalctl -u icinga-starlink -f      # schema is created on first start
```

Use a **dedicated service account** for the daemon. Starlink keeps one stream
read position per service account; anything else reading the stream with the
same account (slscrape.py, `daemon once`) steals rows from the daemon.

If you intend to run an HA version of this on a second Icinga server, make
sure its using a separate Starlink API cred. 

## Checks (master node only)

`contrib/icinga2-starlink.conf` has the CheckCommands and apply rules. Key points:

- Pass `--since $service.last_check$`: the window is exactly the time since the
  previous check, so averages/bytes are right for any `check_interval`
  (bounded by raw retention).
- Averages are over the *whole* window: offline gaps count as zero.
- If the collector hasn't succeeded for `--daemon-stale` (180s) every terminal
  check goes UNKNOWN. A dead collector never looks like "all idle".
- The Icinga user must read Icinga Web config: `usermod -aG icingaweb2 nagios`.

| Situation | failover (default) | primary |
|---|---|---|
| No telemetry > `--offline-after` (120s) | OK `offline` | CRITICAL |
| Avg down+up >= `--active-threshold` | WARNING `FAILOVER ACTIVE` | (perfdata only) |
| Came online inside the window | WARNING | - |
| Active dish alert | WARNING (`--critical-alerts` to escalate, `--ignore-alerts` to drop) | same |
| Ping drop >= 5/20% | WARNING/CRITICAL | same |

Perfdata: `rx_bps tx_bps rx_bps_max tx_bps_max rx_bytes tx_bytes active online
ping_drop latency obstruction signal_quality uptime samples`.

## Director ETL

1. Import source: *Starlink terminals (telemetry)*. Key column `device_id`.
2. Data list `starlink_roles`: `failover`, `primary`.
3. Sync rule (hosts): create/update a host per dish, set `vars.starlink_device_id`
   and `vars.starlink_role`. Set the role with a property modifier (e.g. map
   `device_id` through a data list "Get related data" / lookup table), or create
   the hosts by hand and only sync the device id.

Only dishes that have reported telemetry at least once are imported (no
device-management permission is used). For any dishes that are offline or aren't
installed yet, create the host manually with the `ut...` id from the Starlink
portal, or wait for the first telemetry.

## Notes from live API tests (2026-11)

- `ActiveAlerts` (plural) in the stream, `ActiveAlert` in the docs: both handled.
- IP allocation rows arrive as `ip-<dish id>` and are duplicated; de-duplicated.
- `ObstructionPercentTime` scale is unclear (fraction vs percent); stored and
  shown as raw API value.
- Device type `d` (UserTerminalDataUsage) exists in the enums; such rows are
  kept raw in `starlink_other_row` for later modelling.
