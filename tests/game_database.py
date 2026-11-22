#!/usr/bin/env python3
"""Run SQLite migration and transaction checks using SQL read from the Pawn source.

These tests validate database operations only, not game networking or physics.
"""
import re
import sqlite3
import tempfile
from pathlib import Path

source = (Path(__file__).resolve().parents[1] / "Игра/gamemodes/slavia.pwn").read_text(encoding="utf-8")
schema = re.findall(r'ExecuteSql\("(CREATE TABLE IF NOT EXISTS [^"]+)"\)', source)
expected = ("accounts", "courier_progress", "supplies", "owned_vehicles", "vehicle_state", "garage_bays")
assert len(schema) == len(expected), f"Expected {len(expected)} Pawn CREATE TABLEs, got {len(schema)}"
assert all(name in ddl for name, ddl in zip(expected, schema))
cash_update = re.findall(r'"(UPDATE accounts SET cash=%d WHERE nickname=\'%s\')"', source)
assert len(cash_update) == 6, f"Expected 6 wallet updates, got {len(cash_update)}"
courier_sql = re.findall(r'"(INSERT OR REPLACE INTO courier_progress [^"]+)"', source)
supplies_sql = re.findall(r'"(INSERT OR REPLACE INTO supplies [^"]+)"', source)
buy_car = re.findall(r'"(INSERT INTO owned_vehicles [^"]+)"', source)
park_car = re.findall(r'"(UPDATE owned_vehicles SET [^"]+)"', source)
vehicle_state_buy = re.findall(r'"(INSERT INTO vehicle_state [^"]+)"', source)
vehicle_state_upsert = re.findall(r'"(INSERT OR REPLACE INTO vehicle_state [^"]+)"', source)
assert all(len(q) == 1 for q in (courier_sql, supplies_sql, buy_car, vehicle_state_buy))
assert len(park_car) == 2
assert len(vehicle_state_upsert) == 2
assert vehicle_state_upsert[0] == vehicle_state_upsert[1]


def wallet(db, user="Tester"):
    return db.execute("SELECT cash FROM accounts WHERE nickname=?", (user,)).fetchone()[0]


with tempfile.TemporaryDirectory() as work:
    file = Path(work) / "slavia.db"
    with sqlite3.connect(file) as db:
        db.execute(schema[0])
        db.execute("INSERT INTO accounts (nickname,password_hash,cash) VALUES (?,?,?)",
                   ("Tester", "example-hash", 6000))
        db.commit()

        # Upgrade original account, then courier, then inventory, then v0.4 car.
        for ddl in schema[:4]:
            db.execute(ddl)
        db.execute("BEGIN IMMEDIATE")
        db.execute(courier_sql[0] % ("Tester", 1, 1000))
        db.execute(supplies_sql[0] % ("Tester", 2, 1))
        db.execute(cash_update[2] % (4200, "Tester"))
        db.execute(buy_car[0] % ("Tester", 410, 1466., -1742., 13.2, 90.))
        db.execute("COMMIT")
        db.execute(park_car[0] % (1510., -1700., 13.4, 180., "Tester"))
        db.commit()

        # Upgrade a car purchased under v0.4: no row yet in vehicle_state.
        db.execute(schema[4])
        assert db.execute("SELECT count(*) FROM vehicle_state WHERE nickname='Tester'").fetchone()[0] == 0
        assert wallet(db) == 4200
        assert db.execute("SELECT deliveries FROM courier_progress WHERE nickname='Tester'").fetchone() == (1,)
        assert db.execute("SELECT bandages,medkits FROM supplies WHERE nickname='Tester'").fetchone() == (2, 1)

        # Fuel consumption persists, and locking a car is saved in one record.
        db.execute(vehicle_state_upsert[0] % ("Tester", 85, 1))
        db.commit()

        # Refill 15 units at 7 currency/unit, updating wallet and state atomically.
        db.execute("BEGIN IMMEDIATE")
        db.execute(cash_update[3] % (4095, "Tester"))
        db.execute(vehicle_state_upsert[1] % ("Tester", 100, 1))
        db.execute("COMMIT")
        assert wallet(db) == 4095

        # A transaction that fails before commit cannot charge money or change state.
        db.execute("BEGIN IMMEDIATE")
        db.execute(cash_update[3] % (100, "Tester"))
        db.execute(vehicle_state_upsert[1] % ("Tester", 1, 0))
        db.execute("ROLLBACK")
        assert wallet(db) == 4095
        assert db.execute("SELECT fuel,locked FROM vehicle_state WHERE nickname='Tester'").fetchone() == (100, 1)

        # New buyer under v0.5 receives both car and full tank atomically.
        db.execute("INSERT INTO accounts (nickname,password_hash,cash) VALUES (?,?,?)",
                   ("NewOwner", "example-hash", 2000))
        db.commit()
        db.execute("BEGIN IMMEDIATE")
        db.execute(cash_update[2] % (200, "NewOwner"))
        db.execute(buy_car[0] % ("NewOwner", 410, 1466., -1742., 13.2, 90.))
        db.execute(vehicle_state_buy[0] % ("NewOwner", 100))
        db.execute("COMMIT")
        assert wallet(db, "NewOwner") == 200

        # A second purchase must fail and return the full wallet balance.
        try:
            db.execute("BEGIN IMMEDIATE")
            db.execute(cash_update[2] % (0, "Tester"))
            db.execute(buy_car[0] % ("Tester", 426, 1400., -1700., 13., 0.))
        except sqlite3.IntegrityError:
            db.execute("ROLLBACK")
        else:
            db.execute("ROLLBACK")
            raise AssertionError("Duplicate personal car was not blocked")

    with sqlite3.connect(file) as reopened:
        for ddl in schema:
            reopened.execute(ddl)
        assert wallet(reopened) == 4095
        assert reopened.execute("SELECT fuel,locked FROM vehicle_state WHERE nickname='Tester'").fetchone() == (100, 1)
        assert reopened.execute("SELECT fuel,locked FROM vehicle_state WHERE nickname='NewOwner'").fetchone() == (100, 0)
        assert reopened.execute("SELECT model,x,y,z,a FROM owned_vehicles WHERE nickname='Tester'").fetchone() == (410, 1510., -1700., 13.4, 180.)
        assert reopened.execute("SELECT COUNT(*) FROM accounts").fetchone() == (2,)
        assert reopened.execute("SELECT COUNT(*) FROM garage_bays").fetchone() == (0,)

print("SLAVIA SQLite v0.1-v0.5 migrations, fuel/locks and rollback: PASS")
