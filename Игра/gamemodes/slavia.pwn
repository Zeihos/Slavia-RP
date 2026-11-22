#include <a_samp>
#include <samp_bcrypt>

// SLAVIA ROLEPLAY 0.5.0: a small, independently written open.mp gamemode.
// Passwords are never saved as plain text. SQLite lives in scriptfiles/.
// Production launch requires moderation, anti-cheat and deployment review.

#define SLAVIA_VERSION      "0.5.0"
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

#define ITEM_BANDAGE       (1)
#define ITEM_MEDKIT        (2)
#define ITEM_BANDAGE_PRICE (75)
#define ITEM_MEDKIT_PRICE  (300)
#define ITEM_MAX_STACK     (10)
#define ITEM_BANDAGE_HEAL  (15.0)
#define ITEM_MEDKIT_HEAL   (45.0)
#define SHOP_RADIUS        (35.0)

#define CAR_MODEL_ECONOMY (410)
#define CAR_MODEL_PREMIUM (426)
#define CAR_PRICE_ECONOMY (1800)
#define CAR_PRICE_PREMIUM (5500)
#define CAR_BUY_RADIUS    (45.0)
#define CAR_SPAWN_RADIUS  (35.0)
#define CAR_SPAWN_DELAY   (20)
#define CAR_LOT_X         (1466.0)
#define CAR_LOT_Y         (-1742.0)
#define CAR_LOT_Z         (13.2)
#define CAR_LOT_A         (90.0)
#define CAR_FUEL_MAX      (100)
#define CAR_FUEL_PRICE    (7)
#define CAR_FUEL_TICK_MS  (30000)

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

new gBandages[MAX_PLAYERS];
new gMedkits[MAX_PLAYERS];

// A character can own one personal vehicle in this early version.
new gOwnedCarModel[MAX_PLAYERS];
new gOwnedCarEntity[MAX_PLAYERS];
new gCarLastSpawn[MAX_PLAYERS];
new Float:gCarParkX[MAX_PLAYERS], Float:gCarParkY[MAX_PLAYERS];
new Float:gCarParkZ[MAX_PLAYERS], Float:gCarParkA[MAX_PLAYERS];
new gCarFuel[MAX_PLAYERS];
new bool:gCarLocked[MAX_PLAYERS];

forward OnSlaviaHashCreated(playerid, session);
forward OnSlaviaPasswordChecked(playerid, bool:valid, session);
forward OnSlaviaFuelTick();

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
    gBandages[playerid] = 0;
    gMedkits[playerid] = 0;
    gOwnedCarModel[playerid] = 0;
    gOwnedCarEntity[playerid] = INVALID_VEHICLE_ID;
    gCarLastSpawn[playerid] = 0;
    gCarParkX[playerid] = CAR_LOT_X;
    gCarParkY[playerid] = CAR_LOT_Y;
    gCarParkZ[playerid] = CAR_LOT_Z;
    gCarParkA[playerid] = CAR_LOT_A;
    gCarFuel[playerid] = CAR_FUEL_MAX;
    gCarLocked[playerid] = false;
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

// Starter inventory: two consumables, stored by account in SQLite.
stock bool:LoadSupplies(playerid)
{
    new query[240], DBResult:result;
    format(query, sizeof(query),
        "SELECT bandages, medkits FROM supplies WHERE nickname='%s' LIMIT 1",
        gNicknames[playerid]);
    result = db_query(gDatabase, query);
    if (!result) return false;

    gBandages[playerid] = 0;
    gMedkits[playerid] = 0;
    if (db_num_rows(result) > 0)
    {
        gBandages[playerid] = db_get_field_assoc_int(result, "bandages");
        gMedkits[playerid] = db_get_field_assoc_int(result, "medkits");
        if (gBandages[playerid] < 0) gBandages[playerid] = 0;
        if (gMedkits[playerid] < 0) gMedkits[playerid] = 0;
        if (gBandages[playerid] > ITEM_MAX_STACK) gBandages[playerid] = ITEM_MAX_STACK;
        if (gMedkits[playerid] > ITEM_MAX_STACK) gMedkits[playerid] = ITEM_MAX_STACK;
    }
    db_free_result(result);
    return true;
}

