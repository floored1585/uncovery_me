<?php

global $UMC_USERS; // this should contain all users that are set as an object

class User extends Users {

    // base items
    private $username;     // the current minecraft username
    private $uuid;         // the mojang uuid
    private $is_current;   // is the user causing the code to run also this user?

    private $is_banned;
    private $username_history;
    private $avatar;
    private $context;

    // if the user registerd on the blog
    private $registered_date;
    private $wordpress_id; // the numerical user id of the user
    private $userlevel;

    // donators
    private $is_donator;
    private $donator_time_left;

    // for active users
    private $is_active; // does the user have a lot?
    private $lot_count; // we could eliminate the above by having this here zero.
    private $lots;

    // in-game variables
    private $is_online;
    private $world;
    private $health;
    private $coordinates;
    private $inventory;
    private $current_item;

    public function __construct($unkonwn_id_type) {
        XMPP_ERROR_trace(__CLASS__ . " // " .  __FUNCTION__, func_get_args());
        // determine the ID type and then get the other one
        $type = determine_id_type($unkonwn_id_type);
    }
    
    public function set_uuid($uuid) {
        XMPP_ERROR_trace(__CLASS__ . " // " .  __FUNCTION__, func_get_args());
        // we assume that this is a valid UUID
        $this->uuid = $uuid;
    }
    
    // get the uuid, either from the set value, wordpress_id or username
    public function get_uuid() {
        XMPP_ERROR_trace(__CLASS__ . " // " .  __FUNCTION__, func_get_args());
        
        if (isset($this->uuid)) { // we have a uuid already
            return $this->uuid;
        } else if (isset($this->wordpress_id)) {
            // get uuid from wordpress_id
            $sql = "SELECT meta_value as output FROM minecraft.wp_usermeta
                WHERE user_id=$this->wordpress_id AND meta_key ='minecraft_uuid' LIMIT 1;";
            $D = umc_mysql_fetch_all($sql);
            if (count($D) !== 1) {
                XMPP_ERROR_trigger("Wordpress ID $this->wordpress_id is invalid!");
                return false;
            }
            $this->uuid = $D[0]['output'];
            return $this->uuid;
        } else if (isset($this->username)) {
            $sql = "SELECT meta_value as output FROM minecraft.wp_users
                LEFT JOIN minecraft.wp_usermeta ON ID=user_id
                WHERE display_name=$this->username AND meta_key ='minecraft_uuid' LIMIT 1;";
            $D = umc_mysql_fetch_all($sql);
            $this->uuid = $D[0]['output'];
            if (count($D) !== 1) {
                XMPP_ERROR_trigger("Username $this->username is invalid!");
                return false;
            }
        } else {
            XMPP_ERROR_trigger("No unique ID for user found!");
            return false;
        }
    }

    public function set_username($username) {
        XMPP_ERROR_trace(__CLASS__ . " // " .  __FUNCTION__, func_get_args());
        $this->username = $username;
    }
    
    // requires the UUID to be set
    public function get_username() {
        XMPP_ERROR_trace(__CLASS__ . " // " .  __FUNCTION__, func_get_args());
        
    }
    
    public function ban($reason) {
        XMPP_ERROR_trace(__CLASS__ . " // " .  __FUNCTION__, func_get_args());
        global $UMC_USERS;

        $cmd = "ban $this->username $reason";
        if ($this->context == 'websend') {
            umc_ws_cmd($cmd, 'asConsole', false, false);
            $admin = $UMC_USERS['current_user']->username;
        } else {
            umc_exec_command($cmd, 'asConsole', false);
            $admin = 'wordpress';
        }
        $sql = "INSERT INTO minecraft_srvr.`banned_users`(`username`, `reason`, `admin`, `uuid`) VALUES ('$this->username','$reason', '$admin', '$this->uuid');";
        umc_mysql_query($sql, true);
        // remove shop inventory
        umc_shop_cleanout_olduser($this->uuid);
        // remove from teamspeak
        umc_ts_clear_rights($this->uuid);

        $text = "$admin banned $$this->username ($this->uuid) because of $reason";
        umc_log('mod', 'ban', $text);
        XMPP_ERROR_send_msg($text);
    }
    
    private function determine_id_type($unknown_id_type) {
        if (is_int($unknown_id_type)) { // wordpress ID
            return 'wp_id';
        } else if (strlen($unknown_id_type) == 36) { // UUID
            // 4bb4ff2c-a75e-4ad0-9ff1-caed3cf1c5aa
            // 123456789012345678901234567890123456           
            // 0         1         2         3
            return 'uuid';
        } else { // wp_logon
            return 'wp_login';
        }
    }
}

class Users {
    public $users;
    
    public function __construct($unkonwn_id_type) {
        XMPP_ERROR_trace(__CLASS__ . " // " .  __FUNCTION__, func_get_args());
        $this->$users = new User($unkonwn_id_type);
        return $this->$users;
    }
}
