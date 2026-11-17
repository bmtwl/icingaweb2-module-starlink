<?php

namespace Icinga\Module\Starlink;

class Format
{
    public static function mbps(?float $mbps): string
    {
        if ($mbps === null) {
            return '-';
        }

        return static::bps($mbps * 1e6);
    }

    public static function bps(?float $bps): string
    {
        if ($bps === null) {
            return '-';
        }
        foreach ([['Gbps', 1e9], ['Mbps', 1e6], ['kbps', 1e3]] as [$unit, $div]) {
            if ($bps >= $div) {
                return sprintf('%.2f %s', $bps / $div, $unit);
            }
        }

        return sprintf('%.0f bps', $bps);
    }

    public static function bytes(?float $bytes): string
    {
        if ($bytes === null) {
            return '-';
        }
        foreach ([['TB', 1e12], ['GB', 1e9], ['MB', 1e6], ['kB', 1e3]] as [$unit, $div]) {
            if ($bytes >= $div) {
                return sprintf('%.2f %s', $bytes / $div, $unit);
            }
        }

        return sprintf('%.0f B', $bytes);
    }

    public static function duration(?float $seconds): string
    {
        if ($seconds === null) {
            return '-';
        }
        $s = (int) round($seconds);
        if ($s < 90) {
            return "{$s}s";
        }
        if ($s < 5400) {
            return sprintf('%dm', round($s / 60));
        }
        if ($s < 172800) {
            return sprintf('%dh %02dm', intdiv($s, 3600), intdiv($s % 3600, 60));
        }

        return sprintf('%dd %02dh', intdiv($s, 86400), intdiv($s % 86400, 3600));
    }

    public static function ago(?float $epoch): string
    {
        if ($epoch === null) {
            return 'never';
        }

        return static::duration(max(0, time() - $epoch)) . ' ago';
    }

    public static function dateTime(?float $epoch): string
    {
        return $epoch === null ? '-' : date('Y-m-d H:i:s', (int) $epoch);
    }

    public static function pct(?float $fraction, int $decimals = 2): string
    {
        return $fraction === null ? '-' : sprintf("%.{$decimals}f%%", $fraction * 100);
    }

    public static function jsonList($json): array
    {
        $list = is_array($json) ? $json : json_decode((string) $json, true);

        return is_array($list) ? $list : [];
    }
}