// A failed SQLite write leaves wallet and inventory unchanged in memory.
stock bool:CommitSupplyChange(playerid, bandages, medkits, balance)
{
    if (bandages < 0 || bandages > ITEM_MAX_STACK ||
        medkits < 0 || medkits > ITEM_MAX_STACK ||
        balance < 0 || balance > 100000000) return false;

    new query[300];
    if (!ExecuteSql("BEGIN IMMEDIATE TRANSACTION")) return false;
    if (balance != gCash[playerid])
    {
        format(query, sizeof(query),
            "UPDATE accounts SET cash=%d WHERE nickname='%s'",
            balance, gNicknames[playerid]);
        if (!ExecuteSql(query))
        {
            ExecuteSql("ROLLBACK");
            return false;
        }
    }

    format(query, sizeof(query),
        "INSERT OR REPLACE INTO supplies (nickname, bandages, medkits) VALUES ('%s', %d, %d)",
        gNicknames[playerid], bandages, medkits);
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

    if (balance != gCash[playerid]) GivePlayerMoney(playerid, balance - gCash[playerid]);
    gCash[playerid] = balance;
    gBandages[playerid] = bandages;
    gMedkits[playerid] = medkits;
    return true;
}

stock ShowInventory(playerid)
{
    new line[145];
    format(line, sizeof(line), "Inventory: Bandages %d/%d | Medkits %d/%d",
        gBandages[playerid], ITEM_MAX_STACK, gMedkits[playerid], ITEM_MAX_STACK);
    SendClientMessage(playerid, COLOR_PINK, line);
    SendClientMessage(playerid, COLOR_MUTED, "Use: /usebandage (+15 HP), /usemedkit (+45 HP).");
    return 1;
}

stock BuySupply(playerid, itemid)
{
    if (itemid != ITEM_BANDAGE && itemid != ITEM_MEDKIT) return 0;
    if (GetPlayerState(playerid) != PLAYER_STATE_ONFOOT ||
        GetPlayerInterior(playerid) != 0 || GetPlayerVirtualWorld(playerid) != 0 ||
        !IsPlayerInRangeOfPoint(playerid, SHOP_RADIUS, START_X, START_Y, START_Z))
    {
        SendClientMessage(playerid, COLOR_ERROR, "Buy supplies on foot near city hall (/shop).");
        return 1;
    }

    new cost = itemid == ITEM_BANDAGE ? ITEM_BANDAGE_PRICE : ITEM_MEDKIT_PRICE;
    new bandages = gBandages[playerid], medkits = gMedkits[playerid];
    if (gCash[playerid] < cost)
    {
        SendClientMessage(playerid, COLOR_ERROR, "Not enough money for this purchase.");
        return 1;
    }
    if (itemid == ITEM_BANDAGE && bandages >= ITEM_MAX_STACK ||
        itemid == ITEM_MEDKIT && medkits >= ITEM_MAX_STACK)
    {
        SendClientMessage(playerid, COLOR_ERROR, "Inventory stack is full (10 per item).");
        return 1;
    }

    if (itemid == ITEM_BANDAGE) bandages++;
    else medkits++;
    if (!CommitSupplyChange(playerid, bandages, medkits, gCash[playerid] - cost))
    {
        SendClientMessage(playerid, COLOR_ERROR, "Purchase failed. No money was deducted.");
        return 1;
    }
    SendClientMessage(playerid, COLOR_PINK,
        itemid == ITEM_BANDAGE ? "Bandage purchased for $75." : "Medkit purchased for $300.");
    return 1;
}

stock UseSupply(playerid, itemid)
{
    if (itemid != ITEM_BANDAGE && itemid != ITEM_MEDKIT) return 0;
    if (GetPlayerState(playerid) != PLAYER_STATE_ONFOOT)
    {
        SendClientMessage(playerid, COLOR_ERROR, "Use medical supplies while on foot.");
        return 1;
    }
    if (itemid == ITEM_BANDAGE && gBandages[playerid] < 1 ||
        itemid == ITEM_MEDKIT && gMedkits[playerid] < 1)
    {
        SendClientMessage(playerid, COLOR_ERROR, "Item missing. See /inventory and /shop.");
        return 1;
    }
    new Float:health;
    GetPlayerHealth(playerid, health);
    if (health <= 0.0 || health >= 100.0)
    {
        SendClientMessage(playerid, COLOR_MUTED, "You cannot heal right now.");
        return 1;
    }

    new bandages = gBandages[playerid], medkits = gMedkits[playerid];
    if (itemid == ITEM_BANDAGE) bandages--;
    else medkits--;
    if (!CommitSupplyChange(playerid, bandages, medkits, gCash[playerid]))
    {
        SendClientMessage(playerid, COLOR_ERROR, "Inventory could not be saved. Item not used.");
        return 1;
    }
    new Float:heal = itemid == ITEM_BANDAGE ? ITEM_BANDAGE_HEAL : ITEM_MEDKIT_HEAL;
    health += heal;
    if (health > 100.0) health = 100.0;
    SetPlayerHealth(playerid, health);
    SendClientMessage(playerid, COLOR_PINK,
        itemid == ITEM_BANDAGE ? "Bandage used (+15 HP)." : "Medkit used (+45 HP).");
    return 1;
}

