<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

error_reporting(E_ALL);
ini_set('display_errors', 'stderr');
set_error_handler(fn($no, $msg, $file, $line) => throw new ErrorException($msg, 0, $no, $file, $line));

const LAT  = 40.62667;
const LON  = 22.44417;
const DAYS = 3;
define('LOCAL_TZ', getenv('LOCAL_TZ') ?: 'Europe/Athens');
date_default_timezone_set(LOCAL_TZ);

function connect(): PDO {
    $dsn = sprintf('pgsql:host=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_NAME'));
    return new PDO($dsn, getenv('DB_USER'), getenv('DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

/** @return array{0:string,1:array} [masked url, decoded json] */
function fetch(float $lat, float $lon, int $days): array {
    $key = getenv('WEATHER_API_KEY') ?: throw new RuntimeException('WEATHER_API_KEY is not set');
    $url = 'https://api.weatherapi.com/v1/forecast.json?' . http_build_query([
        'key' => $key, 'q' => "$lat,$lon", 'days' => $days, 'aqi' => 'no', 'alerts' => 'no',
    ]);
    $masked = str_replace($key, '***', $url);

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) throw new RuntimeException("Request failed: $err ($masked)");
    $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    if ($code !== 200) {
        throw new RuntimeException("API HTTP $code: " . ($json['error']['message'] ?? substr($body, 0, 200)));
    }
    return [$masked, $json];
}

/** Maps forecastday[] to rows: [forecast_date, metric, value] */
function map(array $json): array {
    $days = $json['forecast']['forecastday'] ?? throw new UnexpectedValueException('No forecastday in response');
    $rows = [];
    foreach ($days as $fd) {
        $date = $fd['date'];
        // day block: maxtemp_c, mintemp_c, avgtemp_c, maxwind_kph, totalprecip_mm, avghumidity, uv, chance_of_rain ...
        foreach ($fd['day'] ?? [] as $metric => $value) {
            if ($metric === 'condition') { $metric = 'condition_code'; $value = $value['code'] ?? null; }
            if (is_numeric($value)) $rows[] = [$date, $metric, (float)$value];
        }
        // astro block: sunrise/sunset as minutes after midnight, plus numeric moon fields
        foreach ($fd['astro'] ?? [] as $metric => $value) {
            if (is_numeric($value)) { $rows[] = [$date, $metric, (float)$value]; continue; }
            $t = DateTime::createFromFormat('h:i A', (string)$value);
            if ($t) $rows[] = [$date, $metric . '_min', (float)((int)$t->format('G') * 60 + (int)$t->format('i'))];
        }
    }
    if (!$rows) throw new UnexpectedValueException('Mapping produced 0 rows');
    return $rows;
}

/** Upserts rows inside the caller's transaction; returns count. */
function save(PDO $pdo, array $rows, string $jobStarted, string $sourceUrl): int {
    $stmt = $pdo->prepare('INSERT INTO daily_forecast
          (lat, lon, job_started_at, forecast_date, source_url, metric, value)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT (lat, lon, forecast_date, metric) DO UPDATE SET
          value = EXCLUDED.value,
          job_started_at = EXCLUDED.job_started_at,
          source_url = EXCLUDED.source_url,
          created_at = now()');
    foreach ($rows as [$date, $metric, $value]) {
        $stmt->execute([LAT, LON, $jobStarted, $date, $sourceUrl, $metric, $value]);
    }
    return count($rows);
}

function log_run(PDO $pdo, string $jobStarted, float $t0, ?string $url, ?string $from, ?string $to,
                 int $count, string $status, ?string $error = null): void {
    $pdo->prepare('INSERT INTO daily_forecast_log
        (job_started_at, duration_ms, source_url, date_from, date_to, records_saved, status, error_message)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$jobStarted, (int)round((microtime(true) - $t0) * 1000), $url, $from, $to, $count, $status, $error]);
}

// ---- main ----
$t0 = microtime(true);
$jobStarted = (new DateTimeImmutable('now'))->format('c');
$pdo = null; $url = null; $from = null; $to = null;

try {
    $pdo = connect();
    [$url, $json] = fetch(LAT, LON, DAYS);
    $rows = map($json);
    $dates = array_column($rows, 0);
    $from = min($dates); $to = max($dates);

    $pdo->beginTransaction();
    $count = save($pdo, $rows, $jobStarted, $url);
    log_run($pdo, $jobStarted, $t0, $url, $from, $to, $count, 'ok');
    $pdo->commit();

    echo date('c') . " OK: saved $count values for $from to $to\n";
} catch (Throwable $e) {
    $msg = get_class($e) . ': ' . $e->getMessage();
    fwrite(STDERR, date('c') . " ERROR: $msg\n");
    if ($pdo) {
        try {
            if ($pdo->inTransaction()) $pdo->rollBack();
            log_run($pdo, $jobStarted, $t0, $url, $from, $to, 0, 'error', $msg);
        } catch (Throwable $logErr) {
            fwrite(STDERR, date('c') . ' ERROR writing log: ' . $logErr->getMessage() . "\n");
        }
    }
    exit(1);
}
