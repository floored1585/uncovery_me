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
 * This provides a list of items for conversion between the different standards
 * (spigot, minecraft, different plugins, historical names etc) and a hierarchy of
 * item names for the shop and other functions as well as some functions to manage them.
 */
global $UMC_FUNCTIONS;
$UMC_FUNCTIONS['get_icons'] = 'umc_get_icons';

function umc_item_data_create() {
    $files = array(
        'items' => 'items.json',
        'blocks' => 'blocks.json',
    );

    $versions = array(
        0 => array('target' => '112', 'source' => '1.12'),
        1 => array('target' => '113', 'source' => '1.13'),
        2 => array('target' => '1132', 'source' => '1.13.2'),
    );

    // versions iterations
    foreach ($versions as $V) {
        $path = "/home/minecraft/server/mc_assets/minecraft-data/data/pc/{$V['source']}/";
        $array_data = array();

        // we iterates blocks & items
        foreach ($files as $type => $filename) {
            $file_contents = file_get_contents($path . $filename);
            $data = json_decode($file_contents);
            foreach ($data as $obj) {
                $name = strtolower($obj->name);
                // we do not re-add blocks for which we have items already
                // this needs to be improved since the block data stacksize is more accurate than the items
                if (!isset($array_data[$name])) {
                    $array_data[$name] = array(
                        'stack' => $obj->stackSize,
                        'id' => $obj->id,
                        'display_name' => $obj->displayName,
                    );

                    // variations are only pre 1.13
                    if (isset($obj->variations)) {
                        $array_data[$name]['variations'] = array();
                        foreach ($obj->variations as $var) {
                            $var_id = $var->metadata;
                            $array_data[$name]['variations'][$var_id] = $var->displayName;
                        }
                    }
                }
            }
        }

        ksort($array_data);
        umc_array2file($array_data, "UMC_DATA_{$V['target']}", "/home/minecraft/server/bin/assets/item_details_{$V['target']}.inc.php");
    }
}

/**
 * this checks for broken items and fixes if possible.
 *
 * @global boolean $BROKEN_ITEMS
 * @param type $item_name
 */
function umc_broken_items_add_fix($item_name) {
    global $BROKEN_ITEMS;

    // check if we know about this one already
    if (!isset($BROKEN_ITEMS[$item_name])) {
        // we don't, add it to the list of items
        XMPP_ERROR_trigger("Could not identify $item_name as STRING umc_goods_get_text, added to broken items list, please add correct value!");
        $BROKEN_ITEMS[$item_name] = false;
        umc_array2file($BROKEN_ITEMS, "BROKEN_ITEMS", "/home/minecraft/server/bin/assets/broken_items.inc.php");
    } else {
        // we know about this already, let's check if we can find this in the DB?
        // just to make sure
        $check = umc_itemdata_databasecheck($item_name);
        if (!$check) {
            XMPP_ERROR_trigger("Could not identify $item_name as STRING umc_goods_get_text, it's already in the broken items list but NOT in the database????");
        } else {
            // ok, now let's try to fix it
            umc_item_fix_old($item_name, $BROKEN_ITEMS[$item_name]);
        }
    }

}

function umc_item_data_create_icon($item_name) {
    global $UMC_SETTING, $UMC_DOMAIN;
    $version = $UMC_SETTING['mc_version'];



    $item_sub_folder = "/admin/mc_assets/$version/items/";
    $block_sub_folder = "/admin/mc_assets/$version/blocks/";
    $file = "$item_name.png";

    $item_path = $UMC_SETTING['path']['html'] . $item_sub_folder . $file;
    $block_path = $UMC_SETTING['path']['html'] . $block_sub_folder . $file;

    $type = 'item';
    if (!file_exists($item_path)) {
        $type = 'block';
    }

    $item_html = "<img src=\"$UMC_DOMAIN/$item_sub_folder$file\">";

}

