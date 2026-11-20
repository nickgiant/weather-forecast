CREATE EXTENSION IF NOT EXISTS timescaledb;
CREATE EXTENSION IF NOT EXISTS pg_cron;

CREATE TABLE IF NOT EXISTS hourly_forecast (
  lat            double precision NOT NULL,
  lon            double precision NOT NULL,
  job_started_at timestamptz NOT NULL,
  created_at     timestamptz NOT NULL DEFAULT now(),
  forecast_time  timestamptz NOT NULL,
  source_url     text NOT NULL,
  metric         text NOT NULL,
  value          double precision
);
SELECT create_hypertable('hourly_forecast', by_range('forecast_time'), if_not_exists => TRUE);
CREATE INDEX IF NOT EXISTS hourly_forecast_metric_idx ON hourly_forecast (metric, forecast_time DESC);

CREATE TABLE IF NOT EXISTS hourly_forecast_log (
  id             bigserial PRIMARY KEY,
  job_started_at timestamptz NOT NULL,
  job_ended_at   timestamptz NOT NULL DEFAULT now(),
  duration_ms    integer     NOT NULL,
  source_url     text,
  forecast_date  date,
  records_saved  integer     NOT NULL DEFAULT 0,
  status         text        NOT NULL CHECK (status IN ('ok','error')),
  error_message  text
);
CREATE INDEX IF NOT EXISTS hourly_forecast_log_started_idx ON hourly_forecast_log (job_started_at DESC);

CREATE TABLE IF NOT EXISTS daily_forecast (
  lat            double precision NOT NULL,
  lon            double precision NOT NULL,
  job_started_at timestamptz NOT NULL,
  created_at     timestamptz NOT NULL DEFAULT now(),
  forecast_date  date        NOT NULL,
  source_url     text        NOT NULL,
  metric         text        NOT NULL,
  value          double precision,
  CONSTRAINT daily_forecast_uq UNIQUE (lat, lon, forecast_date, metric)
);
SELECT create_hypertable('daily_forecast', by_range('forecast_date', INTERVAL '1 month'), if_not_exists => TRUE);

CREATE TABLE IF NOT EXISTS daily_forecast_log (
  id             bigserial PRIMARY KEY,
  job_started_at timestamptz NOT NULL,
  job_ended_at   timestamptz NOT NULL DEFAULT now(),
  duration_ms    integer     NOT NULL,
  source_url     text,
  date_from      date,
  date_to        date,
  records_saved  integer     NOT NULL DEFAULT 0,
  status         text        NOT NULL CHECK (status IN ('ok','error')),
  error_message  text
);
CREATE INDEX IF NOT EXISTS daily_forecast_log_started_idx ON daily_forecast_log (job_started_at DESC);

