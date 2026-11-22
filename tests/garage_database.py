#!/usr/bin/env python3
"""Check garage-bay allocation, ownership and towing transactions in SQLite.

Exercises the SQLite shapes present in the Pawn source, not game engine physics.
"""
from pathlib import Path
import re
import sqlite3
import tempfile

src = (Path(__file__).resolve().parents[1] / "Игра/gamemodes/slavia.pwn").read_text("utf-8")
ddl = re.findall(r'ExecuteSql\("(CREATE TABLE IF NOT EXISTS [^"]+)"\)', src)
assert len(ddl) == 6 and "garage_bays" in ddl[-1]
new_bays = re.findall(r'"(INSERT INTO garage_bays [^"]+)"', src)
assert len(new_bays) == 2 and new_bays[0] == new_bays[1], "Both new and old owners need garage reservation"
park = re.findall(r'"(UPDATE owned_vehicles SET [^"]+)"', src)
assert len(park) == 2 and park[0] == park[1], "The valet must persist the same parking fields"
cash = re.findall(r'"(UPDATE accounts SET cash=%d WHERE nickname=\'%s\')"', src)
assert len(cash) == 5

with tempfile.TemporaryDirectory() as root:
    path = Path(root) / "garage.db"
    with sqlite3.connect(path) as db:
        for sql in ddl[:5]:
            db.execute(sql)
        # Existing customers, prior to the garage update.
        db.execute("INSERT INTO accounts (nickname,password_hash,cash) VALUES ('OldOwner','hash',500)")
        db.execute("INSERT INTO owned_vehicles (nickname, model, x,y,z,a) VALUES ('OldOwner',410,1000.,-1500.,20.,30.)")
        db.execute(ddl[-1])
        db.execute(new_bays[0] % ("OldOwner", 0))
        db.execute("INSERT INTO accounts (nickname,password_hash,cash) VALUES ('NewOwner','hash',500)")
        db.execute("INSERT INTO owned_vehicles (nickname, model, x,y,z,a) VALUES ('NewOwner',426,1100.,-1600.,20.,30.)")
        db.execute(new_bays[0] % ("NewOwner", 1))
        db.commit()

        # Uniqueness: two users cannot be assigned the same permanent bay.
        db.execute("INSERT INTO accounts (nickname,password_hash,cash) VALUES ('Third','hash',500)")
        try:
            db.execute(new_bays[0] % ("Third", 0))
        except sqlite3.IntegrityError:
            pass
        else:
            raise AssertionError("Duplicate bay was accepted")
        # Valet: charging and returning the owned vehicle must commit together.
        db.execute("BEGIN IMMEDIATE TRANSACTION")
        db.execute(cash[-1] % (300, "OldOwner"))
        db.execute(park[-1] % (1466.0, -1742.0, 13.2, 90.0, "OldOwner"))
        db.execute("COMMIT")
        # On failure or interruption, ROLLBACK must keep the previous state.
        db.execute("BEGIN IMMEDIATE TRANSACTION")
        db.execute(cash[-1] % (100, "OldOwner"))
        db.execute(park[-1] % (999., 999., 3., 0., "OldOwner"))
        db.execute("ROLLBACK")

    with sqlite3.connect(path) as db:
        assert db.execute("SELECT cash FROM accounts WHERE nickname='OldOwner'").fetchone() == (300,)
        assert db.execute("SELECT x,y,z,a FROM owned_vehicles WHERE nickname='OldOwner'").fetchone() == (1466.0,-1742.0,13.2,90.0)
        assert db.execute("SELECT slot FROM garage_bays WHERE nickname='OldOwner'").fetchone() == (0,)
        assert db.execute("SELECT slot FROM garage_bays WHERE nickname='NewOwner'").fetchone() == (1,)
        assert db.execute("SELECT COUNT(*) FROM garage_bays").fetchone() == (2,)
        for sql in ddl: db.execute(sql)   # idempotent upgrade

print("SLAVIA garage persistence, unique bays, rollback: PASS")
