<?php
/**
 * GPS Trip Splitter & GeoJSON Exporter (PHP 8)
 *
 * Usage:
 *   php your_script.php input.csv output.geojson
 *
 * Input CSV fields (first 4 columns, header optional):
 *   device_id (string), lat (decimal), lon (decimal), timestamp (ISO 8601)
 *
 * Rules:
 * - Clean: discard rows with invalid coordinates or bad timestamps -> log to rejects.log
 * - Order: sort remaining points by timestamp (per device)
 * - Split: new trip when (gap > 25 minutes) OR (jump > 2 km by Haversine)
 * - Number: trip_1, trip_2, … (per device)
 * - Metrics per trip: total distance (km), duration (min), avg speed (km/h), max speed (km/h)
 * - Output: GeoJSON FeatureCollection, each trip as a LineString, colored differently
 *
 * Constraints: standard PHP 8 libs only, no external APIs/DB.
 */

declare(strict_types=1);

// ---- CLI args ----
if ($argc < 3) {
    fwrite(STDERR, "Usage: php your_script.php input.csv output.geojson\n");
    exit(1);
}
$inputPath  = $argv[1];
$outputPath = $argv[2];
$rejectsLog = dirname($outputPath) . DIRECTORY_SEPARATOR . 'rejects.log';

// ---- Config ----
const TIME_GAP_MINUTES = 25.0; // minutes
const DIST_JUMP_KM     = 2.0;  // kilometers

// ---- Helpers ----
function isValidLat($lat): bool {
    return is_numeric($lat) && $lat >= -90 && $lat <= 90;
}
function isValidLon($lon): bool {
    return is_numeric($lon) && $lon >= -180 && $lon <= 180;
}
function parseIsoTime(string $t): ?DateTimeImmutable {
    try {
        $dt = new DateTimeImmutable($t);
        return $dt ?: null;
    } catch (Throwable $e) {
        return null;
    }
}
function minutesBetween(DateTimeImmutable $a, DateTimeImmutable $b): float {
    return ($b->getTimestamp() - $a->getTimestamp()) / 60.0;
}
function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371.0088; // km
    $φ1 = deg2rad($lat1); $φ2 = deg2rad($lat2);
    $Δφ = deg2rad($lat2 - $lat1); $Δλ = deg2rad($lon2 - $lon1);
    $a = sin($Δφ/2)**2 + cos($φ1) * cos($φ2) * sin($Δλ/2)**2;
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}
function avgSpeedKmh(float $distKm, float $durationMin): float {
    return $durationMin > 0 ? $distKm / ($durationMin / 60.0) : 0.0;
}
function colorPalette(): array {
    return [
        "#1f77b4","#ff7f0e","#2ca02c","#d62728",
        "#9467bd","#8c564b","#e377c2","#7f7f7f",
        "#bcbd22","#17becf"
    ];
}
function detectHeader(array $row): bool {
    $lower = array_map(fn($v)=>strtolower(trim((string)$v)), $row);
    if (in_array('device_id',$lower,true) || in_array('lat',$lower,true) ||
        in_array('lon',$lower,true) || in_array('timestamp',$lower,true)) {
        return true;
    }
    if (count($row) < 4) return true;
    [$d,$la,$lo,$ts] = array_pad($row,4,null);
    $tsOk = parseIsoTime((string)$ts) !== null;
    return !(is_numeric($la) && is_numeric($lo) && $tsOk);
}

// ---- I/O ----
if (!is_readable($inputPath)) {
    fwrite(STDERR, "Cannot read input: $inputPath\n");
    exit(1);
}
$in = fopen($inputPath, 'r');
if ($in === false) { fwrite(STDERR, "Failed to open input\n"); exit(1); }
$rej = fopen($rejectsLog, 'w');
if ($rej === false) { fwrite(STDERR, "Failed to open rejects.log\n"); fclose($in); exit(1); }
fwrite($rej, "reason,line\n");

// ---- Read & Clean ----
$pointsByDevice = []; // device_id => list of points
$headerChecked = false;

