<?php
/*
 * Copyright (c) 2012 Toni Spets <toni.spets@iki.fi>
 * 
 * Permission to use, copy, modify, and distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
 */

// control burst amount per select loop
$burst = 50;

set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__) . '/include');
set_time_limit(30);

require_once 'Zend/Db.php';
require_once 'GeoIP.php';
date_default_timezone_set('UTC');

$db_path = dirname(__FILE__) . '/db/servers.sqlite3';
$db = Zend_Db::factory('Pdo_Sqlite', array('dbname' => $db_path, 'driver_options' => array(PDO::ATTR_TIMEOUT => 30)));
$db->query('PRAGMA foreign_keys = ON');

$last_full_update = $db->query($db->select()->from('config', 'value')->where($db->quoteIdentifier('key') . ' = ?', 'list_updated'))->fetchColumn();

$now = date('Y-m-d H:i:s');

// update server list from master if needed
if (true || strlen($last_full_update) == 0 || $last_full_update < date('Y-m-d H:i:s', time() - 300)) {

    $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
    if (!socket_connect($socket, 'renmaster.cncnet.org', 28900)) {
        echo "master server unavailable, skipping\n";
        goto query;
    }

    $ret = socket_read($socket, 1024);

    if ($ret != '\\basic\\\\secure\\IGNORE') {
        echo "invalid master handshake (sort of)\n";
        goto query;
    }

    socket_write($socket, '\\gamename\\ccrenegade\\enctype\\0');

    $ret = '';
    while ($buf = socket_read($socket, 1024)) {
        $ret .= $buf;
    }

    socket_close($socket);

    $geoip = geoip_open(dirname(__FILE__) . '/db/GeoIP.dat', GEOIP_STANDARD);
    $servers = 0;

    $ret = str_replace('\\final\\', '', $ret);
    $db->beginTransaction();

    for ($i = 0; $i < strlen($ret) / 6; $i++) {
        $t = unpack('Ci1/Ci2/Ci3/Ci4/ni5', substr($ret, $i * 6, 6));
        $ip = sprintf('%s.%s.%s.%s', $t['i1'], $t['i2'], $t['i3'], $t['i4']);
        $port = $t['i5'];

        echo "server at {$ip}:{$port}\n";

        if (strlen($ip) == 0 || strlen($port) == 0)
            continue;

        $servers++;

        try {
            $db->insert('servers', array('country' => geoip_country_code_by_addr($geoip, $ip), 'ip' => $ip, 'port' => $port, 'updated' => $now));
        } catch (Exception $e) {
            $db->update('servers', array('updated' => $now), $db->quoteInto('ip = ?', $ip) . ' AND ' . $db->quoteInto('port = ?', $port));
        }
    }

    // remove dropped servers
    $db->delete('servers', $db->quoteInto('updated < ?', $now));

    try {
        $db->insert('config', array('key' => 'list_updated', 'value' => $now));
    } catch (Exception $e) {
        $db->update('config', array('value' => $now), $db->quoteInto($db->quoteIdentifier('key') . ' = ?', 'list_updated'));
    }

    $start = microtime(true);
    if ($servers > 0) {
        $db->commit();
    } else {
        $db->rollBack();
    }
    $end = microtime(true);

    geoip_close($geoip);

    echo 'Server list commit took ' . ($end - $start) . ' seconds.' . "\n";
}

query:

$stmt = $db->query($db->select()->from('servers'));

// hashmap the server list for fast reply assign
$all_servers = array();
foreach ($stmt->fetchAll() as $server) {
    $all_servers[$server['ip'] . ':' . $server['port']] = $server;
}

echo "total servers: " . count($all_servers) . "\n";

$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
$query = '\\status\\';
$query_len = strlen($query);
$reply = '';

if ($socket === false) {
    echo "No socket :-(\n";
    return;
}

$in_servers = $all_servers;

$db->beginTransaction();

while (count($in_servers) > 0) {
    $servers = array_splice($in_servers, 0, $burst);

    echo "servers left: " . count($in_servers) . "\n";
    echo "servers in burst: " . count($servers) . "\n";

    $out_queue = array();
    $tmp = array();
    foreach ($servers as $server) {
        $out_queue[] = $server;
        $tmp[$server['ip'] . ':' . $server['port']] = array();
    }

    $in_queue = array();

    $start = microtime(true);
    $end = $start;

    while ($end - $start < 1.5) {
        $end = microtime(true);

        $r = array($socket);
        $w = count($out_queue) > 0 ? array($socket) : array();
        $e = array();
        $ready = socket_select($r, $w, $e, 1.5 - ($end - $start));

        if ($ready === false)
            break;

        if (count($w) > 0) {
            $server = array_pop($out_queue);
            socket_sendto($socket, $query, $query_len, 0, $server['ip'], $server['port']);
        }

        if (count($r) > 0) {
            $buf = '';
            if (socket_recvfrom($socket, $buf, 4096, 0, $ip, $port) > 0) {
                $key = $ip . ':' . $port;
                $buf = rtrim($buf, "\0");
                if (isset($tmp[$key])) {
                    echo "got $buf\n";
                    $tmp[$key][] = $buf;

                    if (strncmp('\\final\\', $buf, 7) == 0) {
                        $in_queue[$key] = $tmp[$key];
                        unset($tmp[$key]);
                    }
                } else {
                    echo "rogue packet from $ip:$port\n";
                }
            }
        }

        if (count($in_queue) == count($servers))
            break;
    }

    foreach ($in_queue as $key => $bufs) {
        $server = $all_servers[$key];

        // XXX: buffer receive order is not important apparently
        $data = array();
        foreach ($bufs as $buf) {
            preg_match_all('/\\\\([^\\\\]+)\\\\([^\\\\]+)/', $buf, $m);
            foreach ($m[1] as $i => $k) {
                if ($k == 'queryid') continue;
                $data[$k] = $m[2][$i];
            }
        }

        $db->delete('servers_info', $db->quoteInto('server_id = ?', $server['id']));
        $db->delete('servers_players', $db->quoteInto('server_id = ?', $server['id']));

        $players = array();
        for ($i = 0; $i < $data['numplayers'] + 2; $i++) {
            if (isset($data['player_' . $i])) {
                $player = array(
                    'server_id' => $server['id'],
                    'name'      => $data['player_' . $i],
                    'score'     => (int)$data['score_' . $i],
                    'kills'     => (int)$data['kills_' . $i],
                    'deaths'    => (int)$data['deaths_' . $i],
                    'time'      => $data['time_' . $i],
                    'ping'      => (int)$data['ping_' . $i],
                    'team'      => $data['team_' . $i],
                );

                unset($data['player_' . $i]);
                unset($data['score_' . $i]);
                unset($data['kills_' . $i]);
                unset($data['deaths_' . $i]);
                unset($data['time_' . $i]);
                unset($data['ping_' . $i]);
                unset($data['team_' . $i]);

                $db->insert('servers_players', $player);
            }
        }

        foreach ($data as $k => $v)
            $db->insert('servers_info', array('server_id' => $server['id'], 'key' => $k, 'value' => $v));

        $db->update('servers', array('queried' => $now), $db->quoteInto('id = ?', $server['id']));
    }

    print count($in_queue) . ' / ' . count($servers) . " servers replied in " . ($end - $start) . " seconds.\n";
}

$db->commit();
