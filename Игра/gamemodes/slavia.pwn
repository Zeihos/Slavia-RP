#include <a_samp>
#include <samp_bcrypt>

// SLAVIA ROLEPLAY 0.2.0: a small, independently written open.mp gamemode.
// Passwords are never saved as plain text. SQLite lives in scriptfiles/.
// Production launch requires moderation, anti-cheat and deployment review.

#define SLAVIA_VERSION      "0.2.0"
#define DATABASE_FILE       "slavia.db"
#define DIALOG_REGISTER     (3400)
#define DIALOG_LOGIN        (3401)
#define MAX_AUTH_ATTEMPTS   (5)
#define STARTING_CASH       (1500)
#define COURIER_REWARD      (250)
#define COURIER_COOLDOWN_SECONDS (60)
#define COURIER_PICKUP_X   (1462.0)
#define COURIER_PICKUP_Y   (-1749.0)
#define COURIER_PICKUP_Z   (13.5)
#define COURIER_DROP_X     (1731.0)
#define COURIER_DROP_Y     (-1652.0)
#define COURIER_DROP_Z     (20.2)

#define COLOR_WHITE         0xFFFFFFFF
#define COLOR_PINK          0xF542A9FF
#define COLOR_MUTED         0xB6B6C8FF
#define COLOR_ERROR         0xFF6B77FF
#define COLOR_RP            0xD9BEDFFF

#define START_X 1481.0000
#define START_Y -1748.0000
#define START_Z 13.5469
#define START_A 0.0000

new DB:gDatabase = DB:0;
new gSessionSequence = 0;

new bool:gAuthenticated[MAX_PLAYERS];
new bool:gAuthBusy[MAX_PLAYERS];
new bool:gHasAccount[MAX_PLAYERS];
new gAuthAttempts[MAX_PLAYERS];
new gSessionId[MAX_PLAYERS];
new gNicknames[MAX_PLAYERS][MAX_PLAYER_NAME + 1];
new gPasswordHashes[MAX_PLAYERS][BCRYPT_HASH_LENGTH];

new gCash[MAX_PLAYERS];
new gSkin[MAX_PLAYERS];
new gPlayMinutes[MAX_PLAYERS];
new gJoinedTick[MAX_PLAYERS];
new Float:gPosX[MAX_PLAYERS], Float:gPosY[MAX_PLAYERS];
new Float:gPosZ[MAX_PLAYERS], Float:gPosA[MAX_PLAYERS];

new gCourierStage[MAX_PLAYERS];
new gCourierLastComplete[MAX_PLAYERS];
new gCourierDeliveries[MAX_PLAYERS];

forward OnSlaviaHashCreated(playerid, session);
forward OnSlaviaPasswordChecked(playerid, bool:valid, session);

main()
{
    print("SLAVIA ROLEPLAY - open.mp development gamemode");
}

stock bool:IsSafeNickname(const name[])
{
    new length = strlen(name);
    if (length < 3 || length > MAX_PLAYER_NAME) return false;

    for (new i = 0; i < length; i++)
    {
        new ch = name[i];
        if (!((ch >= 'A' && ch <= 'Z') ||
              (ch >= 'a' && ch <= 'z') ||
              (ch >= '0' && ch <= '9') || ch == '_'))
        {
            return false;
        }
    }
    return true;
}

stock ResetSession(playerid)
{
    gAuthenticated[playerid] = false;
    gAuthBusy[playerid] = false;
    gHasAccount[playerid] = false;
    gAuthAttempts[playerid] = 0;
    gNicknames[playerid][0] = '\0';
    gPasswordHashes[playerid][0] = '\0';
    gCash[playerid] = STARTING_CASH;
    gSkin[playerid] = 26;
    gPlayMinutes[playerid] = 0;
    gJoinedTick[playerid] = 0;
    gPosX[playerid] = START_X;
    gPosY[playerid] = START_Y;
    gPosZ[playerid] = START_Z;
    gPosA[playerid] = START_A;
    gCourierStage[playerid] = 0;
    gCourierLastComplete[playerid] = 0;
    gCourierDeliveries[playerid] = 0;
    return 1;
}

