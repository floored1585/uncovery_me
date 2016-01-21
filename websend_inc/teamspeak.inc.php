<?php
/*
 * This file is part of Uncovery Minecraft.
 * Copyright (C) 2015 uncovery.me
 *
 * Uncovery Minecraft is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of  MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program.  If not, see <http://www.gnu.org/licenses/>.
 */

/*
 * This interfaces with a teamspeak server an allows users to authorize themselves
 * in teamspeak to get user rights. This is made to prevent non-game users
 * or banned users from spamming the teamspeak server.
 */
global $UMC_SETTING, $WS_INIT, $UMC_TEAMSPEAK;

$WS_INIT['teamspeak'] = array(  // the name of the plugin
    'disabled' => false,
    'events' => false,
    'default' => array(
        'help' => array(
            'title' => 'Teamspeak',  // give it a friendly title
            'short' => 'An interface to various Teamspeak-related features.',  // a short description
            'long' => "Allows you to give yourself teamspeak Settler level, list teamspeak users etc.", // a long add-on to the short  description
            ),
    ),
    'auth' => array( // this is the base command if there are no other commands
        'help' => array(
            'short' => 'Authorize your teamspeak client',
            'long' => "This will authorize your teamspeak client to get talking rights. Find your Teamspeak Unique ID at Settings -> Identities",
        ),
        'function' => 'umc_ts_authorize',
        'security' => array(
            'level'=>'Settler',
        ),
    ),
    'list' => array( // this is the base command if there are no other commands
        'help' => array(
            'short' => 'List all users on the Teamspeak server',
            'long' => "This will list all users that are currently logged in on the teamspeak server",
        ),
        'function' => 'umc_ts_listusers',
        'security' => array(
            'level'=>'Settler',
        ),
    ),
);

$UMC_TEAMSPEAK = array(
    'server' => false,
    'user_groups' => array(
        6 => array('Owner'),
        10 => array('Master','MasterDonator','MasterDonatorPlus',
            'Elder','ElderDonator','ElderDonatorPlus'),
        7 => array('Settler','SettlerDonator','SettlerDonatorPlus',
            'Citizen','CitizenDonator','CitizenDonatorPlus',
            'Architect','ArchitectDonator','ArchitectDonatorPlus',
            'Designer','DesignerDonator','DesignerDonatorPlus'),
    ),
    'ts_groups' => array(
        6 => 'TS Admin', 10 => 'TS Moderator', 7 => 'TS Settler', 8 => 'TS Guest'
    ),
);

function umc_ts_connect() {
    global $UMC_TEAMSPEAK;
    $query_string = file_get_contents("/home/includes/certificates/teamspeak_query.txt");
    require_once('/home/uncovery/teamspeak_php/libraries/TeamSpeak3/TeamSpeak3.php');

    if (!$UMC_TEAMSPEAK['server']) {
        $UMC_TEAMSPEAK['server'] = TeamSpeak3::factory($query_string);
    }
}

function umc_ts_listusers() {
    global $UMC_TEAMSPEAK;
    umc_ts_connect();
    $users = array();
    foreach ($UMC_TEAMSPEAK['server']->clientList() as $ts_Client) {
        $username = $ts_Client["client_nickname"];
        if (strpos($username, 'mc_bot') === false) {
            $users[] = $ts_Client["client_nickname"];
        }
    }
    $count = count($users);
    umc_header("Teamspeak users: $count");
    if ($count > 0) {
        umc_echo(implode(", ", $users));
    } else {
        umc_echo("Nobody online...");
    }
    umc_footer();
}

