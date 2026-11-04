#!/bin/bash
# Sourced by the MySQL image entrypoint on first initialisation of the data volume.
# Loads the "employees" sample dataset into $MYSQL_DATABASE.

mysql_note "Importing employees dataset into '${MYSQL_DATABASE}' (this takes a few minutes)..."

# employees.sql drops/creates a database literally called "employees";
# point it at the database configured for the app instead.
sed -E \
    -e '/^DROP DATABASE IF EXISTS employees;/d' \
    -e '/^CREATE DATABASE IF NOT EXISTS employees;/d' \
    -e "s/^USE employees;/USE \`${MYSQL_DATABASE}\`;/" \
    /opt/employees-db/employees.sql > /tmp/employees-import.sql

# "source load_*.dump" paths in employees.sql are relative to the client's cwd.
(
    cd /opt/employees-db
    docker_process_sql < /tmp/employees-import.sql
)

rm -f /tmp/employees-import.sql

mysql_note "Employees dataset imported."
