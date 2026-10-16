# Ship both a SQLite and a flat-file storage backend behind one API

Innesto aims to be a modern library that uses the best technology a host allows, while still working in the most limited shared-hosting environments. The hosting survey found PDO SQLite confirmed on only two of seven budget hosts, and at least one of those runs SQLite 3.27. So both backends ship from day one, behind a collection-of-documents storage API owned by Roots.

- **SQLite** is used where `pdo_sqlite` is present, SQLite is 3.27 or later, and the database file has been shown to be unreachable over HTTP.
- **Guarded flat files** are used otherwise. These are `.php` files that begin with `<?php exit; ?>` followed by JSON, so a direct request prints nothing.

Modules are written once against the API and never touch SQL or files. The backend is chosen at install and stays fixed for the life of the install. Full rationale: [Where does Innesto data live on the server, and in what format?](https://github.com/as-webmetodo/innesto/issues/9).

## Considered Options

- **Flat files only**: rejected. It is simpler, but it gives up indexes, transactions and safe concurrent writes even on hosts that offer SQLite.
- **SQLite required**: rejected. It would exclude every host where SQLite is unconfirmed, and it breaks the rule that Roots may require only extensions PHP always enables.
- **Each Module implements both backends**: rejected. It doubles every Module's work.

## Consequences

- The storage API is limited to what both backends can serve: equality and range filters, ordering, limits and transactions. Modules declare the fields they filter on. SQLite indexes those fields, while the file backend scans.
- A binary SQLite file cannot protect itself the way a guarded `.php` file can, so SQLite is never chosen when its file could be downloaded. That covers a Data directory inside the webroot on a host that ignores `.htaccess`.
- SQLite runs with the default rollback journal, not WAL, because WAL breaks on the network filesystems some shared hosts use.
- Each Module and Roots has its own Store: one SQLite file or one directory each.
- Switching backends later needs a portable export and import, which is not designed yet.