function umc_ts_authorize() {
    global $UMC_USER, $UMC_TEAMSPEAK;
    umc_ts_connect();

    // get client by name
    $uuid = $UMC_USER['uuid'];
    $userlevel = $UMC_USER['userlevel'];
    $username = $UMC_USER['username'];

    // get required servergroup
    foreach ($UMC_TEAMSPEAK['user_groups'] as $g_id => $usergroups) {
        if (in_array($userlevel, $usergroups)) {
            $target_group = $g_id;
            break;
        }
    }

    // first, we see if there is a current user logged in
    $ts_Client = false;
    umc_header();

    // first, we clean out old clients that are registered with minecraft
    umc_ts_clear_rights($uuid, true);

    // then, we try to find a new user on the TS server to give rights to
    umc_echo("Your TS level is " . $UMC_TEAMSPEAK['ts_groups'][$target_group]);
    umc_echo("Looking for user $username in TS...");
    $found = 0;
    foreach ($UMC_TEAMSPEAK['server']->clientList() as $ts_Client) {
        if ($ts_Client["client_nickname"] == $username) {
            $found++;
        }
    }

    if ($found == 0) {
        umc_echo("You need to logon to Teamspeak with the EXACT same username (\"$username\")");
        umc_echo("Once you did that, please try again");
        umc_footer();
        return false;
    } else if ($found > 1) {
        umc_echo("There are 2 users with the same username (\"$username\") online.");
        umc_echo("Process halted. Make sure you are the only one with the correct username");
        umc_echo("If there is someone else hogging your username, please send in a /ticket");
        umc_footer();
        return false;
    }

    // we have a user
    umc_echo("Found TS user " . $ts_Client["client_nickname"]);
    $ts_dbid = $ts_Client["client_database_id"];
    // remove all groups
    $servergroups = array_keys($UMC_TEAMSPEAK['server']->clientGetServerGroupsByDbid($ts_dbid));
    foreach ($servergroups as $sgid) {
        umc_echo($ts_Client["client_nickname"] . " is member of group " . $UMC_TEAMSPEAK['ts_groups'][$sgid]);
        if ($sgid != $target_group && $sgid != 8) {
            umc_echo("Removing usergroup $sgid...");
            $UMC_TEAMSPEAK['server']->serverGroupClientDel($sgid, $ts_dbid); // remove user from group
        } else if ($sgid == $target_group) {
            umc_echo("Not removing usergroup $sgid...");
            $target_group = false;
        }
    }
    // add the proper group
    if ($target_group) { // add target group of required
        umc_echo("Adding you to group " . $UMC_TEAMSPEAK['ts_groups'][$target_group]);
        $ts_Client->addServerGroup($target_group);
    }

    // get UUID
    $target_ts_uuid = $ts_Client["client_unique_identifier"];
    $ts_uuid = umc_mysql_real_escape_string($target_ts_uuid);
    $ins_sql = "UPDATE minecraft_srvr.UUID SET ts_uuid=$ts_uuid WHERE UUID='$uuid';";
    umc_mysql_query($ins_sql, true);
    umc_echo("Adding TS ID $ts_uuid to database");
    umc_footer("Done!");
}

/*

PHP Fatal error:  Uncaught exception 'TeamSpeak3_Adapter_ServerQuery_Exception' with message 'database empty result set' in /home/includes/teamspeak_php/libraries/TeamSpeak3/Adapter/ServerQuery/Reply.php:319
 * Stack trace:
 * #0 /home/includes/teamspeak_php/libraries/TeamSpeak3/Adapter/ServerQuery/Reply.php(91): TeamSpeak3_Adapter_ServerQuery_Reply->fetchError(Object(TeamSpeak3_Helper_String))
 * #1 /home/includes/teamspeak_php/libraries/TeamSpeak3/Adapter/ServerQuery.php(141): TeamSpeak3_Adapter_ServerQuery_Reply->__construct(Array, 'clientdbfind pa...', Object(TeamSpeak3_Node_Host), true)
 * #2 /home/includes/teamspeak_php/libraries/TeamSpeak3/Node/Abstract.php(73): TeamSpeak3_Adapter_ServerQuery->request('clientdbfind pa...', true)
 * #3 /home/includes/teamspeak_php/libraries/TeamSpeak3/Node/Server.php(90): TeamSpeak3_Node_Abstract->request('clientdbfind pa...', true)
 * #4 /home/includes/teamspeak_php/libraries/TeamSpeak3/Node/Abstract.php(97): TeamSpeak3_Node_Server->request('clientdbfind pa...')
 * #5 /home/includes/teamspeak_php/libraries/ in /home/includes/teamspeak_php/libraries/TeamSpeak3/Adapter/ServerQuery/Reply.php on line 319

*/
/**
 * Reset TS user rights to "Guest" for a specific user
 * This can be done even if the user is not online
 *
 * @param string $uuid
 * @param boolean $echo
 * @return boolean
 */
