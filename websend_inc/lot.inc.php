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
 * This allows users to add and remove members to their lot. It also allows users
 * to warp Guests to their lot before the reserve it.
 */

global $UMC_SETTING, $WS_INIT;

$WS_INIT['lot'] = array(
    'disabled' => false,
    'events' => false,
    'default' => array(
        'help' => array(
            'title' => 'Lot Management',
            'short' => 'Add/remove members, en/disable snowfall on your lot',
            'long' => 'Allow others to build on your lot, en/disable snow accumulation, ice formation;',
            ),
    ),
    'add' => array(
        'help' => array(
            'short' => 'Add features to your lot;',
            'args' => '<lot> <member|snow|ice> [user] [user2]...',
            'long' => 'Add a member so thet they can build on your lot or add snow accumulation or ice forming. You can list several users, separated with spaces.;',
        ),
        'function' => 'umc_lot_addrem',
    ),
    'rem' => array(
        'help' => array(
            'short' => 'Remove features from  your lot;',
            'args' => '<lot> <member|snow|ice> [user] [user2]...',
            'long' => 'You can remove users or flags from your lot or remove snow accumulation or ice forming. You can list several users, separated with spaces.;',
        ),
        'function' => 'umc_lot_addrem',
    ),
    'give' => array(
        'help' => array(
            'short' => 'Remove all members and owners from a lot and give it to someone',
            'args' => '<lot> give [user]',
            'long' => '',
        ),
        'function' => 'umc_lot_addrem',
        'security' => array(
            'level'=>'Owner',
         ),
    ),
    'mod' => array(
        'help' => array(
            'short' => 'Add/Remove yourself from a flatlands lot for emergency fixes',
            'args' => '<lot> <add|rem>',
            'long' => '',
        ),
        'function' => 'umc_lot_mod',
        'security' => array(
            'level'=>'Elder',
            // 'level'=>'ElderDonator', 'level'=>'ElderDonatorPlus',
         ),
    ),
    'warp' => array(
        'help' => array(
            'short' => 'Teleport yourself to a lot - only usable by guests for the settler test',
            'args' => '<lot>',
            'long' => 'Teleport yourself to a lot - only usable by guests for the settler test',
        ),
        'function' => 'umc_lot_warp',
    ),
);

function umc_lot_mod() {
    global $UMC_USER;
    $player = $UMC_USER['username'];
    $args = $UMC_USER['args'];

    /// /lotmember lot world add target

    $addrem = $args[3];
    $lot = strtolower($args[2]);

    if (count($args) <= 2) {
        umc_echo("Too few arguments!");
        umc_show_help($args);
        return;
    }
    $world = 'flatlands';

    $user_id = umc_get_worldguard_id('user', strtolower($player));
    if (!$user_id) {
        umc_error("Your user id cannot be found!");
    }
    $player_group = umc_get_userlevel($player);

    $world_id = umc_get_worldguard_id('world', $world);
    if (!$world_id) {
        umc_error("The lot '$lot' cannot be found in any world!");
    }

    if (!umc_check_lot_exists($world_id, $lot)) {
        umc_error("There is no lot $lot in world $world;");
    }
    if ($player_group !== 'Owner' && $player_group !== 'Elder' && $player_group !== 'ElderDonator' && $player_group !== 'ElderDonatorPlus') {
        umc_error("You are not Elder or Owner, you are $player_group!");
    }

    if ($addrem == 'add') {
        $sql_ins = "INSERT INTO minecraft_worldguard.region_players (`region_id`, `world_id`, `user_id`, `Owner`)
            VALUES ('$lot', '$world_id', $user_id, 0);";
        umc_mysql_query($sql_ins, true);
        umc_echo("Added you to $lot in the $world!");
    } else if ($addrem == 'rem') {
        // check if target is there at all
        $sql = "SELECT * FROM minecraft_worldguard.region_players WHERE region_id='$lot' AND world_id=$world_id AND user_id=$user_id AND Owner=0 LIMIT 1;";
        $D = umc_mysql_fetch_all($sql);
        if (count($D) !== 1) {
            umc_error("It appears you are not a member of lot $lot in world $world!");
        }
        $sql_del = "DELETE FROM minecraft_worldguard.region_players WHERE region_id = '$lot' AND world_id = $world_id AND user_id = $user_id AND Owner=0;";
        umc_mysql_query($sql_del, true);
        umc_echo("Removed you from $lot in the $world!");
    } else {
        umc_error("You can only use [add] or [rem], not {$args[1]}!");
    }
    umc_ws_cmd('region load -w flatlands', 'asConsole');
    umc_log('lot', 'mod', "$player added himself to lot $lot to fix something");
    XMPP_ERROR_trigger("$player added himself to lot $lot to fix something");
}