while (($row = fgetcsv($in)) !== false) {
    if ($row === [null] || $row === false) continue;
    $row = array_map(fn($v)=>is_string($v)?trim($v):$v, $row);

    if (!$headerChecked) {
        $headerChecked = true;
        if (detectHeader($row)) continue; // skip header
    }
    if (count($row) < 4) {
        fputcsv($rej, ["too_few_columns", implode('|',$row)]);
        continue;
    }
    [$deviceId,$lat,$lon,$timestamp] = array_slice($row,0,4);

    if ($deviceId === null || $deviceId === '') {
        fputcsv($rej, ["empty_device_id", implode('|',$row)]); continue;
    }
    if (!isValidLat($lat) || !isValidLon($lon)) {
        fputcsv($rej, ["invalid_coordinates", implode('|',$row)]); continue;
    }
    $dt = parseIsoTime((string)$timestamp);
    if ($dt === null) {
        fputcsv($rej, ["bad_timestamp", implode('|',$row)]); continue;
    }

    $pointsByDevice[(string)$deviceId][] = [
        'device_id'=>(string)$deviceId,
        'lat'=>(float)$lat,
        'lon'=>(float)$lon,
        'ts'=>(string)$timestamp,
        'dt'=>$dt
    ];
}
fclose($in);
fclose($rej);

// ---- Sort per device ----
foreach ($pointsByDevice as &$pts) {
    usort($pts, fn($a,$b)=>$a['dt']->getTimestamp() <=> $b['dt']->getTimestamp());
}
unset($pts);

// ---- Split & compute trips ----
$palette = colorPalette();
$features = [];
$totalTrips = 0;

foreach ($pointsByDevice as $deviceId => $pts) {
    if (!$pts) continue;

    $trips = [];
    $current = [];
    $prev = null;

    foreach ($pts as $p) {
        if ($prev === null) {
            $current = [$p];
        } else {
            $gapMin = minutesBetween($prev['dt'], $p['dt']);
            $jumpKm = haversineKm($prev['lat'],$prev['lon'],$p['lat'],$p['lon']);

            if ($gapMin > TIME_GAP_MINUTES || $jumpKm > DIST_JUMP_KM) {
                if (count($current) >= 1) $trips[] = $current;
                $current = [$p];
            } else {
                $current[] = $p;
            }
        }
        $prev = $p;
    }
    if (count($current) >= 1) $trips[] = $current;

    $tripIndex = 0;
    foreach ($trips as $tripPts) {
        if (count($tripPts) < 2) continue; // need >=2 for LineString
        $tripIndex++; $totalTrips++;

        $coords = [];
        $totalKm = 0.0;
        $maxSpeedKmh = 0.0;

        $first = $tripPts[0];
        $last  = $tripPts[count($tripPts)-1];
        $durationMin = max(0.0, minutesBetween($first['dt'],$last['dt']));

        for ($i=0; $i<count($tripPts); $i++) {
            $coords[] = [$tripPts[$i]['lon'], $tripPts[$i]['lat']];
            if ($i>0) {
                $dKm = haversineKm(
                    $tripPts[$i-1]['lat'],$tripPts[$i-1]['lon'],
                    $tripPts[$i]['lat'],$tripPts[$i]['lon']
                );
                $totalKm += $dKm;

                $segMin = minutesBetween($tripPts[$i-1]['dt'],$tripPts[$i]['dt']);
                if ($segMin > 0) {
                    $segSpeed = $dKm / ($segMin/60.0);
                    if ($segSpeed > $maxSpeedKmh) $maxSpeedKmh = $segSpeed;
                }
            }
        }

        $avgKmh = avgSpeedKmh($totalKm, $durationMin);
        $color  = $palette[($totalTrips - 1) % count($palette)];

        $features[] = [
            "type" => "Feature",
            "properties" => [
                "trip_id"        => "trip_" . $tripIndex,
                "device_id"      => $deviceId,
                "point_count"    => count($tripPts),
                "distance_km"    => round($totalKm, 3),
                "duration_min"   => round($durationMin, 2),
                "avg_speed_kmh"  => round($avgKmh, 2),
                "max_speed_kmh"  => round($maxSpeedKmh, 2),
                "stroke"         => $color,
                "stroke-width"   => 4,
                "stroke-opacity" => 0.9
            ],
            "geometry" => [
                "type" => "LineString",
                "coordinates" => $coords
            ]
        ];
    }
}

// ---- GeoJSON out ----
$fc = ["type"=>"FeatureCollection","features"=>$features];
$json = json_encode($fc, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
if ($json === false) { fwrite(STDERR,"Failed to encode GeoJSON\n"); exit(1); }
if (file_put_contents($outputPath, $json) === false) {
    fwrite(STDERR,"Failed to write $outputPath\n"); exit(1);
}

fwrite(STDOUT, "OK: wrote ".count($features)." trips to $outputPath\n");
fwrite(STDOUT, "Rejects logged to $rejectsLog\n");