function umc_ts_clear_rights($uuid, $echo = false) {
    XMPP_ERROR_trace(__FUNCTION__, func_get_args());
    umc_echo("Trying to remove old permissions:");
    require_once('/home/includes/teamspeak_php/libraries/TeamSpeak3/TeamSpeak3.php');
    global $UMC_TEAMSPEAK;



    // find out the TS id the user has been using from the database
    $check_sql = "SELECT ts_uuid FROM minecraft_srvr.UUID WHERE UUID='$uuid';";
    $D = umc_mysql_fetch_all($check_sql);
    if ($D[0]['ts_uuid'] == '') {
        if ($echo) {
            umc_echo("Old Client: No previous TS account detected.");
        }
        return false;
    } else {
        umc_echo("Found old permissions.");
        $ts_uuid = $D[0]['ts_uuid'];
    }

    umc_echo("Connecting to TS server.");
    if (!$UMC_TEAMSPEAK['server']) {
        $UMC_TEAMSPEAK['server'] = TeamSpeak3::factory("serverquery://queryclient:Uo67dWu3@74.208.45.80:10011/?server_port=9987");
    }

    // find the TS user by that TS UUID
    umc_echo("Searching for you on the TS server.");
    $ts_Clients_match = $UMC_TEAMSPEAK['server']->clientFindDb($ts_uuid, true);
    if (count($ts_Clients_match) > 0) {
        umc_echo("Found user entries on TS server");
        $client_dbid = $ts_Clients_match[0];
        // enumerate all the groups the user is part of
        $servergroups = array_keys($UMC_TEAMSPEAK['server']->clientGetServerGroupsByDbid($client_dbid));
        // remove all servergroups except 8 (Guest)
        umc_echo("Removing all old usergroups:");
        foreach ($servergroups as $sgid) {
            if ($sgid != 8) {
                $UMC_TEAMSPEAK['server']->serverGroupClientDel($sgid, $client_dbid);
                if ($echo) {
                    umc_echo("Old Client: Removing Group " . $UMC_TEAMSPEAK['ts_groups'][$sgid]);
                }
            }
        }
        // also remove TS UUID from DB
        $ins_sql = "UPDATE minecraft_srvr.UUID SET ts_uuid='' WHERE ts_uuid='$ts_uuid';";
        umc_mysql_query($ins_sql, true);
        return true;
    } else {
        if ($echo) {
            umc_echo("Old Client: Previous TS UUID was invalid, nothing to do");
        }
        return false;
    }
}

/**
 * Create a HTML version of the TS server status
 *
 * @global type $UMC_TEAMSPEAK
 * @return string
 */
function umc_ts_viewer() {
    // required CSS:
    /*
        div.ts3_viewer {
                width:190px;
        }

        .ts3_viewer span.suffix{
                float:right;
        }
     */


    global $UMC_TEAMSPEAK;
    require_once('/home/includes/teamspeak_php/libraries/TeamSpeak3/TeamSpeak3.php');

    if (!$UMC_TEAMSPEAK['server']) {
        $UMC_TEAMSPEAK['server'] = TeamSpeak3::factory("serverquery://queryclient:Uo67dWu3@74.208.45.80:10011/?server_port=9987");
    }

    // no line breaks here!
    $pattern = "<div id='%0' class='%1 %3' summary='%2'><span class='%4'>%5</span><span class='%6' title='%7'>%8 %9</span><span class='%10'>%11%12</span></div>\n";

    $ts_viewer = new TeamSpeak3_Viewer_Html(
        "admin/img/teamspeak/viewer/",
        "admin/img/teamspeak/flags/",
        "data:image",
        $pattern
    );

    $out = $UMC_TEAMSPEAK['server']->getViewer($ts_viewer);

    // process text line by line

    $out_lines = explode("\n", $out);
    $out_new = '';
    foreach ($out_lines as $line) {
        if (!strstr($line, 'corpus query')) { // filter out serverquery accounts
            $out_new .= $line . "\n";
        }
    }

    $out_new .= "<div style=\"text-align:right;\"><a href=\"http://uncovery.me/communication/teamspeak/\">Help / Info</a></div>";
    return $out_new;
}