// A separate table preserves v0.1-v0.3 accounts, supplies and courier data.
stock bool:LoadPersonalCar(playerid)
{
    new query[260], DBResult:result;
    format(query, sizeof(query),
        "SELECT model, x, y, z, a FROM owned_vehicles WHERE nickname='%s' LIMIT 1",
        gNicknames[playerid]);
    result = db_query(gDatabase, query);
    if (!result) return false;

    gOwnedCarModel[playerid] = 0;
    if (db_num_rows(result) > 0)
    {
        new model = db_get_field_assoc_int(result, "model");
        if (model != CAR_MODEL_ECONOMY && model != CAR_MODEL_PREMIUM)
        {
            db_free_result(result);
            print("[SLAVIA] Invalid saved personal vehicle model.");
            return false;
        }
        gOwnedCarModel[playerid] = model;
        gCarParkX[playerid] = db_get_field_assoc_float(result, "x");
        gCarParkY[playerid] = db_get_field_assoc_float(result, "y");
        gCarParkZ[playerid] = db_get_field_assoc_float(result, "z");
        gCarParkA[playerid] = db_get_field_assoc_float(result, "a");

        // Bad coordinates must not cause an unsafe vehicle spawn.
        if (gCarParkX[playerid] < -3000.0 || gCarParkX[playerid] > 3000.0 ||
            gCarParkY[playerid] < -3000.0 || gCarParkY[playerid] > 3000.0 ||
            gCarParkZ[playerid] < -100.0 || gCarParkZ[playerid] > 2000.0)
        {
            gCarParkX[playerid] = CAR_LOT_X;
            gCarParkY[playerid] = CAR_LOT_Y;
            gCarParkZ[playerid] = CAR_LOT_Z;
            gCarParkA[playerid] = CAR_LOT_A;
        }
    }
    db_free_result(result);
    return true;
}


stock bool:LoadVehicleState(playerid)
{
    gCarFuel[playerid] = CAR_FUEL_MAX;
    gCarLocked[playerid] = false;
    if (gOwnedCarModel[playerid] == 0) return true;

    new query[240], DBResult:result;
    format(query, sizeof(query),
        "SELECT fuel, locked FROM vehicle_state WHERE nickname='%s' LIMIT 1",
        gNicknames[playerid]);
    result = db_query(gDatabase, query);
    if (!result) return false;
    if (db_num_rows(result) > 0)
    {
        gCarFuel[playerid] = db_get_field_assoc_int(result, "fuel");
        gCarLocked[playerid] = db_get_field_assoc_int(result, "locked") != 0;
        if (gCarFuel[playerid] < 0) gCarFuel[playerid] = 0;
        if (gCarFuel[playerid] > CAR_FUEL_MAX) gCarFuel[playerid] = CAR_FUEL_MAX;
    }
    db_free_result(result);
    return true;
}

stock bool:SaveVehicleState(playerid, fuel, locked)
{
    if (!gAuthenticated[playerid] || gOwnedCarModel[playerid] == 0 ||
        fuel < 0 || fuel > CAR_FUEL_MAX || (locked != 0 && locked != 1)) return false;

    new query[260];
    format(query, sizeof(query),
        "INSERT OR REPLACE INTO vehicle_state (nickname, fuel, locked) VALUES ('%s', %d, %d)",
        gNicknames[playerid], fuel, locked);
    return ExecuteSql(query);
}

stock RefreshVehicleLocks(playerid)
{
    if (gOwnedCarEntity[playerid] == INVALID_VEHICLE_ID) return 0;
    for (new viewerid = 0; viewerid < MAX_PLAYERS; viewerid++)
    {
        if (!IsPlayerConnected(viewerid)) continue;
        SetVehicleParamsForPlayer(gOwnedCarEntity[playerid], viewerid, 0,
            (viewerid != playerid && gCarLocked[playerid]) ? 1 : 0);
    }
    return 1;
}