stock bool:ExecuteSql(const sql[])
{
    new DBResult:result = db_query(gDatabase, sql);
    if (!result)
    {
        print("[SLAVIA] SQLite statement failed.");
        return false;
    }
    db_free_result(result);
    return true;
}

stock ShowAccountDialog(playerid)
{
    if (gHasAccount[playerid])
    {
        ShowPlayerDialog(playerid, DIALOG_LOGIN, DIALOG_STYLE_PASSWORD,
            "SLAVIA | Login",
            "Welcome back. Enter the password for your character:",
            "Login", "Quit");
    }
    else
    {
        ShowPlayerDialog(playerid, DIALOG_REGISTER, DIALOG_STYLE_PASSWORD,
            "SLAVIA | Registration",
            "Create a password (8-64 characters) for your new character:",
            "Register", "Quit");
    }
    return 1;
}

stock LoadCharacter(playerid)
{
    new query[240], DBResult:result;
    format(query, sizeof(query),
        "SELECT cash, skin, minutes, x, y, z, a FROM accounts WHERE nickname='%s' LIMIT 1",
        gNicknames[playerid]);
    result = db_query(gDatabase, query);
    if (!result || !db_num_rows(result))
    {
        if (result) db_free_result(result);
        return 0;
    }
    gCash[playerid] = db_get_field_assoc_int(result, "cash");
    gSkin[playerid] = db_get_field_assoc_int(result, "skin");
    gPlayMinutes[playerid] = db_get_field_assoc_int(result, "minutes");
    gPosX[playerid] = db_get_field_assoc_float(result, "x");
    gPosY[playerid] = db_get_field_assoc_float(result, "y");
    gPosZ[playerid] = db_get_field_assoc_float(result, "z");
    gPosA[playerid] = db_get_field_assoc_float(result, "a");
    db_free_result(result);

    if (gCash[playerid] < 0) gCash[playerid] = 0;
    if (gCash[playerid] > 100000000) gCash[playerid] = 100000000;
    if (gSkin[playerid] < 0 || gSkin[playerid] > 311) gSkin[playerid] = 26;
    if (gPlayMinutes[playerid] < 0) gPlayMinutes[playerid] = 0;

    // A bad saved position must not crash the client or trap a player.
    if (gPosX[playerid] < -3000.0 || gPosX[playerid] > 3000.0 ||
        gPosY[playerid] < -3000.0 || gPosY[playerid] > 3000.0 ||
        gPosZ[playerid] < -100.0 || gPosZ[playerid] > 2000.0)
    {
        gPosX[playerid] = START_X;
        gPosY[playerid] = START_Y;
        gPosZ[playerid] = START_Z;
        gPosA[playerid] = START_A;
    }
    return 1;
}

stock bool:LoadCourierProgress(playerid)
{
    // Separate table preserves every account created by v0.1.0.
    new query[240], DBResult:result;
    format(query, sizeof(query),
        "SELECT deliveries, last_completed FROM courier_progress WHERE nickname='%s' LIMIT 1",
        gNicknames[playerid]);
    result = db_query(gDatabase, query);
    if (!result) return false;

    gCourierDeliveries[playerid] = 0;
    gCourierLastComplete[playerid] = 0;
    if (db_num_rows(result) > 0)
    {
        gCourierDeliveries[playerid] = db_get_field_assoc_int(result, "deliveries");
        gCourierLastComplete[playerid] = db_get_field_assoc_int(result, "last_completed");
        if (gCourierDeliveries[playerid] < 0) gCourierDeliveries[playerid] = 0;
        if (gCourierDeliveries[playerid] > 1000000) gCourierDeliveries[playerid] = 1000000;
        if (gCourierLastComplete[playerid] < 0) gCourierLastComplete[playerid] = 0;
    }
    db_free_result(result);
    return true;
}

