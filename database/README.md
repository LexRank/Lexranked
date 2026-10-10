# Database

Phase 1-4 store data in the WordPress database (custom post types, post meta,
and a small number of custom tables for high-volume append-only data such as
evidence claims and ranking snapshots). See `docs/data-model.md`.

- `schema/` - reference DDL for custom tables, kept in sync with the plugin's
  migration code. Engine-neutral where possible so the data can later move to
  PostgreSQL.
- `migrations/` - versioned, forward-only migrations. The plugin applies them
  on upgrade and records the applied version in `lexranked_db_version`.

PostgreSQL is intentionally **not** introduced yet; the REST API DTO layer is
the seam that allows a later migration without a frontend rewrite.