stock ToggleVehicleLock(playerid)
{
    if (gOwnedCarModel[playerid] == 0)
    {
        SendClientMessage(playerid, COLOR_ERROR, "You do not own a vehicle.");
        return 1;
    }
    new nextLock = gCarLocked[playerid] ? 0 : 1;
    if (!SaveVehicleState(playerid, gCarFuel[playerid], nextLock))
    {
        SendClientMessage(playerid, COLOR_ERROR, "Could not save vehicle lock.");
        return 1;
    }
    gCarLocked[playerid] = nextLock != 0;
    RefreshVehicleLocks(playerid);
    SendClientMessage(playerid, COLOR_PINK,
        gCarLocked[playerid] ? "Personal vehicle locked." : "Personal vehicle unlocked.");
    return 1;
}

stock RefuelPersonalCar(playerid)
{
    if (gOwnedCarModel[playerid] == 0)
    {
        SendClientMessage(playerid, COLOR_ERROR, "Buy a vehicle before refuelling.");
        return 1;
    }
    // Early service point: stored cars only, near city hall. Not a full gas station yet.
    if (gOwnedCarEntity[playerid] != INVALID_VEHICLE_ID ||
        GetPlayerState(playerid) != PLAYER_STATE_ONFOOT ||
        GetPlayerInterior(playerid) != 0 || GetPlayerVirtualWorld(playerid) != 0 ||
        !IsPlayerInRangeOfPoint(playerid, CAR_BUY_RADIUS, START_X, START_Y, START_Z))
    {
        SendClientMessage(playerid, COLOR_ERROR, "Store your car, then refuel on foot near city hall.");
        return 1;
    }
    new missing = CAR_FUEL_MAX - gCarFuel[playerid];
    if (missing <= 0)
    {
        SendClientMessage(playerid, COLOR_MUTED, "Tank is already full.");
        return 1;
    }
    new cost = missing * CAR_FUEL_PRICE;
    if (gCash[playerid] < cost)
    {
        new line[128];
        format(line, sizeof(line), "Refuel cost: $%d; not enough money.", cost);
        SendClientMessage(playerid, COLOR_ERROR, line);
        return 1;
    }
    new query[280], nextBalance = gCash[playerid] - cost;
    if (!ExecuteSql("BEGIN IMMEDIATE TRANSACTION")) return 1;
    format(query, sizeof(query),
        "UPDATE accounts SET cash=%d WHERE nickname='%s'",
        nextBalance, gNicknames[playerid]);
    if (!ExecuteSql(query))
    {
        ExecuteSql("ROLLBACK");
        SendClientMessage(playerid, COLOR_ERROR, "Refuel database error; no money charged.");
        return 1;
    }
    format(query, sizeof(query),
        "INSERT OR REPLACE INTO vehicle_state (nickname, fuel, locked) VALUES ('%s', %d, %d)",
        gNicknames[playerid], CAR_FUEL_MAX, gCarLocked[playerid] ? 1 : 0);
    if (!ExecuteSql(query) || !ExecuteSql("COMMIT"))
    {
        ExecuteSql("ROLLBACK");
        SendClientMessage(playerid, COLOR_ERROR, "Refuel could not be saved.");
        return 1;
    }
    gCash[playerid] = nextBalance;
    gCarFuel[playerid] = CAR_FUEL_MAX;
    GivePlayerMoney(playerid, -cost);
    new line[120];
    format(line, sizeof(line), "Tank refuelled: +%d fuel, paid $%d.", missing, cost);
    SendClientMessage(playerid, COLOR_PINK, line);
    return 1;
}

stock ShowVehicleStatus(playerid)
{
    if (gOwnedCarModel[playerid] == 0)
    {
        SendClientMessage(playerid, COLOR_ERROR, "No personal vehicle. Use /cars.");
        return 1;
    }
    new line[150];
    format(line, sizeof(line), "Vehicle: %s | Fuel: %d/%d | %s",
        gOwnedCarModel[playerid] == CAR_MODEL_ECONOMY ? "Manana" : "Premier",
        gCarFuel[playerid], CAR_FUEL_MAX,
        gCarLocked[playerid] ? "Locked" : "Unlocked");
    SendClientMessage(playerid, COLOR_PINK, line);
    SendClientMessage(playerid, COLOR_MUTED, "/vlock - toggle lock, /refuel - refill stored car at city hall.");
    return 1;
}

