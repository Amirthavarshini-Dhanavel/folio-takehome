Migration files live here and are applied by `seed.php` after `schema.sql`.

Use zero-padded numeric prefixes so migrations run in a predictable order, for example:

```text
001_add_publish_at_to_documents.sql
002_add_readable_id_to_documents.sql
```

This app reseeds `db.sqlite` from scratch during `docker compose up`, so migrations describe schema changes for reviewers without adding a full production migration framework.