stock bool:CompleteCourierDelivery(playerid)
{
    // Commit reward and progress together. Do not award money on database failure.
    if (gCash[playerid] > 100000000 - COURIER_REWARD ||
        gCourierDeliveries[playerid] >= 1000000) return false;

    new newCash = gCash[playerid] + COURIER_REWARD;
    new completed = gCourierDeliveries[playerid] + 1;
    new now = gettime(), query[380];

    if (!ExecuteSql("BEGIN IMMEDIATE TRANSACTION")) return false;
    format(query, sizeof(query),
        "UPDATE accounts SET cash=%d WHERE nickname='%s'",
        newCash, gNicknames[playerid]);
    if (!ExecuteSql(query))
    {
        ExecuteSql("ROLLBACK");
        return false;
    }

    format(query, sizeof(query),
        "INSERT OR REPLACE INTO courier_progress (nickname, deliveries, last_completed) VALUES ('%s', %d, %d)",
        gNicknames[playerid], completed, now);
    if (!ExecuteSql(query))
    {
        ExecuteSql("ROLLBACK");
        return false;
    }
    if (!ExecuteSql("COMMIT"))
    {
        ExecuteSql("ROLLBACK");
        return false;
    }

    gCash[playerid] = newCash;
    gCourierDeliveries[playerid] = completed;
    gCourierLastComplete[playerid] = now;
    GivePlayerMoney(playerid, COURIER_REWARD);
    return true;
}

stock SaveCharacter(playerid)
{
    if (!gAuthenticated[playerid]) return 0;

    new playerState = GetPlayerState(playerid);
    if (playerState == PLAYER_STATE_ONFOOT || playerState == PLAYER_STATE_DRIVER ||
        playerState == PLAYER_STATE_PASSENGER)
    {
        GetPlayerPos(playerid, gPosX[playerid], gPosY[playerid], gPosZ[playerid]);
        GetPlayerFacingAngle(playerid, gPosA[playerid]);
    }
    new elapsed = (GetTickCount() - gJoinedTick[playerid]) / 60000;
    if (elapsed > 0)
    {
        gPlayMinutes[playerid] += elapsed;
        // Keep fractional minutes across repeated /save calls.
        gJoinedTick[playerid] += elapsed * 60000;
    }

    new query[400];
    format(query, sizeof(query),
        "UPDATE accounts SET cash=%d, skin=%d, minutes=%d, x=%f, y=%f, z=%f, a=%f WHERE nickname='%s'",
        gCash[playerid], gSkin[playerid], gPlayMinutes[playerid],
        gPosX[playerid], gPosY[playerid], gPosZ[playerid], gPosA[playerid],
        gNicknames[playerid]);
    return ExecuteSql(query);
}

stock EnterWorld(playerid)
{
    if (!LoadCharacter(playerid) || !LoadCourierProgress(playerid))
    {
        SendClientMessage(playerid, COLOR_ERROR, "Could not load character. Reconnect later.");
        Kick(playerid);
        return 0;
    }
    gAuthenticated[playerid] = true;
    gJoinedTick[playerid] = GetTickCount();
    SetSpawnInfo(playerid, 255, gSkin[playerid],
        gPosX[playerid], gPosY[playerid], gPosZ[playerid], gPosA[playerid],
        0, 0, 0, 0, 0, 0);
    SpawnPlayer(playerid);
    SendClientMessage(playerid, COLOR_PINK, "Welcome to SLAVIA ROLEPLAY!");
    SendClientMessage(playerid, COLOR_WHITE, "Commands: /help, /jobs, /courier, /jobstats.");
    return 1;
}

