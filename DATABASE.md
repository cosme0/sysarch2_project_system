# AMDPMS Database Guide

## Which file do I use?

| File | Use it for |
| --- | --- |
| `setup.sql` | **Fresh install.** The authoritative, complete schema. |
| `migration_progress2.sql` | **Existing install.** Adds audit + payment columns. Safe to re-run. |
| `migration_reserve_stock.sql` | **Existing install.** Adds `medications.reserve_stock` (machine vs. storage stock). Safe to re-run. |
| `migration_inventory_control.sql` | Superseded — already folded into `setup.sql`. |
| `migration_pending_purchases.sql` | Superseded — already folded into `setup.sql`. |
| `amdpms_database.sql`, `amdpms_full_setup.sql`, `amdpms_backup.sql` | **Superseded snapshots.** Archived, do not install from them. |

## Why the old files were archived

`amdpms_database.sql`, `amdpms_full_setup.sql` and `amdpms_backup.sql` were
mysqldump-style snapshots taken at different moments. They had drifted apart:

- none of them contained the `purchases` table, so installing from them produced
  a database that crashed on the admin reordering screen
- `amdpms_full_setup.sql` and `amdpms_backup.sql` seeded a hardcoded
  administrator account with a known password — anyone with the project folder
  could sign in as admin
- they seeded different stock quantities than `setup.sql`

`setup.sql` is now the single source of truth. Every table definition lives
there exactly once.

## Fresh install

```bash
mysql -u root < setup.sql
```

or import `setup.sql` in phpMyAdmin.

No accounts are created by the script. Register through `index.php` — the first
account to register is automatically promoted to administrator.

## Upgrading an existing install

```bash
mysql -u root < migration_progress2.sql
```

The migration only adds what is missing. It never drops tables or rewrites
existing rows, and it is safe to run repeatedly.

## Machine stock vs. reserve stock

`medications` tracks two quantities:

- `stock` — units loaded in the dispenser. This is what the shop sells and what
  the Arduino can physically release.
- `reserve_stock` — backup units in storage, not inside the machine.

`stock + reserve_stock` is the total on hand and is the balance written to
`stock_movements.stock_after`. Confirmed purchases add to `reserve_stock`; an
admin moves units into the machine with **Reordering → Load into machine**.

## Triggers

`admin.php` also self-heals: if the `purchases` table is absent it is created on
load. This exists so an incomplete database cannot lock an administrator out of
the reordering screen, but it is a safety net, not a substitute for running the
migration.

## Credentials

`database.php`, `auth.php` and several page files connect with `root` and an
empty password. That is fine for a single-machine class demo on XAMPP. Do not
deploy this configuration to a machine that is reachable from the network.
