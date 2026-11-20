<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
set_error_handler(fn($no, $msg, $file, $line) => throw new ErrorException($msg, 0, $no, $file, $line));

const LAT = 40.62667;
const LON = 22.44417;
const METRICS = [
    'maxtemp_c', 'mintemp_c', 'avgtemp_c',
    'totalprecip_mm', 'totalsnow_cm',
    'daily_chance_of_rain', 'daily_chance_of_snow',
    'condition_code',
];

define('LOCAL_TZ', getenv('LOCAL_TZ') ?: 'Europe/Athens');
date_default_timezone_set(LOCAL_TZ);

function connect(): PDO {
    $dsn = sprintf('pgsql:host=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_NAME'));
    return new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/** @return array{0:string,1:array} [masked source url, decoded json] */
function fetch(float $lat, float $lon, string $date): array {
    $key = getenv('WEATHER_API_KEY') ?: throw new RuntimeException('WEATHER_API_KEY is not set');
    $url = 'https://api.weatherapi.com/v1/forecast.json?' . http_build_query([
        'key' => $key, 'q' => "$lat,$lon", 'dt' => $date, 'aqi' => 'no', 'alerts' => 'no',
    ]);
    $masked = str_replace($key, '***', $url);

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FAILONERROR => false]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) throw new RuntimeException("Request failed: $err ($masked)");
    $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if ($code !== 200) {
        $apiMsg = $json['error']['message'] ?? substr($body, 0, 200);
        throw new RuntimeException("API HTTP $code: $apiMsg");
    }
    return [$masked, $json];
}

/** Turns the API response into rows: [forecast_time, metric, value] */
/** Maps forecastday[].day to rows: [forecast_date, metric, value] — only METRICS */
function map(array $json): array {
    $days = $json['forecast']['forecastday'] ?? throw new UnexpectedValueException('No forecastday in response');
    $rows = [];
    foreach ($days as $fd) {
        $date = $fd['date'] ?? throw new UnexpectedValueException('forecastday without date');
        $day  = $fd['day']  ?? throw new UnexpectedValueException("No day block for $date");
        $day['condition_code'] = $day['condition']['code'] ?? null;

        foreach (METRICS as $metric) {
            $value = $day[$metric] ?? null;
            if (!is_numeric($value)) {
                throw new UnexpectedValueException("Missing or non-numeric '$metric' for $date");
            }
            $rows[] = [$date, $metric, (float)$value];
        }
    }
    return $rows;
}

/** Inserts rows; runs inside the caller's transaction. Returns count. */
function save(PDO $pdo, array $rows, string $jobStarted, string $sourceUrl): int {
    $stmt = $pdo->prepare('INSERT INTO hourly_forecast
        (lat, lon, job_started_at, forecast_time, source_url, metric, value)
        VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($rows as [$ts, $metric, $value]) {
        $stmt->execute([LAT, LON, $jobStarted, $ts, $sourceUrl, $metric, $value]);
    }
    return count($rows);
}

function log_run(PDO $pdo, string $jobStarted, float $t0, ?string $url, ?string $date,
                 int $count, string $status, ?string $error = null): void {
    $pdo->prepare('INSERT INTO hourly_forecast_log
        (job_started_at, duration_ms, source_url, forecast_date, records_saved, status, error_message)
        VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$jobStarted, (int)round((microtime(true) - $t0) * 1000), $url, $date, $count, $status, $error]);
}

// ---- main ----
$t0         = microtime(true);
$jobStarted = (new DateTimeImmutable('now'))->format('c');            // e.g. 2026-11-20T14:05:00+02:00
$date       = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
$pdo = null; $url = null;

try {
    $pdo = connect();
    [$url, $json] = fetch(LAT, LON, $date);
    $rows = map($json);

    $pdo->beginTransaction();
    $count = save($pdo, $rows, $jobStarted, $url);
    log_run($pdo, $jobStarted, $t0, $url, $date, $count, 'ok');
    $pdo->commit();

    echo date('c') . " OK: saved $count values for $date\n";
} catch (Throwable $e) {
    $msg = get_class($e) . ': ' . $e->getMessage();
    fwrite(STDERR, date('c') . " ERROR: $msg\n");
    if ($pdo) {
        try {
            if ($pdo->inTransaction()) $pdo->rollBack();
            log_run($pdo, $jobStarted, $t0, $url, $date, 0, 'error', $msg);
        } catch (Throwable $logErr) {
            fwrite(STDERR, date('c') . ' ERROR writing log: ' . $logErr->getMessage() . "\n");
        }
    }
    exit(1);
}
