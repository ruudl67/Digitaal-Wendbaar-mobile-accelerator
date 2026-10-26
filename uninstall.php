<?php
/**
 * Opruimen bij verwijderen van de plugin.
 */
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('dwma_settings');
delete_post_meta_by_key('_dwma_page');
