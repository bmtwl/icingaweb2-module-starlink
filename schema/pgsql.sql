-- Starlink module schema, version 1 (PostgreSQL)

CREATE TABLE starlink_schema (
  version     integer PRIMARY KEY,
  applied_at  timestamptz NOT NULL DEFAULT now()
);

-- single row, written by the daemon
CREATE TABLE starlink_daemon (
  id               smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
  hostname         text,
  pid              integer,
  started_at       timestamptz,
  last_poll_at     timestamptz,
  last_success_at  timestamptz,
  last_error       text,
  last_error_at    timestamptz,
  rows_total       bigint NOT NULL DEFAULT 0,
  account_number   text,
  account_name     text,
  alert_enums      jsonb NOT NULL DEFAULT '{}'::jsonb,
  columns          jsonb NOT NULL DEFAULT '{}'::jsonb
);

CREATE TABLE starlink_terminal (
  device_id          text PRIMARY KEY,
  first_seen         timestamptz NOT NULL,
  last_seen          timestamptz NOT NULL,
  online_since       timestamptz,
  last_sample_ts     timestamptz,
  downlink_mbps      double precision,
  uplink_mbps        double precision,
  ping_drop          double precision,
  latency_ms         double precision,
  obstruction        double precision,
  signal_quality     double precision,
  uptime_s           bigint,
  h3_cell            bigint,
  swupdate_reboot_s  bigint,
  software_version   text,
  country_code       text,
  in_territorial_waters boolean,
  active_alerts      jsonb NOT NULL DEFAULT '[]'::jsonb,
  ipv4               jsonb NOT NULL DEFAULT '[]'::jsonb,
  ipv6_ue            jsonb NOT NULL DEFAULT '[]'::jsonb,
  ipv6_cpe           jsonb NOT NULL DEFAULT '[]'::jsonb,
  ip_seen_at         timestamptz,
  last_row           jsonb NOT NULL DEFAULT '{}'::jsonb,
  active_streak      integer NOT NULL DEFAULT 0,
  inactive_streak    integer NOT NULL DEFAULT 0,
  activity_id        bigint
);

CREATE TABLE starlink_sample (
  device_id       text NOT NULL REFERENCES starlink_terminal (device_id) ON DELETE CASCADE,
  ts              timestamptz NOT NULL,
  downlink_mbps   double precision,
  uplink_mbps     double precision,
  ping_drop       double precision,
  latency_ms      double precision,
  obstruction     double precision,
  signal_quality  double precision,
  uptime_s        bigint,
  alerts          jsonb NOT NULL DEFAULT '[]'::jsonb,
  PRIMARY KEY (device_id, ts)
);
CREATE INDEX starlink_sample_ts ON starlink_sample (ts);

CREATE TABLE starlink_router (
  device_id   text PRIMARY KEY,
  dish_id     text,
  first_seen  timestamptz NOT NULL,
  last_seen   timestamptz NOT NULL,
  last_row    jsonb NOT NULL DEFAULT '{}'::jsonb
);

-- device types we don't model yet (e.g. 'd' = UserTerminalDataUsage)
CREATE TABLE starlink_other_row (
  id          bigserial PRIMARY KEY,
  device_type text NOT NULL,
  device_id   text,
  ts          timestamptz NOT NULL,
  row_data    jsonb NOT NULL
);
CREATE INDEX starlink_other_row_ts ON starlink_other_row (ts);

-- periods where a terminal carried traffic above the activity threshold
CREATE TABLE starlink_activity (
  id              bigserial PRIMARY KEY,
  device_id       text NOT NULL REFERENCES starlink_terminal (device_id) ON DELETE CASCADE,
  started_at      timestamptz NOT NULL,
  last_active_at  timestamptz NOT NULL,
  ended_at        timestamptz,
  samples         integer NOT NULL DEFAULT 0,
  rx_bytes        double precision NOT NULL DEFAULT 0,
  tx_bytes        double precision NOT NULL DEFAULT 0,
  peak_down_mbps  double precision NOT NULL DEFAULT 0,
  peak_up_mbps    double precision NOT NULL DEFAULT 0
);
CREATE INDEX starlink_activity_device ON starlink_activity (device_id, started_at DESC);

CREATE TABLE starlink_alert_history (
  id          bigserial PRIMARY KEY,
  device_id   text NOT NULL REFERENCES starlink_terminal (device_id) ON DELETE CASCADE,
  alert       text NOT NULL,
  raised_at   timestamptz NOT NULL,
  cleared_at  timestamptz
);
CREATE INDEX starlink_alert_history_device ON starlink_alert_history (device_id, raised_at DESC);

INSERT INTO starlink_daemon (id) VALUES (1);
INSERT INTO starlink_schema (version) VALUES (1);

ALTER TABLE starlink_terminal ADD COLUMN nickname text;
CREATE UNIQUE INDEX starlink_terminal_nickname ON starlink_terminal (lower(nickname));
INSERT INTO starlink_schema (version) VALUES (2);
