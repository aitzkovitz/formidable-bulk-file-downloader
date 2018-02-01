<?php
/*
Plugin Name: Bulk File Downloader
Description: Plugin extending formidable forms by adding a download all button below files area.
Author: Aaron Itzkovitz
Version: 1.0.0
Author URI: http://aaronitzkovitz.com/
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }
define( 'PLUGIN_NAME', 'bulk-file-downloader' );
define( 'FORMIDABLE_UPLOADS', trailingslashit( wp_upload_dir()['baseurl'] ) . 'formidable/10' );

/**
 * Register the JavaScript for the admin 'Edit Entry' page.
 *
 * @since    1.0.0
 */
function bfd_add_dl_button( $hook_suffix ) {
    if( $hook_suffix == 'formidable_page_formidable-entries' 
    	&& isset($_GET[ 'frm_action' ]) 
    	&& $_GET[ 'frm_action' ] == 'edit') {
    		wp_enqueue_script( 
				'bfd-dl-button',
				plugin_dir_url( __FILE__ ) . 'js/bfd.js',
				array( 'jquery' ),
				1.0,
				true
			);
		  	wp_localize_script( 'bfd-dl-button' , 'scriptObject', array(
				'ajaxurl'               => admin_url( 'admin-ajax.php' ),
				'security'              => wp_create_nonce( 'bfv-files-dl' ),
				'id'					=> intval( $_GET[ 'id' ] )
			));
    }
}
add_action( 'admin_enqueue_scripts', 'bfd_add_dl_button' );

/**
 * Register the AJAX hook to update the database with new 'Invoice' data.
 *
 * @since    1.0.0
 */