public OnSlaviaFuelTick()
{
    for (new playerid = 0; playerid < MAX_PLAYERS; playerid++)
    {
        if (!IsPlayerConnected(playerid) || !gAuthenticated[playerid] ||
            gOwnedCarEntity[playerid] == INVALID_VEHICLE_ID ||
            GetPlayerState(playerid) != PLAYER_STATE_DRIVER ||
            GetPlayerVehicleID(playerid) != gOwnedCarEntity[playerid]) continue;

        if (gCarFuel[playerid] == 0)
        {
            RemovePlayerFromVehicle(playerid);
            continue;
        }
        new Float:velX, Float:velY, Float:velZ;
        GetVehicleVelocity(gOwnedCarEntity[playerid], velX, velY, velZ);
        // No consumption while parked/idling; one unit per active 30-second tick.
        if (floatabs(velX) + floatabs(velY) < 0.04) continue;

        new remaining = gCarFuel[playerid] - 1;
        if (!SaveVehicleState(playerid, remaining, gCarLocked[playerid] ? 1 : 0))
        {
            SendClientMessage(playerid, COLOR_ERROR, "Cannot save fuel consumption.");
            continue;
        }
        gCarFuel[playerid] = remaining;
        if (remaining == 0)
        {
            SetVehicleVelocity(gOwnedCarEntity[playerid], 0.0, 0.0, 0.0);
            RemovePlayerFromVehicle(playerid);
            SendClientMessage(playerid, COLOR_ERROR, "Fuel exhausted. Store the car and refuel.");
        }
    }
    return 1;
}

stock BuyPersonalCar(playerid, model)
{
    if (model != CAR_MODEL_ECONOMY && model != CAR_MODEL_PREMIUM) return 0;
    if (gOwnedCarModel[playerid] != 0)
    {
        SendClientMessage(playerid, COLOR_ERROR, "Only one owned car per character in v0.5.");
        return 1;
    }
    if (GetPlayerState(playerid) != PLAYER_STATE_ONFOOT ||
        GetPlayerInterior(playerid) != 0 || GetPlayerVirtualWorld(playerid) != 0 ||
        !IsPlayerInRangeOfPoint(playerid, CAR_BUY_RADIUS, START_X, START_Y, START_Z))
    {
        SendClientMessage(playerid, COLOR_ERROR, "Buy a car on foot near city hall.");
        return 1;
    }

    new cost = model == CAR_MODEL_ECONOMY ? CAR_PRICE_ECONOMY : CAR_PRICE_PREMIUM;
    if (gCash[playerid] < cost)
    {
        SendClientMessage(playerid, COLOR_ERROR, "Not enough cash for this vehicle.");
        return 1;
    }

    // Give different connected players different temporary starter parking slots.
    new Float:x = CAR_LOT_X + float(playerid % 5) * 5.0;
    new Float:y = CAR_LOT_Y + float(playerid / 5) * 7.0;
    new Float:z = CAR_LOT_Z, Float:angle = CAR_LOT_A;
    new newBalance = gCash[playerid] - cost, query[350];

    if (!ExecuteSql("BEGIN IMMEDIATE TRANSACTION")) return 0;
    format(query, sizeof(query),
        "UPDATE accounts SET cash=%d WHERE nickname='%s'",
        newBalance, gNicknames[playerid]);
    if (!ExecuteSql(query))
    {
        ExecuteSql("ROLLBACK");
        SendClientMessage(playerid, COLOR_ERROR, "Car purchase failed. Money unchanged.");
        return 1;
    }
    format(query, sizeof(query),
        "INSERT INTO owned_vehicles (nickname, model, x, y, z, a) VALUES ('%s', %d, %f, %f, %f, %f)",
        gNicknames[playerid], model, x, y, z, angle);
    if (!ExecuteSql(query))
    {
        ExecuteSql("ROLLBACK");
        SendClientMessage(playerid, COLOR_ERROR, "Car purchase failed. Vehicle not issued.");
        return 1;
    }
    format(query, sizeof(query),
        "INSERT INTO vehicle_state (nickname, fuel, locked) VALUES ('%s', %d, 0)",
        gNicknames[playerid], CAR_FUEL_MAX);
    if (!ExecuteSql(query))
    {
        ExecuteSql("ROLLBACK");
        SendClientMessage(playerid, COLOR_ERROR, "Could not initialize vehicle state.");
        return 1;
    }
    if (!ExecuteSql("COMMIT"))
    {
        ExecuteSql("ROLLBACK");
        SendClientMessage(playerid, COLOR_ERROR, "Cannot confirm purchase. Check server logs.");
        return 1;
    }

    gOwnedCarModel[playerid] = model;
    gCarFuel[playerid] = CAR_FUEL_MAX;
    gCarLocked[playerid] = false;
    gCarParkX[playerid] = x;
    gCarParkY[playerid] = y;
    gCarParkZ[playerid] = z;
    gCarParkA[playerid] = angle;
    gCash[playerid] = newBalance;
    GivePlayerMoney(playerid, -cost);
    SendClientMessage(playerid, COLOR_PINK,
        model == CAR_MODEL_ECONOMY ? "Manana purchased. Use /vspawn near parking." :
                                     "Premier purchased. Use /vspawn near parking.");
    return 1;
}

