<?php

class WC_Tabby_Lock {

    public function lock($name) {
        global $wpdb;
        $res = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK('%s', 30);", esc_attr($name)));
        if ($res == 1) {
            return true;
        }
        return false;
    }
    public function unlock($name) {
        global $wpdb;
        $res = $wpdb->get_var($wpdb->prepare("SELECT RELEASE_LOCK('%s');", esc_attr($name)));
    }
}

