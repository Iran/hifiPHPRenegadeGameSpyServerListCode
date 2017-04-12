PRAGMA foreign_keys = ON;

CREATE TABLE "config" (
    "key"       TEXT NOT NULL,
    "value"     TEXT NOT NULL
);

CREATE UNIQUE INDEX "config_key" ON "config"("key");

CREATE TABLE "servers" (
    "id"        INTEGER PRIMARY KEY,
    "country"   TEXT DEFAULT '',
    "ip"        TEXT NOT NULL,
    "port"      INTEGER NOT NULL,

    "updated"   DATETIME DEFAULT '0000-00-00 00:00:00', -- when server was updated from master
    "queried"   DATETIME DEFAULT '0000-00-00 00:00:00'  -- when server was last queried (or tried)
);

CREATE UNIQUE INDEX "servers_ip_port" ON "servers"("ip", "port");
CREATE INDEX "servers_country" on "servers"("country");

CREATE TABLE "servers_info" (
    "server_id" INTEGER REFERENCES "servers"("id") ON DELETE CASCADE ON UPDATE RESTRICT,
    "key"       TEXT NOT NULL,
    "value"     TEXT NOT NULL
);

CREATE INDEX "servers_info_server_id_key" ON "servers_info"("server_id", "key");
CREATE INDEX "servers_info_key_value" ON "servers_info"("key", "value");
CREATE INDEX "servers_info_server_id_key_value" ON "servers_info"("server_id", "key", "value");

CREATE TABLE "servers_players" (
    "server_id" INTEGER REFERENCES "servers"("id") ON DELETE CASCADE ON UPDATE RESTRICT,
    "name"      TEXT NOT NULL,
    "score"     INTEGER NOT NULL,
    "kills"     INTEGER NOT NULL,
    "deaths"    INTEGER NOT NULL,
    "time"      TEXT NOT NULL,
    "ping"      INTEGER NOT NULL,
    "team"      TEXT NOT NULL
);

CREATE INDEX "servers_players_server_id" ON "servers_players"("server_id");
CREATE INDEX "servers_players_server_id_name" ON "servers_players"("server_id", "name");