stock SpawnPersonalCar(playerid)
{
    if (gOwnedCarModel[playerid] == 0)
    {
        SendClientMessage(playerid, COLOR_ERROR, "No owned car. See /cars.");
        return 1;
    }
    if (gOwnedCarEntity[playerid] != INVALID_VEHICLE_ID)
    {
        SendClientMessage(playerid, COLOR_MUTED, "Car already spawned. Use /vstore first.");
        return 1;
    }
    if (gCarFuel[playerid] <= 0)
    {
        SendClientMessage(playerid, COLOR_ERROR, "Tank is empty. Use /refuel near city hall.");
        return 1;
    }
    if (GetPlayerState(playerid) != PLAYER_STATE_ONFOOT ||
        GetPlayerInterior(playerid) != 0 || GetPlayerVirtualWorld(playerid) != 0 ||
        !IsPlayerInRangeOfPoint(playerid, CAR_SPAWN_RADIUS,
            gCarParkX[playerid], gCarParkY[playerid], gCarParkZ[playerid]))
    {
        SendClientMessage(playerid, COLOR_ERROR, "Go to your last parked position on foot.");
        return 1;
    }
    new remaining = gCarLastSpawn[playerid] + CAR_SPAWN_DELAY - gettime();
    if (gCarLastSpawn[playerid] != 0 && remaining > 0)
    {
        SendClientMessage(playerid, COLOR_ERROR, "Vehicle spawn cooldown: 20 seconds.");
        return 1;
    }

    new vehicleid = CreateVehicle(gOwnedCarModel[playerid],
        gCarParkX[playerid], gCarParkY[playerid], gCarParkZ[playerid],
        gCarParkA[playerid], 1, 1, -1);
    if (vehicleid == INVALID_VEHICLE_ID || vehicleid == 0)
    {
        SendClientMessage(playerid, COLOR_ERROR, "No free vehicle slot. Try later.");
        return 1;
    }
    gOwnedCarEntity[playerid] = vehicleid;
    gCarLastSpawn[playerid] = gettime();
    RefreshVehicleLocks(playerid);
    SendClientMessage(playerid, COLOR_PINK, "Personal car spawned. /vpark /vstore.");
    return 1;
}

stock ParkPersonalCar(playerid)
{
    if (gOwnedCarEntity[playerid] == INVALID_VEHICLE_ID ||
        GetPlayerState(playerid) != PLAYER_STATE_DRIVER ||
        GetPlayerVehicleID(playerid) != gOwnedCarEntity[playerid] ||
        GetPlayerInterior(playerid) != 0 || GetPlayerVirtualWorld(playerid) != 0)
    {
        SendClientMessage(playerid, COLOR_ERROR, "You must drive your own car outside to park it.");
        return 1;
    }
    new Float:x, Float:y, Float:z, Float:angle;
    GetVehiclePos(gOwnedCarEntity[playerid], x, y, z);
    GetVehicleZAngle(gOwnedCarEntity[playerid], angle);
    if (x < -3000.0 || x > 3000.0 || y < -3000.0 || y > 3000.0 ||
        z < -100.0 || z > 2000.0)
    {
        SendClientMessage(playerid, COLOR_ERROR, "Cannot save parking at this position.");
        return 1;
    }
    new query[320];
    format(query, sizeof(query),
        "UPDATE owned_vehicles SET x=%f, y=%f, z=%f, a=%f WHERE nickname='%s'",
        x, y, z, angle, gNicknames[playerid]);
    if (!ExecuteSql(query))
    {
        SendClientMessage(playerid, COLOR_ERROR, "Parking save failed. Location unchanged.");
        return 1;
    }
    gCarParkX[playerid] = x;
    gCarParkY[playerid] = y;
    gCarParkZ[playerid] = z;
    gCarParkA[playerid] = angle;
    SendClientMessage(playerid, COLOR_PINK, "Parking saved. Use /vstore before leaving.");
    return 1;
}

