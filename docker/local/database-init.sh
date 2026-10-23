#!/bin/sh
set -eu
psql -X -U postgres -v ON_ERROR_STOP=1 -f /run/database-secrets/init.sql
touch /var/lib/postgresql/local-cluster-ready