function umc_item_data_versionmatch() {
    $old_version = "/home/minecraft/server/bin/assets/item_details_112.inc.php";
    $new_version = "/home/minecraft/server/bin/assets/item_details_113.inc.php";

    global $UMC_DATA_112;
    global $UMC_DATA_113;

    include_once($old_version);
    include_once($new_version);

    // create a list of the new items based on display names
    $name_arr_113 = array();
    foreach ($UMC_DATA_113 as $name => $D) {
        $dis_name = $D['display_name'];
        $name_arr_113[$dis_name] = $name;
    }

    $invalid = array();

    // iterate the old items and see if we can find them in the new list
    $name_translate = array();
    foreach ($UMC_DATA_112 as $name => $D) {
        $dis_name = $D['display_name'];
        $dis_name = str_replace("Wood ", "", $dis_name);
        $dis_name = str_replace("Chain ", "Chainmail ", $dis_name);
        if (isset($D['variations'])) {
            foreach ($D['variations'] as $type_id => $var_display_name) {
                // remove all the "Wood" for better matching
                $var_display_name = str_replace("Wood ", "", $var_display_name);
                $var_display_name = str_replace("Chain ", "Chainmail ", $var_display_name);
                if (umc_itemdata_databasecheck($name, $type_id)) {
                    if (isset($name_arr_113[$var_display_name])) {
                        if ($name != $name_arr_113[$var_display_name]) {
                            $name_translate[$name][$type_id] = $name_arr_113[$var_display_name];
                        }
                    } else {
                        $invalid[$name][$type_id] = $var_display_name;
                    }
                }
            }
        } else {
            if (umc_itemdata_databasecheck($name, 0)) {
                if (isset($name_arr_113[$dis_name])) {
                    if ($name != $name_arr_113[$dis_name]) {
                        $name_translate[$name][0] = $name_arr_113[$dis_name];
                    }
                } else {
                    $invalid[$name][0] = $dis_name;
                }
            }
        }
    }

    $count = count($invalid);
    $name_translate['invalid'] = $invalid;
    umc_array2file($name_translate, 'UMC_DATA_tranlation', "/home/minecraft/server/bin/assets/item_details_translation.inc.php");
    echo "$count invalid items found";
}

/**
 * check if an item exists anywhere in the database
 *
 * @param type $name
 * @param type $id
 * @return boolean
 */
function umc_itemdata_databasecheck($name, $id = false) {
    //check invalid items if they actually exist
    $tables = array(
        'minecraft_iconomy.transactions',
        'minecraft_iconomy.stock',
        'minecraft_iconomy.request',
        'minecraft_iconomy.deposit',
    );

    $damage_check = '';
    if ($id) {
        $damage_check = " AND `damage` = $id";
    }

    foreach ($tables as $table) {
        $sql = "SELECT * FROM $table WHERE `item_name` = '$name'$damage_check";
        $D = umc_mysql_fetch_all($sql);
        if (count($D) > 0) {
            return true;
        }
    }
    return false;
}

function umc_item_search_create() {
    XMPP_ERROR_trace(__FUNCTION__, func_get_args());
    // this here creates a new items array file
    $search_arr = umc_item_data_get_namelist();
    if (($handle = fopen("/home/minecraft/server/bukkit/plugins/Essentials/items.csv", "r")) !== FALSE) {
        while (($items = fgetcsv($handle, 10000, ",")) !== FALSE) {
            XMPP_ERROR_trace("Reading Essentials CSV");
            // get the fist letter to weed out comments
            $firstletter = substr($items[0], 0, 1);
            if (count($items) == 3 && $firstletter !== '#' && !isset($search_arr[$items[0]])) {
                // we get the numeric ID from the list
                // csv format is:
                // rock,1,0
                // item_name, num_id, type_id
                $item = umc_goods_get_text($items[1], $items[2]);
                if ($item) { // the file contains a bunch of unobtainable stuff, we skip that
                    $search_arr[$items[0]] = array('item_name' => $item['item_name'], 'type' => $item['type']);
                }
            }
        }
        umc_array2file($search_arr, 'ITEM_SEARCH', '/home/minecraft/server/bin/assets/item_search.inc.php');
    } else {
        die("Could not read items file!");
    }
}

/**
 * This downloads all icons from Minecraft Wiki and stores it on the website
 *
 */
function umc_get_icons() {
    global $UMC_DATA, $UMC_PATH_MC;
    $base_url = 'http://hydra-media.cursecdn.com/minecraft.gamepedia.com';
    $base_path = "$UMC_PATH_MC/server/bin/data/icons/";

    $img_arr = array();
    foreach ($UMC_DATA as $item => $D) {
        if (isset($D['subtypes'])) {
            foreach ($D['subtypes'] as $id => $S) {
                if ($S['icon_url'] != '?') {
                    $img_arr[$S['name']] = $base_url . $S['icon_url'];
                }
            }
        }
        if ($D['icon_url'] === '?') {
            continue;
        } else {
            $img_arr[$item] = $base_url . $D['icon_url'];
        }
    }
    // pass all arrays to mass-downloader
    $complete_count = count($img_arr);
    $D = unc_serial_curl($img_arr);

    $failed_icons = array();
    foreach ($D as $img => $R) {
        if ($R['response']['http_code'] !== 200) {
            $failed_icons[] = array(
                'img' => $img,
                'url' => $R['response']['url'],
                'reason' => "failed to get file from source",
            );
        } else {
            // assemble target path
            $full_url = $R['response']['url'];
            $path_info = pathinfo($full_url);
            if (!isset($path_info['extension'])) {
                XMPP_ERROR_trace("Extension missning for $img", $full_url);
            }
            $ext = $path_info['extension'];
            $target_path = $base_path . "$img.$ext";
            // write target file
            $written = file_put_contents($target_path, $R['content']);
            if (!$written) {
                $failed_icons[] = array(
                    'img' => $img,
                    'url' => $R['response']['url'],
                    'reason' => 'failed to write file to $target_path',
                );
            }
        }
    }
    $count = count($failed_icons);
    if ($count > 0) {
        XMPP_ERROR_trace("failed users:", $failed_icons);
        XMPP_ERROR_trigger("Failed to get $count of $complete_count Block icons, see error report for details");
    }
}