function umc_lot_addrem() {
    global $UMC_USER;
    $player = $UMC_USER['username'];
    $args = $UMC_USER['args'];

    /// /lotmember lot world add target
    if ((count($args) <= 3)) {
        umc_echo("{red}Not enough arguments given");
        umc_show_help($args);
        return;
    }

    $addrem = $args[1];
    $lot = strtolower($args[2]);
    $action = $args[3];

    $worlds = array(
        'emp' => 'empire',
        'fla' => 'flatlands',
        'dar' => 'darklands',
        'aet' => 'aether',
        'kin' => 'kingdom',
        'dra' => 'draftlands',
        'blo' => 'skyblock',
        'con' => 'aether');

    $world_abr = substr($lot, 0, 3);
    if (!isset($worlds[$world_abr])) {
        umc_error("Your used an invalid lot name!");
    }
    $world = $worlds[$world_abr];

    if ($player == '@Console') {
        $player = 'uncovery';
    }

    $user_id = umc_get_worldguard_id('user', strtolower($player));
    if (!$user_id) {
        umc_error("Your user id cannot be found!");
    }
    $player_group = umc_get_userlevel($player);

    $world_id = umc_get_worldguard_id('world', $world);
    if (!$world_id) {
        umc_show_help($args);
        umc_error("The lot '$lot' cannot be found in any world!");
    }

    if (!umc_check_lot_exists($world_id, $lot)) {
        umc_show_help($args);
        umc_error("There is no lot $lot in world $world!");
    }

    if ($action == 'snow' || $action == 'ice') {
        // check if the user has DonatorPlus status.

        if ($player_group !== 'Owner') {
            if (!stristr($player_group, 'DonatorPlus')) {
                umc_error("You need to be DonatorPlus level to use the snow/ice features!;");
            }
            $owner_switch = 0;
            // check if player is Owner of lot
            $sql = "SELECT * FROM minecraft_worldguard.region_players WHERE region_id='$lot' AND world_id=$world_id AND user_id=$user_id and Owner=1;";
            $D = umc_mysql_fetch_all($sql);
            $num = count($D);
            if ($num != 1) {
                umc_error("It appears you $player ($user_id) are not Owner of lot $lot in world $world!");
            }
        }
        // get the current status of the flags
        if ($addrem == 'add') {
            $flag = 'allow';
            umc_echo("Allowing $action forming on lot $lot... ");
        } else if ($addrem == 'rem')  {
            $flag = 'deny';
            umc_echo("Preventing $action forming on lot $lot... ");
        } else {
            umc_show_help($args);
        }
        if ($action == 'snow') {
            $flagname = 'snow-fall';
        } else if ($action == 'ice') {
            $flagname = 'ice-form';
        }
        // does flag exist?
        $check_sql = "SELECT * FROM minecraft_worldguard.region_flag WHERE region_id='$lot' AND world_id=$world_id AND flag='$flagname';";
        $D2 = umc_mysql_fetch_all($check_sql);
        $count = count($D2);
        if ($count == 0) {
            // insert
            $ins_sql = "INSERT INTO minecraft_worldguard.region_flag (region_id, world_id, flag, value) VALUES ('$lot', $world_id, '$flagname', '$flag');";
            umc_mysql_query($ins_sql, true);
        } else {
            // update
            $upd_sql = "UPDATE minecraft_worldguard.region_flag SET value='$flag' WHERE region_id='$lot' AND world_id=$world_id AND flag='$flagname';";
            umc_mysql_query($upd_sql, true);
        }
        umc_echo("done!");
        umc_log('lot', 'addrem', "$player changed $action property of $lot");
    } else {
        if ($action == 'owner' || $action == 'give') {
            if ($player != 'uncovery' && $player != '@Console') {
                umc_error("Nice try, $player. Think I am stupid? Want to get banned?");
            }
            $owner_switch = 1;
        } else if ($action == 'member') {
            $user_id = umc_get_worldguard_id('user', strtolower($player));
            if (!$user_id && $player !== 'uncovery') {
                umc_error("Your user id cannot be found!");
            }
            $owner_switch = 0;
            // check if player is Owner of lot
            if ($player_group !== 'Owner') {
                $sql = "SELECT * FROM minecraft_worldguard.region_players WHERE region_id='$lot' AND world_id=$world_id AND user_id=$user_id and Owner=1;";
                $D3 = umc_mysql_fetch_all($sql);
                $count = count($D3);
                if ($count != 1) {
                    umc_error("It appears you ($player $user_id) are not Owner of lot $lot in world $world!");
                }
            }
        } else {
            umc_echo("Action $action not recognized!");
            umc_show_help($args);
            return;
        }
        // get list of active users
        $active_users = umc_get_active_members();

        for ($i=4; $i<count($args); $i++) {
            $target = strtolower($args[$i]);

            // check if target player exists
            $target_id = umc_get_worldguard_id('user', strtolower($target));
            if (!$target_id) {
                umc_error("The user $target does not exist in the database. Please check spelling of username");
            }
            if ($player != 'uncovery') {
                $targ_group = umc_get_userlevel($target);
                if ($targ_group == 'Guest') {
                    umc_error("You cannnot add Guests to your lot!;");
                } else if (!in_array($target, $active_users)) {
                    XMPP_ERROR_trigger("$player tried to add $target to his lot $lot, but $target is not an active member!");
                    umc_error("$target is not an active user!");
                }
            }

            // add / remove target player from lot
            if ($addrem == 'add') {
                // make sure target is not already there
                $sql = "SELECT * FROM minecraft_worldguard.region_players WHERE region_id='$lot' AND world_id=$world_id AND user_id=$target_id;";
                $D3 = umc_mysql_fetch_all($sql);
                $num = count($D3);
                if ($num == 1) {
                    umc_error("It appears $target is already member of lot $lot in world $world!");
                }
                // add to the lot
                umc_lot_add_player($target, $lot, 0);
                umc_echo("Added $target to $lot in the $world!");
            } else if ($addrem == 'rem') {
                // check if target is there at all
                $sql = "SELECT * FROM minecraft_worldguard.region_players WHERE region_id='$lot' AND world_id=$world_id AND user_id=$target_id AND Owner=$owner_switch LIMIT 1;";
                $D3 = umc_mysql_fetch_all($sql);
                $num = count($D3);
                if ($num !== 1) {
                    umc_error("It appears user $target is not a member of lot $lot in world $world!");
                }
                umc_lot_rem_player($target, $lot, 0);
                umc_echo("Removed $target from $lot in the $world!");
            } else if ($addrem == 'give') {
                // remove all members and owners
                umc_lot_remove_all($lot);
                umc_lot_add_player($target, $lot, 1);
                umc_echo("Gave $lot to $target in the $world! All other user removed!");
                // logfile entry
                umc_log('lot', 'addrem', "$player gave lot to $target");
            } else {
                umc_show_help($args);
            }
        }
    }
    umc_ws_cmd("region load -w $world", 'asConsole');
}

