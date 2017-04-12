<?php

header('Content-type: text/javascript');
require_once '_includes/init.php';

$callback = (isset($_GET['_callback']) ? $_GET['_callback'] : false);
$players = (isset($_GET['_players']) ? $_GET['_players'] : false);
$active = (isset($_GET['_active']) ? $_GET['_active'] : false);

$db = CnCNet::$rendb;

if (!$db) die('false');

$sel = $db->select()->from('servers', array('id' => 'id', 'country' => 'country', 'countrycode' => 'countrycode', 'ip' => 'ip'))
                    ->order(new Zend_Db_Expr('s_hostname.value COLLATE NOCASE ASC'));

$keys = array('hostport', 'numplayers', 'maxplayers', 'password', 'mapname', 'hostname');

foreach ($keys as $k)
    $sel->join(array('s_' . str_replace(' ', '_', $k) => 'servers_info'), sprintf("s_%s.server_id = servers.id AND s_%s.key = %s", str_replace(' ', '_', $k), str_replace(' ', '_', $k), $db->quote($k)), array($k => 'value'));

foreach ($_GET as $k => $v) {
    if ($k[0] == '_') continue;
    if (strlen($v) == 0) {
        $sel->joinLeft(array('filter_' . $k => 'servers_info'), $db->quoteIdentifier('filter_' . $k) . '.server_id = servers.id AND ' . $db->quoteInto($db->quoteIdentifier('filter_' . $k) . '.key LIKE ?', str_replace('_', ' ', $k)), array(str_replace('_', ' ', $k) => 'value'));
    } else {
        $sel->join(array('filter_' . $k => 'servers_info'), $db->quoteIdentifier('filter_' . $k) . '.server_id = servers.id AND ' . $db->quoteInto($db->quoteIdentifier('filter_' . $k) . '.key LIKE ?', str_replace('_', ' ', $k)) . ' AND ' . $db->quoteInto($db->quoteIdentifier('filter_' . $k) . '.value = ?', $v), array());
    }   
}

if ((int)$active > 0) {
    $sel->where('s_numplayers.value > 0');
}

$sel->where("queried > datetime('now', '-2 minute', 'utc')");

$servers = $db->query($sel)->fetchAll();

foreach ($servers as &$server) {
    $id = $server['id'];
    unset($server['id']);

    if ($players) {
        $server['players'] = $db->query($db->select()->from('servers_players', array('name', 'score', 'kills', 'deaths', 'time', 'ping', 'team'))->where('server_id = ?', $id)->order('score DESC'))->fetchAll();
    }   
}

if ($callback) echo $callback . '(';
echo json_encode($servers);
if ($callback) echo ');';