/**
 * returns the list of available names with their main item name and the type number
 *
 * @global array $UMC_DATA
 * @return type
 */
function umc_item_data_get_namelist() {
    global $UMC_DATA;
    XMPP_ERROR_trace(__FUNCTION__, func_get_args());
    $out = array();
    foreach ($UMC_DATA as $item_name => $data) {
        $out[$item_name] = array('item_name' => $item_name, 'type' => 0);
        if (isset($data['subtypes'])) {
            foreach ($data['subtypes'] as $sub_id => $subtype) {
                if (!isset($out[$subtype['name']])) {
                    $out[$subtype['name']] = array('item_name' => $item_name, 'type' => $sub_id);
                }
            }
        }
    }
    return $out;
}

function umc_item_data_id2namelist() {
    global $UMC_DATA;
    $D = array();
    foreach ($UMC_DATA as $item_name => $data) {
        $id = $data['id'];
        $D[$id] = $item_name;
    }
    ksort($D);
    umc_array2file($D, 'UMC_DATA_ID2NAME', __DIR__ . "/item_id2name.inc.php");
}

/**
 * get the full HTML of the icon of an item/block to be displayed in-line
 * in HTML
 *
 * @param type $item_name
 * @param type $sub_type
 */
function umc_item_data_icon_html($item_name, $sub_type = false) {

    $html = "<span class=\"item_sprite item_{$item_name}_{$sub_type}\"> </span>";
    return $html;
}


/**
 * Getting the data from the google spreadsheets
 *
 * @return type
 */
function umc_item_data_icon_getdata() {
    global $UMC_DATA;
    // google API settings:
    // https://console.developers.google.com/iam-admin/serviceaccounts/project?project=plucky-sight-167212&organizationId=0

    // used code:
    // https://github.com/juampynr/google-spreadsheet-reader

    require '/home/includes/google_api/vendor/autoload.php';
    $service_account_file = '/home/includes/google_api/google_auth.json';
    $spreadsheet_id = '1b3M2EPGzNFtMp-hW9Sam5ETQg2eTuBEg1S1WArhwJKY';
    $spreadsheet_range = 'Entry!B2:AG51';

    putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $service_account_file);
    $client = new Google_Client();
    $client->useApplicationDefaultCredentials();
    $client->addScope(Google_Service_Sheets::SPREADSHEETS_READONLY);
    $service = new Google_Service_Sheets($client);

    $result = $service->spreadsheets_values->get($spreadsheet_id, $spreadsheet_range);

    $data = $result->getValues();
    echo "processing " . count($data) . " datasets for itemsprites";

    $final_data = array();

    $icon_size = 32;
    $image_width = 1024;
    $scale = 0.75;

    $background_size_x = $image_width * $scale;
    $img_size = $icon_size * $scale;

    // item sprite css header
    $css = ".item_sprite {display: inline-block; background-size: {$background_size_x}px; background-image: url(/admin/img/InvSprite.png); "
        . "background-repeat: no-repeat; width:{$img_size}px; height:{$img_size}px;}\n"
        . ".item_golden_apple_1 {background-position:-24px -624px;}\n"; // the enchanted golden apple is the same as the normal one but not twice in the table
    foreach ($data as $row => $L) {
        foreach ($L as $line => $name) {
            $name_type = 0;
            $coords = array('x' => $line, 'y' => $row);

            if ($name == '' || strstr($name, " ") || strstr($name, ".")) {
                continue;
            } else if (strstr($name, ":")) {
                $name_data = explode(":", $name);
                $item_name = $name_data[0];
                $name_type = $name_data[1];
                if (!isset($UMC_DATA[$item_name])) {
                    continue;
                }
                $final_data[$item_name][$name_type] = $coords;
            } else {
                if (!isset($UMC_DATA[$name])) {
                    continue;
                }
                $item_name = $name;
                $final_data[$item_name]['coords'] = $coords;
            }
            $x = $line * $scale * $icon_size;
            $y = $row * $scale * $icon_size;
            $css .=  ".item_{$item_name}_{$name_type} {background-position:-{$x}px -{$y}px;}\n";
        }
    }

    ksort($final_data);
    //$final_data['invalid'] = $invalid_data;

    umc_array2file($final_data, 'item_sprites', '/home/minecraft/server/bin/assets/item_sprites.inc.php');

    //TODO: Download latest version of this file:
    // http://hydra-media.cursecdn.com/minecraft.gamepedia.com/4/44/InvSprite.png
    //wiki page here: http://minecraft.gamepedia.com/File:InvSprite.png

    $source_file = 'https://d1u5p3l4wpay3k.cloudfront.net/minecraft_gamepedia/4/44/InvSprite.png';
    $target_directory = '/home/minecraft/server/bin/data/images';
    // $R = unc_serial_curl($source_file);
    // file_put_contents($target_directory . "/InvSprite.png", $R[0]['content']);

    // write CSS to file
    $css_file = '/home/minecraft/server/bin/data/item_sprites.css';
    file_put_contents($css_file, $css);
}