function umc_lot_warp() {
    global $UMC_USER;
    $player = $UMC_USER['username'];
    $userlevel = $UMC_USER['userlevel'];
    $world = $UMC_USER['world'];
    $args = $UMC_USER['args'];

    $allowed_ranks = array('Owner', 'Guest');
    if (!in_array($userlevel, $allowed_ranks)) {
        umc_error("Sorry, this command is only for Guests!");
    }

    $allowed_worlds = array('empire', 'flatlands');

    if (!in_array($world, $allowed_worlds)) {
        umc_error('Sorry, you need to be in the Empire or Flatlands to warp!');
    } else {
        $lot = strtolower(umc_sanitize_input($args[2], 'lot'));
        // the above one fails already if the lot is not a proper lot
        $target_world = umc_get_lot_world($lot);
        if (!in_array($target_world, $allowed_worlds)) {
            umc_error('Sorry, you need to be in the Empire or Flatlands to warp!');
        }
        if ($target_world != $world) {
            umc_error("Sorry, you need to be in $target_world to warp to $lot!");
        }
    }

    $sql = "SELECT * FROM minecraft_worldguard.world LEFT JOIN minecraft_worldguard.region ON world.id=region.world_id
        LEFT JOIN minecraft_worldguard.region_cuboid ON region.id=region_cuboid.region_id
        WHERE world.name='$target_world' AND region.id = '$lot' ";

    $D = umc_mysql_fetch_all($sql);
    if (count($D) != 1) {
        umc_error("There was an error teleporting you to your lot, the admin was notified, please wait for it to be fixed!");
    }
    $lots = $D[0];

    $c_x = $lots['min_x'] + 64;
    $c_z = $lots['min_z'] + 64;
    $c_y = 256;

    $cmd = "tppos $player $c_x $c_y $c_z 0";
    umc_ws_cmd($cmd, 'asConsole');
    umc_pretty_bar("darkblue", "-", "{darkcyan} Warping to lot $lot");
    umc_echo("You are now in the center of lot $lot!");
    umc_footer();
}