# weather-forecast
Backend Assignment: Weather Forecast Collector

# 1 Installation and setup: 
    prerequisites
        - A server or a vps with os and docker
        - An API key from https://www.weatherapi.com

    installation steps
        - set passwords and environment variables
        - Install Docker and Docker Compose tool
        - Run the docker-compose.yml

    how to run the project locally
        - set passwords and environment variables described bellow in the .env file
            (in Ubuntu Linux)
            sudo apt update && sudo apt upgrade -y
            sudo snap install docker
            docker-compose build
            docker-compose up -d

    required environment variables
        DB_PASS=change_me
        PGADMIN_PASS=change_me
        WEATHER_API_KEY=your_weatherapi_key_here
        LOCAL_TZ=Europe/Athens

# 2 Implementation choices: 
    the framework and libraries
        - Cron: to trigger the php code based on specific time per day. 1) At 10:00 (Europe/Athens) send a Get request to fetch the daily forecast for 3 days. 2) At 11:00 (Europe/Athens) send a Get request to fetch the hourly forecast for the next day.  
        - Apache with php: Able to run 1) a Get request to fetch the daily forecast, map and save to db table daily_forecast and a log record at table daily_forecast_log. 2) a Get request to fetch the hourly forecast, map and save to db table hourly_forecast and a log record at table hourly_forecast_log
        - Postgresql (+TimescaleDB): SQL RDBMS with extension for time-series data.   

    third-party dependencies used
        Postgresql with TimescaleDB for the convenience in running sql commands and also keeping timeseries data at a later phase. Currently data is stored in plain tables.

    the main decisions and trade-offs behind current design.
        Metrics are stored as records, rather than columns, since providers usually change the metric names. Apache with php is used mainly due to fast response and wide adoption of this technology.

# 3 Incomplete requirements:
    The bind of Postgresql with TimescaleDB
    Implementation to fetch data from the second provider

# 4 Next steps:
    The rest of the functionality, like a second or a third provider, plus the use of sensors in the field with the use of Raspberry Pi devices. A gui for visualization like Grafana would make the results more interesting and appealing. 

# 5 Additional notes:
    Very interesting project showing that it is not enough to have some free to use projects but combine them to form a solution