/**
 * get the invSprote data from the Wiki
 * and adds it to the table minecraft_srvr.items
 */
function umc_item_data_wiki() {
    global $UMC_DATA_ITEM2WIKI, $UMC_CONFIG;

    // STEP 1: get the whole website data
    $url = 'https://minecraft.gamepedia.com/Module:InvSprite';
    $url_data = unc_serial_curl($url,0,50, $UMC_CONFIG['ssl_cert']);

    // STEP 2: filter out only the LUA part
    $matches = false;
    $regex = '/.*(return {[\S\s]*)<\/pre>/';
    preg_match($regex, $url_data[0]['content'], $matches);

    $searches = array(
        0 => '/&quot;/', // replace HTML entities (could be done with htmlentities?
        1 => '/url = require.*,/', // filter out some requirements for the wiki
        2 => '/&amp/', // replace HTML entities (could be done with htmlentities?
    );
    $replacement = array(
        0 => '"',
        1 => '',
        2 => '&',
    );
    $fixed = preg_replace($searches, $replacement, $matches[1]);

    // STEP 3: parse the LUA
    $file = new Lua();
    $output = $file->eval($fixed);

    // STEP 4: Get the important parts of the array
    $ids = $output['ids'];
    $raw_sections = $output['sections'];

    // STEP 5 filter out bad sections
    $sections = array(); // make array with sections so we can match out the bad ones
    foreach ($raw_sections as $S) {
        $s_id = $S['id'];
        $sections[$s_id] = $S['name'];
    }

    $invalid_sections = array(
        'April Fools',
        'Outdated',
        "Bedrock Edition & Education Edition"
    );

    // STEP 6 add the sprite locations to the MySQL table where we have a perfect match
    foreach ($ids as $item_text => $I) {
        $section_id = $I['section'];
        $section_name = $sections[$section_id];
        if (!in_array($section_name, $invalid_sections)) {
            $item_name = strtolower(str_replace(" ", "_", $item_text));
            $item_safe = umc_mysql_real_escape_string($item_name);
            $sql = "UPDATE minecraft_srvr.items SET sprite_location = {$I['pos']} WHERE item_name LIKE $item_safe;";
            umc_mysql_execute_query($sql);
        }
    }

    // STEP 7 take the ones without a perfect match & add them to the file array where we don't have a stored fix in the file
    $sql = "SELECT item_name FROM minecraft_srvr.items WHERE sprite_location = 0;";
    $D = umc_mysql_fetch_all($sql);
    $count = 0;

    // just in case this file is generated for the first time...
    if (!isset($UMC_DATA_ITEM2WIKI)) {
        $UMC_DATA_ITEM2WIKI = array();
    }

    foreach ($D as $d) {
        $name = $d['item_name'];
        // this is for items that don't exist in the array yet, that are new in the LUA file online
        if (!isset($UMC_DATA_ITEM2WIKI[$name])) {
            $UMC_DATA_ITEM2WIKI[$name] = '';
        }

        // this is for the items that might be new or old, but they don't have a translation yet
        if ($UMC_DATA_ITEM2WIKI[$name] == '') {
            $count ++;
        }
    }

    // STEP 8 Message the admin that there are unmatched item names that need to be filled in manually
    if ($count > 0) {
        XMPP_ERROR_trigger("Found $count undefined items in Inv_sprite on the minecraft wiki! Please fill the empty ones in item_wiki2item.inc.php!");
    }

    $comments = "This file is needed to translate item names where the InvSprites name in this URL
https://minecraft.gamepedia.com/Module:InvSprite
does not matach the actual item name. The file will automatically expanded when there are new item names found in minecraft with empty array values.
Please look for empty array values and match their key with above URL. Once you fill those in, they will stay in the file.
Syntax is item_name => wiki_name";
    umc_array2file($UMC_DATA_ITEM2WIKI, 'UMC_DATA_ITEM2WIKI', '/home/minecraft/server/bin/assets/item_item2wiki.inc.php', $comments);
}

