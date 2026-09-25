# Postgres and Redis for the tool's own storage

`make start` runs four services: `web`, `worker`, `db` (Postgres 18) and `redis`. Queue, cache and session all use Redis. We chose this over a single SQLite file with a database queue, although that option makes the bootstrap lighter. The product, the dev infrastructure and the Pest suite then use the same engine and the same drivers, so the rule "the suite runs against Postgres" still protects production.

## Consequences

- Postgres data lives in a named Docker volume, not in `storage/runtime/`. A bind mount needs uid 999 and mode `0700`, and that ownership can fail on Docker Desktop for macOS and on WSL2 under `/mnt/c`. `storage/runtime/` holds only the user's files. The Report export is the backup path for Run history.
- Redis has no volume. It is ephemeral. Postgres is the source of truth for a Run, so the Run model must detect a Run whose job the queue lost.
- `db` and `redis` publish no port. Only `web` publishes, on `127.0.0.1`. A Postgres or Redis that the user already runs on 5432 or 6379 can never block `make start`.
- The Redis queue `retry_after` must be longer than the longest Run. If it is not, the queue dispatches a Run again while the Run is still in progress.
