<?php

namespace Icinga\Module\Starlink;

use Icinga\Application\Config;

/**
 * Typed access to /etc/icingaweb2/modules/starlink/config.ini
 */
class Settings
{
    protected static function get(string $section, string $key, $default = null)
    {
        $value = Config::module('starlink')->get($section, $key);

        return ($value === null || $value === '') ? $default : $value;
    }

    public static function dbResource(): ?string
    {
        return static::get('db', 'resource');
    }

    public static function credentialsFile(): string
    {
        return (string) static::get('api', 'credentials_file', '/etc/icinga-starlink/credentials.env');
    }

    /** Raw sample retention. Long-term history lives in perfdata. */
    public static function retentionHours(): int
    {
        return max(1, (int) static::get('collector', 'retention_hours', 48));
    }

    /** Combined down+up Mbps that counts as "carrying traffic" for activity periods */
    public static function activityThresholdMbps(): float
    {
        return (float) static::get('collector', 'activity_threshold_mbps', 0.5);
    }

    /** Consecutive active samples (15s each) before an activity period opens */
    public static function activityStartSamples(): int
    {
        return max(1, (int) static::get('collector', 'activity_start_samples', 2));
    }

    /** Consecutive idle samples before an activity period closes */
    public static function activityEndSamples(): int
    {
        return max(1, (int) static::get('collector', 'activity_end_samples', 8));
    }

    /** Telemetry gap after which a terminal is considered to have gone offline */
    public static function offlineGapSeconds(): int
    {
        return max(30, (int) static::get('collector', 'offline_gap_seconds', 120));
    }

    public static function tileUrl(): string
    {
        return (string) static::get('map', 'tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
    }

    public static function tileAttribution(): string
    {
        return (string) static::get(
            'map',
            'tile_attribution',
            '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        );
    }
}
