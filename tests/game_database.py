#!/usr/bin/env python3
"""Check the actual Pawn schema and courier SQL with an old account database."""
import re
import sqlite3
import tempfile
from pathlib import Path

source = (Path(__file__).resolve().parents[1] / "Игра/gamemodes/slavia.pwn").read_text(encoding="utf-8")
schema = re.findall(r'ExecuteSql\("(CREATE TABLE IF NOT EXISTS [^"]+)"\)', source)
assert len(schema) == 2, f"Expected 2 Pawn table definitions, got {len(schema)}"
assert "accounts" in schema[0] and "courier_progress" in schema[1]
updates = re.findall(r'"(UPDATE accounts SET cash=%d WHERE nickname=\'%s\')"', source)
inserts = re.findall(r'"(INSERT OR REPLACE INTO courier_progress [^"]+)"', source)
assert len(updates) == 1 and len(inserts) == 1

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

        # A failed transaction must not award money.
        db.execute("BEGIN IMMEDIATE TRANSACTION")
        db.execute(updates[0] % (2000, "Tester"))
        db.execute("ROLLBACK")

    with sqlite3.connect(dbpath) as db:
        assert db.execute("SELECT cash FROM accounts WHERE nickname='Tester'").fetchone() == (1750,)
        assert db.execute("SELECT deliveries, last_completed FROM courier_progress WHERE nickname='Tester'").fetchone() == (1, 1000)
        for statement in schema:
            db.execute(statement)  # migration is safe on repeated startup
        assert db.execute("SELECT count(*) FROM accounts").fetchone() == (1,)

print("SLAVIA courier SQLite migration and transaction: PASS")