/**
 * fix old item names in tables
 */
function umc_item_fix_old($search, $replace) {
    $tables = array(
        'minecraft_iconomy.transactions',
        'minecraft_iconomy.stock',
        'minecraft_iconomy.request',
        'minecraft_iconomy.deposit',
    );

    foreach ($tables as $table) {
        $update_sql = "UPDATE $table SET `item_name` = REPLACE(`item_name`, $search, $replace) WHERE `item_name` LIKE '$search';";

        XMPP_ERROR_send_msg($update_sql);
        // $X = umc_mysql_query($update_sql);
    }
}

$enchant_legacy_convert = array(
    array('search'=>'id:protection}', 'replace'=>'id:"minecraft:protection"}'),
    array('search'=>'id:fire_protection}', 'replace'=>'id:"minecraft:fire_protection"}'),
    array('search'=>'id:feather_falling}', 'replace'=>'id:"minecraft:feather_falling"}'),
    array('search'=>'id:blast_protection}', 'replace'=>'id:"minecraft:blast_protection"}'),
    array('search'=>'id:0}', 'replace'=>'id:"minecraft:protection"}'),
    array('search'=>'id:1}', 'replace'=>'id:"minecraft:fire_protection"}'),
    array('search'=>'id:2}', 'replace'=>'id:"minecraft:feather_falling"}'),
    array('search'=>'id:3}', 'replace'=>'id:"minecraft:blast_protection"}'),
    array('search'=>'id:4}', 'replace'=>'id:"minecraft:projectile_protection"}'),
    array('search'=>'id:5}', 'replace'=>'id:"minecraft:respiration"}'),
    array('search'=>'id:6}', 'replace'=>'id:"minecraft:aqua_affinity"}'),
    array('search'=>'id:7}', 'replace'=>'id:"minecraft:thorns"}'),
    array('search'=>'id:8}', 'replace'=>'id:"minecraft:depth_strider"}'),
    array('search'=>'id:9}', 'replace'=>'id:"minecraft:froster_walker"}'),
    array('search'=>'id:10}', 'replace'=>'id:"minecraft:binding_curse"}'),
    array('search'=>'id:16}', 'replace'=>'id:"minecraft:sharpness"}'),
    array('search'=>'id:17}', 'replace'=>'id:"minecraft:smite"}'),
    array('search'=>'id:18}', 'replace'=>'id:"minecraft:bane_of_arthropods"}'),
    array('search'=>'id:19}', 'replace'=>'id:"minecraft:knockback"}'),
    array('search'=>'id:20}', 'replace'=>'id:"minecraft:fire_aspect"}'),
    array('search'=>'id:21}', 'replace'=>'id:"minecraft:looting"}'),
    array('search'=>'id:22}', 'replace'=>'id:"minecraft:sweeping"}'),
    array('search'=>'id:32}', 'replace'=>'id:"minecraft:efficiency"}'),
    array('search'=>'id:33}', 'replace'=>'id:"minecraft:silk_touch"}'),
    array('search'=>'id:34}', 'replace'=>'id:"minecraft:unbreaking"}'),
    array('search'=>'id:35}', 'replace'=>'id:"minecraft:fortune"}'),
    array('search'=>'id:48}', 'replace'=>'id:"minecraft:power"}'),
    array('search'=>'id:49}', 'replace'=>'id:"minecraft:punch"}'),
    array('search'=>'id:50}', 'replace'=>'id:"minecraft:flame"}'),
    array('search'=>'id:51}', 'replace'=>'id:"minecraft:infinity"}'),
    array('search'=>'id:61}', 'replace'=>'id:"minecraft:luck_of_the_sea"}'),
    array('search'=>'id:62}', 'replace'=>'id:"minecraft:lure"}'),
    array('search'=>'id:70}', 'replace'=>'id:"minecraft:mending"}'),
    array('search'=>'id:71}', 'replace'=>'id:"minecraft:vanishing_curse"}'),
);