function zip_send_back(){

	$errors = array();

	if ( !check_ajax_referer( 'bfv-files-dl', 'security', false) ) {
		$errors[] = 'Security check failed';
	}

	$entry_id = intval( $_POST[ 'data' ][ 'entry_id' ] );

	global $wpdb;
	$query = $wpdb->get_results( 
		"SELECT meta_value FROM wp_frm_item_metas 
		WHERE item_id = $entry_id
		AND field_id = 123"
	);

	// return if no results
	if ( !isset( $query ) || empty( $query ) || (int)$wpdb->num_rows > 1 ){
		wp_send_json_error( array(
			'reason'	=> 'Query error.'
		));
	}
	
	$file_ids = maybe_unserialize( $query[ 0 ]->meta_value );

	$user_id = get_current_user_id();
   
    if ( empty( $errors ) ) {
       	$valid_file_ids = true;
        $files_to_download = $wpdb->get_results( 
			"SELECT * FROM {$wpdb->prefix}postmeta
			WHERE post_id IN (" . implode( ',', $file_ids ) . ")
			AND meta_key = '_wp_attached_file'"
		);

        if ( empty( $files_to_download ) ) {
            $valid_file_ids = false;
        }
    }
   
    if ( ! $valid_file_ids ) {
		$errors[] = "Could not find any valid files";
    } else {
           
        foreach ( $files_to_download as $file_to_download ) {
	        if ( current_user_can( 'edit_post', $file_to_download->post_id ) ) {

                $valid_files[] = $file_to_download;
			}
        }
       
        if ( empty( $valid_files ) ) {
            $errors[] =  'Invalid file permissions.';
        }
        if ( count( $valid_files ) == 1 ){
        	// no need to make a zip
        	$single_file_path = get_attached_file( $valid_files[0]->post_id, true );
        	error_log('1 file: ' . $single_file_path );
        	wp_send_json_success( array(
        		'path'		=> FORMIDABLE_UPLOADS . '/' . basename( $single_file_path ),
        		'fname'		=> basename( $single_file_path )
        	));
        }
           
    }
    if ( empty( $errors ) ) { 
                           
        // create user folder if necessary
        $user_id = get_current_user_id();
        $zip_dir = WP_PLUGIN_DIR . '/' . PLUGIN_NAME . '/downloads/' . $user_id;
        if ( ! file_exists( $zip_dir ) ) {
        	mkdir( $zip_dir, 0755, true );
        	
        }
       
        //sanitize data
        $post_title = date('Y-m-d') . '_' . $entry_id;
        $post_title = sanitize_file_name( $post_title );

        $name_count = 0;
        while ( file_exists( $zip_dir . '/' . $post_title . ( $name_count > 0 ? $name_count : '' ) .'.zip' ) ) {
                $name_count++;
        }

        if ( $name_count > 0 ) {
			$post_title .= $name_count;
        }
       
        // create the zip file
        if ( class_exists( 'ZipArchive' ) ) {
            $zip = new ZipArchive();
            $zip_opened = $zip->open( $zip_dir . '/' . $post_title . '.zip', ZipArchive::CREATE );
	
            ob_start();
			var_dump( $zip );
			$contents = ob_get_contents();
			ob_end_clean();
			error_log('query contents: ' . $contents);

            if ( true === $zip_opened ) {
           
                $upload_dir_info = wp_upload_dir();
                $added_rel_filepaths = array();
               
                // add the files to the zip
                foreach ( $valid_files as $valid_file ) {
                    $file_path = get_attached_file( $valid_file->post_id, true );
                    if ( file_exists( $file_path ) ) { 

						$relative_file_path = wp_basename( $file_path );   
                        $added_rel_filepaths = bfd_add_file_to_zip( $zip, $file_path, $relative_file_path, $added_rel_filepaths );


                    }   
                }
                // close the zip
                $zip->close();
               
                if ( file_exists( $zip_dir . '/' . $post_title . '.zip' ) ) {
                    wp_send_json_success(array(
                        'path'      => plugin_dir_url( __FILE__ ) . 'downloads/' . $user_id . '/' . $post_title . '.zip',
                        'fname'		=> $post_title . '.zip'
                    ));
               
                } else {
					$errors[] = 'The download could not be created.';
                }
                   
            } else {
               	$errors[] = 'The download could not be created.';
            }
               
        } else {
           	$errors[] = 'Error. Your download could not be created. It looks like you don\'t have ZipArchive installed on your server.';
        }
   
	    if ( ! empty( $permissions_errors ) ) {
	           
	        $results_msg = '<div class="jabd-popup-msg"><span>' . $permissions_errors[0] . '</span></div>';
	        wp_send_json_error(array(
	                'messages'      => $results_msg
	        ));   
	    }
	}	
}
add_action( 'wp_ajax_dl_files_zip', 'zip_send_back' );

function bfd_add_file_to_zip( $zip, $file_path, $relative_file_path, $added_rel_filepaths ) {
       
    $relative_file_path = bfd_unique_filepath_in_filepaths_array( $relative_file_path, $added_rel_filepaths );
   
    // add the file using the relative file path
    if ( $zip->addFile( $file_path, $relative_file_path ) ) {
    	$added_rel_filepaths[] = $relative_file_path;
    }
    
    return $added_rel_filepaths;
}

function bfd_unique_filepath_in_filepaths_array( $relative_file_path, $added_rel_filepaths ) {
    $count = -1;
    do {
        $count++;
        $path_and_ext = bfd_split_filepath_and_ext( $relative_file_path );
        $relative_file_path = $path_and_ext['path'] . ( $count > 0 ? $count : '' ) . $path_and_ext['ext'];
    } while ( in_array( $relative_file_path, $added_rel_filepaths ) );

    return $relative_file_path;
}

function bfd_split_filepath_and_ext( $filepath ) {
    $dotpos = strrpos( $filepath, '.' );
    if ( false === $dotpos ) {
            $output['path'] = $filepath;
            $output['ext'] = '';
    } else {
            $output['path'] = substr( $filepath, 0, $dotpos );
            $output['ext'] = substr( $filepath, $dotpos );
    }

    return $output;
}