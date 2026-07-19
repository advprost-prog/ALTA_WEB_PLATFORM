# Local PostgreSQL 18 development runtime

The development transition cluster is user-owned and binds only to
`127.0.0.1:55438`. The normal application default in `.env.example` remains
SQLite. Local credentials stay outside Git.

Set the runtime paths for the current shell:

```bash
export ALTA_PG18_ROOT="$HOME/.local/share/alta-pg18-runtime/root-18.4"
export ALTA_PG18_CLUSTER="$HOME/.local/share/alta-pg-transition-20260719"
export LD_LIBRARY_PATH="$ALTA_PG18_ROOT/usr/lib/x86_64-linux-gnu"
```

Start, stop, and check the isolated server without changing the system
PostgreSQL service:

```bash
"$ALTA_PG18_ROOT/usr/lib/postgresql/18/bin/pg_ctl" \
  -D "$ALTA_PG18_CLUSTER/data" \
  -l "$ALTA_PG18_CLUSTER/postgresql.log" \
  -o "-h 127.0.0.1 -p 55438 -k $ALTA_PG18_CLUSTER/socket -c timezone=UTC" \
  start -w

"$ALTA_PG18_ROOT/usr/lib/postgresql/18/bin/pg_isready" \
  -h 127.0.0.1 -p 55438

"$ALTA_PG18_ROOT/usr/lib/postgresql/18/bin/pg_ctl" \
  -D "$ALTA_PG18_CLUSTER/data" stop -m fast -w
```

The imported development database is `alta_pg_transition_20260719`. Full test
suites must use a separately created disposable database whose name begins
`alta_pg_transition_test_`; never run destructive tests against the imported
database. The protected SQLite source and `.env` backup are the rollback
boundary until PostgreSQL development writes are explicitly accepted.