stock SendLocalMessage(playerid, color, const message[], Float:radius)
{
    new Float:x, Float:y, Float:z;
    GetPlayerPos(playerid, x, y, z);
    for (new other = 0; other < MAX_PLAYERS; other++)
    {
        if (!IsPlayerConnected(other) || !gAuthenticated[other]) continue;
        if (GetPlayerVirtualWorld(other) != GetPlayerVirtualWorld(playerid)) continue;
        if (GetPlayerInterior(other) != GetPlayerInterior(playerid)) continue;
        if (IsPlayerInRangeOfPoint(other, radius, x, y, z))
        {
            SendClientMessage(other, color, message);
        }
    }
    return 1;
}

public OnGameModeInit()
{
    SetGameModeText("SLAVIA RP 0.2.0");
    ShowPlayerMarkers(PLAYER_MARKERS_MODE_OFF);
    EnableStuntBonusForAll(0);
    UsePlayerPedAnims();
    AddPlayerClass(26, START_X, START_Y, START_Z, START_A, 0, 0, 0, 0, 0, 0);

    gDatabase = db_open(DATABASE_FILE);
    if (!gDatabase)
    {
        print("[SLAVIA] FATAL: cannot open scriptfiles/slavia.db.");
        SendRconCommand("exit");
        return 0;
    }
    if (!ExecuteSql("CREATE TABLE IF NOT EXISTS accounts (nickname TEXT PRIMARY KEY COLLATE NOCASE, password_hash TEXT NOT NULL, cash INTEGER NOT NULL DEFAULT 1500, skin INTEGER NOT NULL DEFAULT 26, minutes INTEGER NOT NULL DEFAULT 0, x REAL NOT NULL DEFAULT 1481.0, y REAL NOT NULL DEFAULT -1748.0, z REAL NOT NULL DEFAULT 13.5469, a REAL NOT NULL DEFAULT 0.0, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)"))
    {
        SendRconCommand("exit");
        return 0;
    }
    if (!ExecuteSql("CREATE TABLE IF NOT EXISTS courier_progress (nickname TEXT PRIMARY KEY COLLATE NOCASE, deliveries INTEGER NOT NULL DEFAULT 0, last_completed INTEGER NOT NULL DEFAULT 0)"))
    {
        print("[SLAVIA] FATAL: cannot initialize courier progress.");
        SendRconCommand("exit");
        return 0;
    }
    print("[SLAVIA] SQLite accounts and courier progress initialized.");
    return 1;
}

public OnGameModeExit()
{
    for (new playerid = 0; playerid < MAX_PLAYERS; playerid++)
    {
        if (IsPlayerConnected(playerid) && gAuthenticated[playerid])
        {
            SaveCharacter(playerid);
        }
    }
    if (gDatabase) db_close(gDatabase);
    return 1;
}

public OnPlayerConnect(playerid)
{
    ResetSession(playerid);
    gSessionId[playerid] = ++gSessionSequence;
    GetPlayerName(playerid, gNicknames[playerid], MAX_PLAYER_NAME + 1);
    if (!IsSafeNickname(gNicknames[playerid]))
    {
        SendClientMessage(playerid, COLOR_ERROR,
            "Use 3-24 Latin letters, digits or underscore in your nickname.");
        Kick(playerid);
        return 1;
    }

    new query[230], DBResult:result;
    format(query, sizeof(query),
        "SELECT password_hash FROM accounts WHERE nickname='%s' LIMIT 1",
        gNicknames[playerid]);
    result = db_query(gDatabase, query);
    if (!result)
    {
        SendClientMessage(playerid, COLOR_ERROR, "Database unavailable. Try again later.");
        Kick(playerid);
        return 1;
    }
    if (db_num_rows(result) > 0)
    {
        db_get_field_assoc(result, "password_hash",
            gPasswordHashes[playerid], BCRYPT_HASH_LENGTH);
        gHasAccount[playerid] = true;
    }
    db_free_result(result);
    ShowAccountDialog(playerid);
    return 1;
}

public OnPlayerDisconnect(playerid, reason)
{
    SaveCharacter(playerid);
    gSessionId[playerid] = ++gSessionSequence;
    ResetSession(playerid);
    return 1;
}

