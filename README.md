# Shuffled GPS Points → Trips (PHP 8)

Process shuffled GPS points into sequential trips and export a colored GeoJSON `FeatureCollection`.

## Input

- **Source**: Download the CSV from your shared file:

Save it locally as `input.csv`.
- **Columns** (first 4 columns, header optional):
1. `device_id` (string)
2. `lat` (decimal degrees, -90..90)
3. `lon` (decimal degrees, -180..180)
4. `timestamp` (ISO 8601, e.g., `2025-08-14T12:34:56Z`)

> If extra columns exist, they are ignored. If there is a header row, it is auto-detected and skipped.

## What the script does

1. **Clean**
 - Discards rows with invalid coordinates or invalid timestamps.
 - Logs each discarded line to `rejects.log` with a reason.

2. **Order**
 - Sorts remaining points **by timestamp (per device)**.

3. **Split into trips**
 - Starts a new trip when either condition holds between consecutive points:
   - **Time gap** > **25 minutes**, **OR**
   - **Straight-line distance jump** > **2 km** (Haversine formula).

4. **Number trips**
 - Trips are numbered sequentially per device: `trip_1`, `trip_2`, …

5. **Compute metrics per trip**
 - **Total distance (km)**
 - **Duration (min)**
 - **Average speed (km/h)** = total distance / duration
 - **Max segment speed (km/h)** across consecutive points

6. **Output GeoJSON**
 - A `FeatureCollection` with each trip as a `LineString`.
 - Each trip has a distinct color and the metrics in `properties`.

## Requirements

- **PHP 8+**
- **No external libraries, no database, no network calls.**

## Usage

```bash
php your_script.php points.csv output.geojson
