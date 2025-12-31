<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$options = array(
    'wsf_search_fields',
    'wsf_required_steps',
    'wsf_parent_attributes',
);

foreach ( $options as $option ) {
    delete_option( $option );
    delete_site_option( $option );
}