public OnPlayerRequestSpawn(playerid)
{
    return gAuthenticated[playerid] ? 1 : 0;
}

public OnPlayerSpawn(playerid)
{
    if (!gAuthenticated[playerid])
    {
        TogglePlayerControllable(playerid, 0);
        ShowAccountDialog(playerid);
        return 1;
    }
    TogglePlayerControllable(playerid, 1);
    SetPlayerInterior(playerid, 0);
    SetPlayerVirtualWorld(playerid, 0);
    ResetPlayerMoney(playerid);
    GivePlayerMoney(playerid, gCash[playerid]);
    SetPlayerColor(playerid, COLOR_PINK);
    if (gCourierStage[playerid] == 1)
    {
        SetPlayerCheckpoint(playerid, COURIER_PICKUP_X, COURIER_PICKUP_Y, COURIER_PICKUP_Z, 3.5);
    }
    else if (gCourierStage[playerid] == 2)
    {
        SetPlayerCheckpoint(playerid, COURIER_DROP_X, COURIER_DROP_Y, COURIER_DROP_Z, 3.5);
    }
    return 1;
}

public OnPlayerDeath(playerid, killerid, reason)
{
    if (gAuthenticated[playerid])
    {
        DisablePlayerCheckpoint(playerid);
        gCourierStage[playerid] = 0;
    }
    return 1;
}

public OnDialogResponse(playerid, dialogid, response, listitem, inputtext[])
{
    if (dialogid != DIALOG_REGISTER && dialogid != DIALOG_LOGIN) return 0;
    if (!response)
    {
        Kick(playerid);
        return 1;
    }
    if (gAuthenticated[playerid]) return 1;
    if (gAuthBusy[playerid]) return 1;

    new length = strlen(inputtext);
    if (length < 8 || length > 64)
    {
        SendClientMessage(playerid, COLOR_ERROR, "Password must be 8-64 characters.");
        ShowAccountDialog(playerid);
        return 1;
    }

    gAuthBusy[playerid] = true;
    if (dialogid == DIALOG_REGISTER && !gHasAccount[playerid])
    {
        bcrypt_hash(playerid, "OnSlaviaHashCreated",
            inputtext, BCRYPT_COST, "d", gSessionId[playerid]);
    }
    else if (dialogid == DIALOG_LOGIN && gHasAccount[playerid])
    {
        bcrypt_verify(playerid, "OnSlaviaPasswordChecked",
            inputtext, gPasswordHashes[playerid], "d", gSessionId[playerid]);
    }
    else
    {
        gAuthBusy[playerid] = false;
        ShowAccountDialog(playerid);
    }
    return 1;
}

public OnSlaviaHashCreated(playerid, session)
{
    if (!IsPlayerConnected(playerid) || session != gSessionId[playerid] ||
        gAuthenticated[playerid] || !gAuthBusy[playerid]) return 1;

    new hash[BCRYPT_HASH_LENGTH];
    bcrypt_get_hash(hash, sizeof(hash));
    gAuthBusy[playerid] = false;

    new query[300];
    format(query, sizeof(query),
        "INSERT OR IGNORE INTO accounts (nickname, password_hash) VALUES ('%s','%s')",
        gNicknames[playerid], hash);
    if (!ExecuteSql(query))
    {
        SendClientMessage(playerid, COLOR_ERROR, "Registration failed (database error).");
        Kick(playerid);
        return 1;
    }
    // Re-read the stored hash in case a concurrent registration already exists.
    new DBResult:result;
    format(query, sizeof(query),
        "SELECT password_hash FROM accounts WHERE nickname='%s' LIMIT 1",
        gNicknames[playerid]);
    result = db_query(gDatabase, query);
    if (!result || !db_num_rows(result))
    {
        if (result) db_free_result(result);
        Kick(playerid);
        return 1;
    }
    db_get_field_assoc(result, "password_hash",
        gPasswordHashes[playerid], BCRYPT_HASH_LENGTH);
    db_free_result(result);
    if (strcmp(hash, gPasswordHashes[playerid]) != 0)
    {
        SendClientMessage(playerid, COLOR_ERROR, "Account name is already taken.");
        Kick(playerid);
        return 1;
    }
    gHasAccount[playerid] = true;
    EnterWorld(playerid);
    return 1;
}

