#!/usr/bin/env python3
"""Check the actual Pawn schema and courier SQL with an old account database."""
import re
import sqlite3
import tempfile
from pathlib import Path

source = (Path(__file__).resolve().parents[1] / "Игра/gamemodes/slavia.pwn").read_text(encoding="utf-8")
schema = re.findall(r'ExecuteSql\("(CREATE TABLE IF NOT EXISTS [^"]+)"\)', source)
assert len(schema) == 4, f"Expected 4 Pawn table definitions, got {len(schema)}"
assert all(name in query for name, query in zip(("accounts", "courier_progress", "supplies", "owned_vehicles"), schema))
updates = re.findall(r'"(UPDATE accounts SET cash=%d WHERE nickname=\'%s\')"', source)
inserts = re.findall(r'"(INSERT OR REPLACE INTO courier_progress [^"]+)"', source)
assert len(updates) == 3 and updates[0] == updates[1] == updates[2] and len(inserts) == 1
supplies = re.findall(r'"(INSERT OR REPLACE INTO supplies [^"]+)"', source)
assert len(supplies) == 1, "Actual Pawn inventory INSERT statement missing"
car_purchases = re.findall(r'"(INSERT INTO owned_vehicles [^"]+)"', source)
car_parking = re.findall(r'"(UPDATE owned_vehicles SET [^"]+)"', source)
assert len(car_purchases) == 1 and len(car_parking) == 1, "Pawn personal-vehicle SQL missing"

with tempfile.TemporaryDirectory() as folder:
    dbpath = Path(folder) / "slavia.db"
    with sqlite3.connect(dbpath) as db:
        # Simulate an account created by v0.1.0.
        db.execute(schema[0])
        db.execute("INSERT INTO accounts (nickname, password_hash) VALUES (?, ?)", ("Tester", "hash"))
        db.commit()

        # Restart with v0.2.0 schema: existing account remains.
        for statement in schema:
            db.execute(statement)
        db.execute("BEGIN IMMEDIATE TRANSACTION")
        db.execute(updates[0] % (1750, "Tester"))
        db.execute(inserts[0] % ("Tester", 1, 1000))
        db.execute("COMMIT")

        # Buy a bandage, store both the wallet and inventory in one transaction.
        db.execute("BEGIN IMMEDIATE TRANSACTION")
        db.execute(updates[1] % (1675, "Tester"))
        db.execute(supplies[0] % ("Tester", 1, 0))
        db.execute("COMMIT")
        assert db.execute("SELECT cash FROM accounts WHERE nickname='Tester'").fetchone() == (1675,)

        # Consuming a bandage persists stock without changing the wallet.
        db.execute("BEGIN IMMEDIATE TRANSACTION")
        db.execute(supplies[0] % ("Tester", 0, 0))
        db.execute("COMMIT")

        # A failed transaction must not award money.
        db.execute("BEGIN IMMEDIATE TRANSACTION")
        db.execute(updates[0] % (2000, "Tester"))
        db.execute(supplies[0] % ("Tester", 9, 9))
        db.execute("ROLLBACK")

        # Simulate courier income and buy a Manana; debit and ownership are atomic.
        db.execute("UPDATE accounts SET cash=6000 WHERE nickname='Tester'")
        db.commit()
        db.execute("BEGIN IMMEDIATE TRANSACTION")
        db.execute(updates[2] % (4200, "Tester"))
        db.execute(car_purchases[0] % ("Tester", 410, 1466.0, -1742.0, 13.2, 90.0))
        db.execute("COMMIT")
        db.execute(car_parking[0] % (1510.0, -1700.0, 13.4, 180.0, "Tester"))
        db.commit()

        # Database uniqueness prevents buying a second vehicle with the same account.
        try:
            db.execute("BEGIN IMMEDIATE TRANSACTION")
            db.execute(updates[2] % (0, "Tester"))
            db.execute(car_purchases[0] % ("Tester", 426, 1400.0, -1700.0, 13.0, 0.0))
        except sqlite3.IntegrityError:
            db.execute("ROLLBACK")
        else:
            db.execute("ROLLBACK")
            raise AssertionError("Second car must be rejected by UNIQUE constraint")

    with sqlite3.connect(dbpath) as db:
        assert db.execute("SELECT cash FROM accounts WHERE nickname='Tester'").fetchone() == (4200,)
        assert db.execute("SELECT model, x, y, z, a FROM owned_vehicles WHERE nickname='Tester'").fetchone() == (410, 1510.0, -1700.0, 13.4, 180.0)
        assert db.execute("SELECT bandages, medkits FROM supplies WHERE nickname='Tester'").fetchone() == (0, 0)
        assert db.execute("SELECT deliveries, last_completed FROM courier_progress WHERE nickname='Tester'").fetchone() == (1, 1000)
        for statement in schema:
            db.execute(statement)  # migration is safe on repeated startup
        assert db.execute("SELECT count(*) FROM accounts").fetchone() == (1,)

print("SLAVIA SQLite accounts, courier, inventory, vehicles, and rollback: PASS")
