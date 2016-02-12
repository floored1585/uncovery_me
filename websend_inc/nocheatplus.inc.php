<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

global $UMC_SETTING;
$UMC_SETTING['nocheatplus']['logfile'] = '/home/minecraft/server/bukkit/plugins/NoCheatPlus/nocheatplus.log';

function umc_nocheatplus_web() {
    $drop_sql = 'SELECT count(log_id) as counter, `action`
        FROM minecraft_log.nocheatplus
        GROUP BY `action`';
    $A = umc_mysql_fetch_all($drop_sql);
    $drop_data = array();
    foreach ($A as $row) {
        $drop_data[$row['action']] = ucwords($row['action']) . " (" . $row['counter'] . ")";
    }

    $post_action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
    if (is_null($post_action)) {
        $action = 'passable';
    } else {
        $action = $post_action;
    }

    $out = "<form action=\"\" method=\"post\">\n";
    $out .= umc_web_dropdown($drop_data, "action", $action, true);

    $sql_action = umc_mysql_real_escape_string($action);
    $sql = "SELECT count(log_id) AS hit_count, DATE_FORMAT(`date`,'%Y-%u') AS date, sum(level)/count(log_id) as average
        FROM minecraft_log.nocheatplus
        WHERE action=$sql_action
        GROUP BY `action`, DATE_FORMAT(`date`,'%Y-%u')
        ORDER BY `date` ASC";
    $D = umc_mysql_fetch_all($sql);

    $data_arr = array();
    foreach ($D as $d) {
        $data_arr[$d['date']]["hitcount"] = $d['hit_count'];
        $data_arr[$d['date']]["average"] = round($d['average']);
    }

    $out .= umc_web_javachart($data_arr, 'Weeks', 'none', array('hitcount' => 'left', 'average' => 'right'));
    $out .= "</form>";
    return $out;
}


function umc_nocheatplus_logimport() {
    global $UMC_SETTING;
    $file_path = $UMC_SETTING['nocheatplus']['logfile'];

    $regex = '/(^.{0,17}) \[INFO\] ([a-zA-Z_0-9]*) failed ([a-zA-Z_0-9]*):(.*)VL (\d*).$/';

    $invalid_str = array(
        '[NoCheatPlus]',
        'settings could have changed',
        'Configuration reloaded',
        'Logger started',
        'Logging system initialized',
        'Version information',
        '# Server #',
        '-Spigot-',
        'runs the command',
    );
    $required_str = '[INFO]';
    $line_count = 0;
    foreach (new SplFileObject($file_path) as $line) {
        $line_count ++;
        if (!strpos($line, $required_str)) {
            continue;
        }
        $inval_line = false;
        foreach ($invalid_str as $check) {
            if (strpos($line, $check)) {
                $inval_line = true;
            }
        }
        if ($inval_line) {
            continue;
        }
        $M = false;
        preg_match($regex, $line, $M);
        /*
        $M ⇒
            0 ⇒ "13.10.10 09:59:46 [INFO] miner22122 failed SurvivalFly: tried to move from -294.43, 65.17, -110.90 to -300.08, 64.00, -110.37 over a distance of 5.79 block(s). VL 472."
            1 ⇒ "13.10.10 09:59:46"
            2 ⇒ "miner22122"
            3 ⇒ "SurvivalFly"
            4 ⇒ " tried to move from -294.43, 65.17, -110.90 to -300.08, 64.00, -110.37 over a distance of 5.79 block(s). "
            5 ⇒ "472"
         */
        if (count($M) < 6) {
            XMPP_ERROR_trace("Matches for $line:", $M);
            XMPP_ERROR_trigger("line $line_count not recognized: $line");
            break;
        }
        $date = umc_mysql_real_escape_string(trim($M[1]));
        $uuid = umc_uuid_getone(strtolower(trim($M[2])), 'uuid', false);
        if (!$uuid) {
            continue;
        }
        $uuid_sql = umc_mysql_real_escape_string($uuid);
        $action = umc_mysql_real_escape_string(strtolower(trim($M[3])));
        $text = umc_mysql_real_escape_string(trim($M[4]));
        $vl = umc_mysql_real_escape_string(trim($M[5]));
        // $sql_check = "SELECT count(log_id) as counter FROM minecraft_log.nocheatplus WHERE `date`=$date AND username=$username AND action=$action AND level=$vl;";
        // $C = umc_mysql_fetch_all($sql_check);
        // if ($C[0]['counter'] < 1){
            $sql = "INSERT IGNORE INTO minecraft_log.nocheatplus(`date`, `uuid`, `action`, `level`, `text`)
                VALUES
                ($date,$uuid_sql,$action,$vl,$text)";
            umc_mysql_execute_query($sql);
        // }
    }
}