stock StorePersonalCar(playerid)
{
    if (gOwnedCarEntity[playerid] == INVALID_VEHICLE_ID)
    {
        SendClientMessage(playerid, COLOR_MUTED, "Your personal car is already stored.");
        return 1;
    }
    if (GetPlayerState(playerid) != PLAYER_STATE_ONFOOT ||
        !IsPlayerInRangeOfPoint(playerid, 20.0,
            gCarParkX[playerid], gCarParkY[playerid], gCarParkZ[playerid]))
    {
        SendClientMessage(playerid, COLOR_ERROR,
            "Exit the vehicle and stand near the saved parking location.");
        return 1;
    }
    DestroyVehicle(gOwnedCarEntity[playerid]);
    gOwnedCarEntity[playerid] = INVALID_VEHICLE_ID;
    SendClientMessage(playerid, COLOR_PINK, "Personal car stored.");
    return 1;
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
    if (!LoadCharacter(playerid) || !LoadCourierProgress(playerid) ||
        !LoadSupplies(playerid) || !LoadPersonalCar(playerid) ||
        !LoadVehicleState(playerid))
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
    SendClientMessage(playerid, COLOR_WHITE, "Commands: /help, /inventory, /cars, /jobs.");
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
    SetGameModeText("SLAVIA RP 0.5.0");
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
    if (!ExecuteSql("CREATE TABLE IF NOT EXISTS supplies (nickname TEXT PRIMARY KEY COLLATE NOCASE, bandages INTEGER NOT NULL DEFAULT 0, medkits INTEGER NOT NULL DEFAULT 0)"))
    {
        print("[SLAVIA] FATAL: cannot initialize supply inventory.");
        SendRconCommand("exit");
        return 0;
    }
    if (!ExecuteSql("CREATE TABLE IF NOT EXISTS owned_vehicles (nickname TEXT PRIMARY KEY COLLATE NOCASE, model INTEGER NOT NULL, x REAL NOT NULL, y REAL NOT NULL, z REAL NOT NULL, a REAL NOT NULL)"))
    {
        print("[SLAVIA] FATAL: cannot initialize personal vehicles.");
        SendRconCommand("exit");
        return 0;
    }
    if (!ExecuteSql("CREATE TABLE IF NOT EXISTS vehicle_state (nickname TEXT PRIMARY KEY COLLATE NOCASE, fuel INTEGER NOT NULL DEFAULT 100 CHECK(fuel BETWEEN 0 AND 100), locked INTEGER NOT NULL DEFAULT 0 CHECK(locked IN (0,1)))"))
    {
        print("[SLAVIA] FATAL: cannot initialize fuel and locks.");
        SendRconCommand("exit");
        return 0;
    }
    SetTimer("OnSlaviaFuelTick", CAR_FUEL_TICK_MS, true);
    print("[SLAVIA] Accounts, courier, inventory, vehicles, fuel and locks ready.");
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
    if (gOwnedCarEntity[playerid] != INVALID_VEHICLE_ID)
    {
        DestroyVehicle(gOwnedCarEntity[playerid]);
        gOwnedCarEntity[playerid] = INVALID_VEHICLE_ID;
    }
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
        SendClientMessage(playerid, COLOR_PINK, "Inventory: /inv, /shop, /buybandage, /buymedkit");
        SendClientMessage(playerid, COLOR_MUTED, "Medical: /usebandage, /usemedkit");
        SendClientMessage(playerid, COLOR_PINK, "Vehicles: /cars, /buycar 1, /buycar 2, /mycar");
        SendClientMessage(playerid, COLOR_MUTED, "Vehicle control: /vspawn, /vpark, /vstore");
        SendClientMessage(playerid, COLOR_MUTED, "Fuel & security: /vstatus, /vlock, /refuel");
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
    if (!strcmp(cmdtext, "/inv", true) || !strcmp(cmdtext, "/inventory", true))
    {
        return ShowInventory(playerid);
    }
    if (!strcmp(cmdtext, "/shop", true))
    {
        SendClientMessage(playerid, COLOR_PINK, "Medical supplies: bandage $75 | medkit $300.");
        SendClientMessage(playerid, COLOR_MUTED, "Buy on foot near city hall: /buybandage, /buymedkit.");
        return 1;
    }
    if (!strcmp(cmdtext, "/buybandage", true)) return BuySupply(playerid, ITEM_BANDAGE);
    if (!strcmp(cmdtext, "/buymedkit", true)) return BuySupply(playerid, ITEM_MEDKIT);
    if (!strcmp(cmdtext, "/usebandage", true)) return UseSupply(playerid, ITEM_BANDAGE);
    if (!strcmp(cmdtext, "/usemedkit", true)) return UseSupply(playerid, ITEM_MEDKIT);
    if (!strcmp(cmdtext, "/cars", true))
    {
        SendClientMessage(playerid, COLOR_PINK, "Vehicle shop at city hall (on foot):");
        SendClientMessage(playerid, COLOR_WHITE, "/buycar 1 - Manana ($1800); /buycar 2 - Premier ($5500)");
        SendClientMessage(playerid, COLOR_MUTED, "One vehicle per character. Buy, then /vspawn at parking.");
        return 1;
    }
    if (!strcmp(cmdtext, "/mycar", true))
    {
        if (gOwnedCarModel[playerid] == 0)
            SendClientMessage(playerid, COLOR_MUTED, "No owned vehicle. Use /cars.");
        else
            SendClientMessage(playerid, COLOR_PINK,
                gOwnedCarModel[playerid] == CAR_MODEL_ECONOMY ?
                "Owned vehicle: Manana | /vspawn, /vpark, /vstore." :
                "Owned vehicle: Premier | /vspawn, /vpark, /vstore.");
        return 1;
    }
    if (!strcmp(cmdtext, "/buycar 1", true)) return BuyPersonalCar(playerid, CAR_MODEL_ECONOMY);
    if (!strcmp(cmdtext, "/buycar 2", true)) return BuyPersonalCar(playerid, CAR_MODEL_PREMIUM);
    if (!strcmp(cmdtext, "/buycar", true))
    {
        SendClientMessage(playerid, COLOR_MUTED, "Usage: /buycar 1 or /buycar 2.");
        return 1;
    }
    if (!strcmp(cmdtext, "/vspawn", true)) return SpawnPersonalCar(playerid);
    if (!strcmp(cmdtext, "/vpark", true)) return ParkPersonalCar(playerid);
    if (!strcmp(cmdtext, "/vstore", true)) return StorePersonalCar(playerid);
    if (!strcmp(cmdtext, "/vstatus", true)) return ShowVehicleStatus(playerid);
    if (!strcmp(cmdtext, "/vlock", true)) return ToggleVehicleLock(playerid);
    if (!strcmp(cmdtext, "/refuel", true)) return RefuelPersonalCar(playerid);
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

public OnPlayerStateChange(playerid, newstate, oldstate)
{
    if (newstate != PLAYER_STATE_DRIVER && newstate != PLAYER_STATE_PASSENGER) return 1;
    new vehicleid = GetPlayerVehicleID(playerid);
    for (new ownerid = 0; ownerid < MAX_PLAYERS; ownerid++)
    {
        if (!IsPlayerConnected(ownerid) || !gAuthenticated[ownerid]) continue;
        if (gOwnedCarEntity[ownerid] != vehicleid) continue;

        if (ownerid != playerid &&
            (newstate == PLAYER_STATE_DRIVER || gCarLocked[ownerid]))
        {
            RemovePlayerFromVehicle(playerid);
            SendClientMessage(playerid, COLOR_ERROR, "You cannot enter this personal car.");
        }
        else if (ownerid == playerid && newstate == PLAYER_STATE_DRIVER &&
                 gCarFuel[ownerid] <= 0)
        {
            RemovePlayerFromVehicle(playerid);
            SendClientMessage(playerid, COLOR_ERROR, "Tank is empty. Refuel before driving.");
        }
        break;
    }
    return 1;
}

public OnVehicleStreamIn(vehicleid, forplayerid)
{
    for (new ownerid = 0; ownerid < MAX_PLAYERS; ownerid++)
    {
        if (!IsPlayerConnected(ownerid) || !gAuthenticated[ownerid]) continue;
        if (gOwnedCarEntity[ownerid] != vehicleid) continue;
        SetVehicleParamsForPlayer(vehicleid, forplayerid, 0,
            (ownerid != forplayerid && gCarLocked[ownerid]) ? 1 : 0);
        break;
    }
    return 1;
}

public OnVehicleDeath(vehicleid, killerid)
{
    for (new ownerid = 0; ownerid < MAX_PLAYERS; ownerid++)
    {
        if (!IsPlayerConnected(ownerid) || !gAuthenticated[ownerid]) continue;
        if (gOwnedCarEntity[ownerid] != vehicleid) continue;
        gOwnedCarEntity[ownerid] = INVALID_VEHICLE_ID;
        SendClientMessage(ownerid, COLOR_ERROR, "Your car was destroyed; return to parking and use /vspawn.");
        DestroyVehicle(vehicleid);
        break;
    }
    return 1;
}