public OnSlaviaPasswordChecked(playerid, bool:valid, session)
{
    if (!IsPlayerConnected(playerid) || session != gSessionId[playerid] ||
        gAuthenticated[playerid] || !gAuthBusy[playerid]) return 1;
    gAuthBusy[playerid] = false;

    if (!valid)
    {
        gAuthAttempts[playerid]++;
        if (gAuthAttempts[playerid] >= MAX_AUTH_ATTEMPTS)
        {
            SendClientMessage(playerid, COLOR_ERROR, "Too many failed attempts.");
            Kick(playerid);
            return 1;
        }
        SendClientMessage(playerid, COLOR_ERROR, "Wrong password.");
        ShowAccountDialog(playerid);
        return 1;
    }
    EnterWorld(playerid);
    return 1;
}

public OnPlayerText(playerid, text[])
{
    if (!gAuthenticated[playerid]) return 0;
    new line[190];
    format(line, sizeof(line), "%s says: %s", gNicknames[playerid], text);
    SendLocalMessage(playerid, COLOR_WHITE, line, 25.0);
    return 0;
}

public OnPlayerCommandText(playerid, cmdtext[])
{
    if (!gAuthenticated[playerid])
    {
        SendClientMessage(playerid, COLOR_ERROR, "Log in before using commands.");
        return 1;
    }

    if (!strcmp(cmdtext, "/help", true))
    {
        SendClientMessage(playerid, COLOR_PINK, "SLAVIA: /stats, /save, /jobs, /courier, /jobstats, /quitjob");
        SendClientMessage(playerid, COLOR_MUTED, "RP: /me [action], /do [event], /b [OOC]");
        SendClientMessage(playerid, COLOR_MUTED, "Aliases: /rabota, /dostavki, /otmena");
        return 1;
    }
    if (!strcmp(cmdtext, "/stats", true))
    {
        new line[140];
        format(line, sizeof(line),
            "Character: %s | Cash: $%d | Played: %d min",
            gNicknames[playerid], gCash[playerid], gPlayMinutes[playerid]);
        SendClientMessage(playerid, COLOR_PINK, line);
        return 1;
    }
    if (!strcmp(cmdtext, "/save", true))
    {
        if (SaveCharacter(playerid))
            SendClientMessage(playerid, COLOR_PINK, "Character saved.");
        else
            SendClientMessage(playerid, COLOR_ERROR, "Save failed.");
        return 1;
    }
    if (!strcmp(cmdtext, "/jobs", true))
    {
        SendClientMessage(playerid, COLOR_PINK, "Courier: $250 for each successful delivery.");
        SendClientMessage(playerid, COLOR_MUTED, "Begin near the city hall: /courier or /rabota.");
        return 1;
    }
    if (!strcmp(cmdtext, "/jobstats", true) || !strcmp(cmdtext, "/dostavki", true))
    {
        new line[140];
        format(line, sizeof(line), "Deliveries: %d | Total earned: $%d",
            gCourierDeliveries[playerid], gCourierDeliveries[playerid] * COURIER_REWARD);
        SendClientMessage(playerid, COLOR_PINK, line);
        return 1;
    }
    if (!strcmp(cmdtext, "/courier", true) || !strcmp(cmdtext, "/rabota", true))
    {
        if (gCourierStage[playerid])
        {
            SendClientMessage(playerid, COLOR_MUTED, "You already have a delivery.");
            return 1;
        }
        if (GetPlayerState(playerid) != PLAYER_STATE_ONFOOT ||
            GetPlayerInterior(playerid) != 0 || GetPlayerVirtualWorld(playerid) != 0 ||
            !IsPlayerInRangeOfPoint(playerid, 60.0, START_X, START_Y, START_Z))
        {
            SendClientMessage(playerid, COLOR_ERROR, "Start courier work on foot near city hall.");
            return 1;
        }
        new remaining = gCourierLastComplete[playerid] + COURIER_COOLDOWN_SECONDS - gettime();
        if (gCourierLastComplete[playerid] != 0 && remaining > 0)
        {
            new line[100];
            format(line, sizeof(line), "Courier cooldown: %d sec remaining.", remaining);
            SendClientMessage(playerid, COLOR_ERROR, line);
            return 1;
        }
        gCourierStage[playerid] = 1;
        SetPlayerCheckpoint(playerid, COURIER_PICKUP_X, COURIER_PICKUP_Y, COURIER_PICKUP_Z, 3.5);
        SendClientMessage(playerid, COLOR_PINK, "Courier: collect the parcel at the checkpoint.");
        return 1;
    }
    if (!strcmp(cmdtext, "/quitjob", true) || !strcmp(cmdtext, "/otmena", true))
    {
        gCourierStage[playerid] = 0;
        DisablePlayerCheckpoint(playerid);
        SendClientMessage(playerid, COLOR_MUTED, "Delivery cancelled.");
        return 1;
    }
    if (strfind(cmdtext, "/me ", true) == 0 && strlen(cmdtext) > 4)
    {
        new line[190];
        format(line, sizeof(line), "* %s %s", gNicknames[playerid], cmdtext[4]);
        SendLocalMessage(playerid, COLOR_RP, line, 25.0);
        return 1;
    }
    if (strfind(cmdtext, "/do ", true) == 0 && strlen(cmdtext) > 4)
    {
        new line[190];
        format(line, sizeof(line), "* %s (( %s ))", cmdtext[4], gNicknames[playerid]);
        SendLocalMessage(playerid, COLOR_RP, line, 25.0);
        return 1;
    }
    if (strfind(cmdtext, "/b ", true) == 0 && strlen(cmdtext) > 3)
    {
        new line[190];
        format(line, sizeof(line), "(( %s: %s ))", gNicknames[playerid], cmdtext[3]);
        SendLocalMessage(playerid, COLOR_MUTED, line, 25.0);
        return 1;
    }
    return 0;
}

