# Contract statistics CSV streaming fix

Replace repeated sorted OFFSET queries with one sorted Eloquent cursor. Disable MySQL buffering only on the actual read PDO during the stream. Release the cursor and restore the previous setting on success and failure. Keep SQLite support, all-version audit rows, schema, order, casts, provenance, and active-method markers unchanged.

Local work only. No production calls, commit, push, timeout change, connection, dependency, or flag. The parent owns rollout records and production benchmark approval.
