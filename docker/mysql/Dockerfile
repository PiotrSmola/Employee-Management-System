# syntax=docker/dockerfile:1

# MySQL 8.0 with the "employees" sample dataset (https://github.com/datacharmer/test_db)
# baked in. The dataset is imported automatically the first time the data volume
# is initialised.

FROM alpine:3.20 AS dataset

ARG TEST_DB_REF=e324b56193ca506ab7cc1ab143a9153d8c4535d7

RUN apk add --no-cache curl tar \
    && mkdir -p /dataset \
    && curl -fsSL "https://codeload.github.com/datacharmer/test_db/tar.gz/${TEST_DB_REF}" \
        | tar -xz -C /dataset --strip-components=1 \
            --wildcards '*/employees.sql' '*/load_*.dump' '*/show_elapsed.sql'


FROM mysql:8.0

COPY --from=dataset /dataset /opt/employees-db
COPY import-employees.sh /docker-entrypoint-initdb.d/10-import-employees.sh

# The init script must NOT be executable: the MySQL entrypoint then sources it,
# which gives it access to the docker_process_sql helper.
RUN sed -i 's/\r$//' /docker-entrypoint-initdb.d/10-import-employees.sh \
    && chmod 644 /docker-entrypoint-initdb.d/10-import-employees.sh
