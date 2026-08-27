<?php
if (!defined('ABSPATH')) exit;

class WCEP_Deactivator {

    public static function deactivate() {
        flush_rewrite_rules();
    }
}
