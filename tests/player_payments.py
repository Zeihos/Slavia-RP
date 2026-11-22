#!/usr/bin/env python3
"""Verify the Pawn money-transfer SQLite statements, migration and rollback.

This does not execute the Pawn game engine; network/range checks need live clients.
"""
import re
import sqlite3
import tempfile
from pathlib import Path

src = (Path(__file__).resolve().parents[1] / "Игра/gamemodes/slavia.pwn").read_text("utf-8")
schema = re.findall(r'ExecuteSql\("(CREATE TABLE IF NOT EXISTS [^"]+)"\)', src)
assert len(schema) == 7 and "money_transfers" in schema[-1]
updates = re.findall(r'"(UPDATE accounts SET cash=%d WHERE nickname=\'%s\' AND cash=%d)"', src)
audit = re.findall(r'"(INSERT INTO money_transfers \(sender, recipient, amount\) VALUES \([^"]+\))"', src)
assert len(updates) == 2 and updates[0] == updates[1]
assert len(audit) == 1
pay = src.split("stock PayNearbyPlayer(playerid, targetid, amount)", 1)[1].split(
    "stock ShowAccountDialog(playerid)", 1
)[0]
for guard in (
    "targetid == playerid",
    "IsPlayerConnected(targetid)",
    "gAuthenticated[targetid]",
    "GetPlayerInterior(playerid) != GetPlayerInterior(targetid)",
    "GetPlayerVirtualWorld(playerid) != GetPlayerVirtualWorld(targetid)",
    "IsPlayerInRangeOfPoint(targetid, PAY_MAX_DISTANCE",
    "PAY_MAX_AMOUNT",
    "PAY_COOLDOWN_SECONDS",
    'ExecuteSql("BEGIN IMMEDIATE TRANSACTION")',
    "SqlChangedOne()",
    'ExecuteSql("ROLLBACK")',
    'ExecuteSql("COMMIT")',
):
    assert guard in pay, "Missing Pawn transfer guard: " + guard

with tempfile.TemporaryDirectory() as folder:
    path = Path(folder) / "legacy.db"
    with sqlite3.connect(path) as db:
        for ddl in schema[:6]:
            db.execute(ddl)
        db.execute("INSERT INTO accounts(nickname,password_hash,cash) VALUES('Sender','hash',1500)")
        db.execute("INSERT INTO accounts(nickname,password_hash,cash) VALUES('Recipient','hash',500)")
        db.commit()
        db.execute(schema[-1])  # v0.7 -> v0.8 upgrade

        # Valid payment: debit, credit and journal entry in one transaction.
        db.execute("BEGIN IMMEDIATE TRANSACTION")
        assert db.execute(updates[0] % (1250, "Sender", 1500)).rowcount == 1
        assert db.execute(updates[1] % (750, "Recipient", 500)).rowcount == 1
        db.execute(audit[0] % ("Sender", "Recipient", 250))
        db.execute("COMMIT")

        # Stale recipient balance must roll back the already-debited sender.
        db.execute("BEGIN IMMEDIATE TRANSACTION")
        assert db.execute(updates[0] % (1150, "Sender", 1250)).rowcount == 1
        assert db.execute(updates[1] % (850, "Recipient", 500)).rowcount == 0
        db.execute("ROLLBACK")

        # Stale sender balance cannot charge or pay anybody.
        db.execute("BEGIN IMMEDIATE TRANSACTION")
        assert db.execute(updates[0] % (1000, "Sender", 1500)).rowcount == 0
        db.execute("ROLLBACK")

        # Invalid audit amount triggers a rollback of both balance updates.
        db.execute("BEGIN IMMEDIATE TRANSACTION")
        assert db.execute(updates[0] % (1200, "Sender", 1250)).rowcount == 1
        assert db.execute(updates[1] % (800, "Recipient", 750)).rowcount == 1
        try:
            db.execute(audit[0] % ("Sender", "Recipient", 6000))
        except sqlite3.IntegrityError:
            db.execute("ROLLBACK")
        else:
            db.execute("ROLLBACK")
            raise AssertionError("Transfer audit accepted more than 5000")

    with sqlite3.connect(path) as db:
        assert db.execute("SELECT cash FROM accounts WHERE nickname='Sender'").fetchone() == (1250,)
        assert db.execute("SELECT cash FROM accounts WHERE nickname='Recipient'").fetchone() == (750,)
        assert db.execute("SELECT sender,recipient,amount FROM money_transfers").fetchall() == [
            ("Sender", "Recipient", 250)
        ]
        for ddl in schema: db.execute(ddl)
        assert db.execute("SELECT count(*) FROM money_transfers").fetchone() == (1,)

print("SLAVIA SQLite v0.8 player payments, audit and rollback: PASS")
