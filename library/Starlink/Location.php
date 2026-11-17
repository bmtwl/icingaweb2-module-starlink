<?php

namespace Icinga\Module\Starlink;

/**
 * Terminal position from the H3 cell reported in telemetry.
 *
 * Starlink reports a coarse cell (resolution 5, ~250 km2), so this is a
 * general locale: the dish is somewhere inside the cell outline. Needs the
 * PostgreSQL h3 extension in the module database.
 */
class Location
{
    /** @var ?string last lookup error (e.g. h3 extension missing), for display */
    public static $error;

    /**
     * @return array device_id => [lat, lng, boundary [[lat, lng], ...], radius_km, resolution, h3]
     */
    public static function forTerminals(?string $deviceId = null): array
    {
        static::$error = null;
        try {
            $rows = Db::all(
                'SELECT device_id, h3_cell,'
                . ' CAST(h3_cell_to_lat_lng(CAST(h3_cell AS h3index)) AS text) AS centre,'
                . ' CAST(h3_cell_to_boundary(CAST(h3_cell AS h3index)) AS text) AS boundary'
                . ' FROM starlink_terminal WHERE h3_cell IS NOT NULL'
                . ' AND (CAST(? AS text) IS NULL OR device_id = CAST(? AS text))',
                [$deviceId, $deviceId]
            );
        } catch (\Throwable $e) {
            static::$error = $e->getMessage();

            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $centre = static::points((string) $row['centre']);
            $boundary = static::points((string) $row['boundary']);
            if (empty($centre) || empty($boundary)) {
                continue;
            }
            [$lat, $lng] = $centre[0];
            $radius = 0.0;
            foreach ($boundary as [$bLat, $bLng]) {
                $radius = max($radius, static::km($lat, $lng, $bLat, $bLng));
            }
            $cell = (int) $row['h3_cell'];
            $result[$row['device_id']] = [
                'lat'        => $lat,
                'lng'        => $lng,
                'boundary'   => $boundary,
                'radius_km'  => $radius,
                'resolution' => ($cell >> 52) & 15,
                'h3'         => dechex($cell),
            ];
        }

        return $result;
    }

    /**
     * Parse PostgreSQL point / polygon text. h3-pg points are (lng,lat).
     *
     * @return array [[lat, lng], ...]
     */
    protected static function points(string $text): array
    {
        preg_match_all('/\(\s*([-+0-9.eE]+)\s*,\s*([-+0-9.eE]+)\s*\)/', $text, $m, PREG_SET_ORDER);
        $points = [];
        foreach ($m as $match) {
            $points[] = [(float) $match[2], (float) $match[1]];
        }

        return $points;
    }

    protected static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * 6371.0088 * asin(min(1, sqrt($a)));
    }

    public static function osmUrl(float $lat, float $lng, int $zoom = 10): string
    {
        return sprintf(
            'https://www.openstreetmap.org/?mlat=%1$.5F&mlon=%2$.5F#map=%3$d/%1$.5F/%2$.5F',
            $lat, $lng, $zoom
        );
    }
}