public OnPlayerEnterCheckpoint(playerid)
{
    if (!gAuthenticated[playerid]) return 1;
    if (GetPlayerInterior(playerid) != 0 || GetPlayerVirtualWorld(playerid) != 0) return 1;

    if (gCourierStage[playerid] == 1)
    {
        if (!IsPlayerInRangeOfPoint(playerid, 6.0,
            COURIER_PICKUP_X, COURIER_PICKUP_Y, COURIER_PICKUP_Z)) return 1;

        gCourierStage[playerid] = 2;
        SetPlayerCheckpoint(playerid, COURIER_DROP_X, COURIER_DROP_Y, COURIER_DROP_Z, 3.5);
        SendClientMessage(playerid, COLOR_PINK, "Parcel collected. Deliver it to the next checkpoint.");
    }
    else if (gCourierStage[playerid] == 2)
    {
        if (!IsPlayerInRangeOfPoint(playerid, 6.0,
            COURIER_DROP_X, COURIER_DROP_Y, COURIER_DROP_Z)) return 1;
        if (!CompleteCourierDelivery(playerid))
        {
            SendClientMessage(playerid, COLOR_ERROR, "Could not save delivery; retry later.");
            return 1;
        }
        gCourierStage[playerid] = 0;
        DisablePlayerCheckpoint(playerid);
        SaveCharacter(playerid);
        SendClientMessage(playerid, COLOR_PINK, "Delivery complete! Earned $250.");
    }
    return 1;
}
