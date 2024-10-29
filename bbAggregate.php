<?php
/*******************************************************************************
Plugin Name: bbAggregate
Plugin URI: http://www.burobjorn.nl
Description: bbAggregate allows you to aggregate content from multiple blogs into one stream 
Author: Bjorn Wijers <burobjorn at burobjorn dot nl> 
Version: 1.0
Author URI: http://www.burobjorn.nl
*******************************************************************************/   
   
/*  Copyright 2010
  

bbAggregate is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

bbAggregate is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/


if ( ! class_exists('bbAggregate')) {
  class bbAggregate {
    
    /**
     * @var string The options string name for this plugin
    */
    var $options_name = 'bbagg_var_options';
    
    /**
     * @var string $localization_domain Domain used for localization
    */
    var $localization_domain = "bbagg";


    /** 
     * @var float current version number
     * @todo do not forget to change the version number upon release
     */
    var $bbagg_version = 1;

    /**
     * @var string $plugin_url The path to this plugin
    */ 
    var $plugin_url = '';
    
    /**
     * @var string $plugin_path The path to this plugin
    */
    var $plugin_path = '';
        
    /**
     * @var array $options Stores the options for this plugin
    */
    var $options = array();

    /**
     * @var wpdb database object
     */
    var $wpdb = null;

    /**
     * @var database table names
     */
    var $db = array();

    /**
     * @var bbAggregateStream object
     */
    var $stream;

    /**
     * @var holds the lasts aggregated stream's pagination info
     */
    var $last_aggregated_pagination_data = array();  


    /**
     * PHP 4 Compatible Constructor
    */
    function bbAggregate(){ $this->__construct(); }
    
    /**
     * PHP 5 Constructor
    */        
    function __construct()
    {
      // language setup
      $locale = get_locale();
      $mo     = dirname(__FILE__) . "/lang/" . $this->localization_domain . "-".$locale.".mo";
      load_textdomain($this->localization_domain, $mo);

      // 'constants' setup
      $this->plugin_url  = WP_PLUGIN_URL  . '/' . dirname(plugin_basename(__FILE__) ) .'/';
      $this->plugin_path = WP_PLUGIN_DIR . '/' . dirname(plugin_basename(__FILE__) ) .'/';
      
      // database connection setup
      global $wpdb;
      $this->wpdb = $wpdb;

      // setup stream
      require_once('lib/bbAggregateStream.class.php');
      $this->stream = new bbAggregateStream($this->wpdb);

      // setup stream
      require_once('lib/bbAggregateStreamItem.class.php');
      $this->stream_item = new bbAggregateStreamItem($this->wpdb);

      // database table names
      $this->db['table_stream'] = $this->wpdb->base_prefix . 'bbAggregate_stream';
      $this->db['table_stream_item'] = $this->wpdb->base_prefix . 'bbAggregate_item';

      // initialize the options
      //This is REQUIRED to initialize the options when the plugin is loaded!
      $this->get_sitewide_options();
      
      // Wordpress actions        
      //add_action('init', array(&$this, "init"), 10, 0);
      register_activation_hook(__FILE__, array(&$this, "activate") );
      add_action("admin_menu", array(&$this,"admin_menu_link") );
      add_action('admin_enqueue_scripts', array(&$this, 'admin_js') );
      add_action('save_post', array(&$this, 'save_post_stream_data') );
      add_action('delete_post', array(&$this, 'remove_post_stream_data') );
      add_action('wpmu_options', array(&$this, 'network_options_gui'), 10, 0);
      add_action('update_wpmu_options', array(&$this, 'network_options_save'), 10, 0 );
      
      // actions on which the blog_list cache needs an update  
      add_action('wpmu_update_blog_options', array(&$this, '_clear_blogs_transient'), 99, 0);
      add_action('wpmu_new_blog', array(&$this, '_clear_blogs_transient'), 99, 0);
      add_action('delete_blog', array(&$this, '_clear_blogs_transient'), 99, 0);

      // add uninstall 
      register_uninstall_hook(__FILE__, array(&$this, 'uninstall') );
 
    }

    /**
     * Upon plugin activation check if WordPress is in multisite
     * mode. 
     * 
     * @access public 
     * @return void
     **/
    function activate() 
    {
      if( is_multisite() ) {
        // setup the database if needed
        $this->setup_database();
      } else {
        // explain the plugin won't work
        // unless multisite is enabled
        wp_die(__('The bbAggregate plugin will only work in a multisite enabled WordPress.', $this->localization_domain) );
      }
    }

    
    /** 
     * Upon initialization add a metabox to the WordPress admin post interface
     * this allows a user to add their post to one or more streams
     *
     * @access public
     * @return void
     */
    function setup_metabox() 
    {
      add_meta_box($id = 'bbagg-streams', $title = 'Streams', $callback = array(&$this, 'render_post_stream_gui'), 
        $page = 'post', $context = 'normal', $priority = 'high', $callback_args=null
      );
    }

    
    /**
     * Renders (echo) a metabox with all streams allowed for this blog
     * to which a post may be added. Callback for add_meta_box  
     *
     * @access public
     * @return string html
     */
    function render_post_stream_gui() 
    {
      global $blog_id;
      global $post_id;
      $excluded_streams = $this->get_excluded_streams($blog_id);
      $selected_streams = $this->get_item_streams($post_id, $blog_id); 

      $html  = '';
      $html .= '<p>';
      $html .= __('Add the post to one or more streams: <br />', $this->localization_domain);
      $html .= $this->show_checkboxes_streams($selected_streams, $excluded_streams, $echo = false );
      $html .= wp_nonce_field('bbagg-streams', $name = 'bbagg_wpnonce', true, false);
      $html .= '</p>';
      echo $html; 
    }


    /**
     * Saves stream data upon the WordPress hook save_post
     *
     * @param int post_id
     * @return int post_id
     */
    function save_post_stream_data( $post_id ) 
    {
      // only continue if we're not dealing with a revision or auto save
      if( wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) ) {
        return $post_id;
      }
      // check nonce and make sure we're dealing with a post 
      if ( isset($_POST['bbagg_wpnonce']) && (wp_verify_nonce($_POST['bbagg_wpnonce'], 'bbagg-streams') ) && ('post' === $_POST['post_type']) ) { 
        global $blog_id;
        global $user_ID;
        // First check if the post is already part of one or more streams 
        // if it was part of any stream, remove the post from the streams
        // and then add it again with the new data if needed. This makes
        // it easy for a user to deselect a post from a stream 
        if( $this->stream_item->does_item_exist($post_id, $blog_id) ) {
          $this->remove_item($post_id, $blog_id); 
        }
        if( isset($_POST['bbagg_stream_ids']) && ! empty($_POST['bbagg_stream_ids']) && $_POST['post_status'] == 'publish') {
          $stream_ids = $_POST['bbagg_stream_ids'];
          if( is_array($stream_ids) ) {
            foreach($stream_ids as $stream_id) {
              $this->save_item($post_id, $blog_id, $stream_id, $user_ID);
            }
          }
        }
      } 
      // always return the post_id for further use  
      return $post_id;  
    }

    
    /*
     * If a post gets removed and it is part of a stream
     * we need to remove the post from the streams it was part of as well.
     * Posts placed into the trash will only be removed if they are literally removed
     * from the database. Called by delete_post hook. 
     *
     * @access public
     * @param int post_id
     * @return int post_id
     */
    function remove_post_stream_data($post_id)
    {
      // only continue if we're not dealing with a revision or auto save
      if( wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) ) {
        return $post_id;
      }

      global $blog_id;
      // First check if the post is already part of one or more streams 
      // if it was part of any stream, remove the post from the streams
      // and then add it again with the new data if needed. This makes
      // it easy for a user to deselect a post from a stream 
      if( $this->stream_item->does_item_exist($post_id, $blog_id) ) {
        $this->remove_item($post_id, $blog_id);
      }
      return $post_id;
    } 


    /**
     * Retrieve all streams which should not be 
     * shown in this particular blog
     */  
    function get_excluded_streams($blog_id) 
    {
      $excluded_streams = array();
      $all_streams = $this->get_all_streams();
      if( is_array($all_streams) ) {
        foreach($all_streams as $s) {
          if( is_object($s) ) {
            if( is_array($s->stream_options['bbagg_site_blogs']) ) {
              if(in_array($blog_id, $s->stream_options['bbagg_site_blogs']) ) {
                $excluded_streams[] = $s->stream_id;
              }
            }
          } 
        }
      }
      return $excluded_streams;
    }



    /**
     * Checks if a given table is already installed 
     *  
     * @access private
     * @param string table name  
     * @return bool true on database tables installed
     */
    function _is_installed($table_name)
    {
      $sql = $this->wpdb->prepare("SHOW TABLES LIKE %s", $table_name);
      $result = $this->wpdb->get_var($sql) == $table_name; 
      if( $result == $table_name ) {
        return true;
      }
      // fall thru
      return false;
    }  


    /**
     * Creates the necessary database tables for the plugin. Also deals with 
     * plugin specific database upgrades if needed
     * 
     * @access public
     * @return void
     */
    function setup_database()
    {
      $queries = array();
      
      // dealing with colation and character sets. Derived from /wp-admin/wp-includes/schema.php
      $charset_collate = '';
      if ( ! empty($this->wpdb->charset) ) {
        $charset_collate = "DEFAULT CHARACTER SET " . $this->wpdb->charset;
      }
      if ( ! empty($this->wpdb->collate) ) {
        $charset_collate .= " COLLATE " . $this->wpdb->collate;
      }
 
      // check if the tables are already installed
      if( $this->_is_installed( $this->db['table_stream'] ) &&
        $this->_is_installed( $this->db['table_stream_item'] ) ) {
          // if there are already tables installed, check the version
          //  to see if and what we need to update

      } else {
        // no tables so we need to install using the latest sql
        $queries[] = "CREATE TABLE IF NOT EXISTS " . $this->db['table_stream'] . " (
          stream_id bigint(20) NOT NULL auto_increment,
          stream_name varchar(255) NOT NULL,
          stream_slug varchar(255) NOT NULL,
          stream_description text NOT NULL,
          stream_options text NOT NULL,
          stream_nr_items bigint(20) NOT NULL,
          PRIMARY KEY  (stream_id)
        ) $charset_collate;";

        $queries[] = "CREATE TABLE IF NOT EXISTS " . $this->db['table_stream_item'] . " (
          stream_id bigint(20) NOT NULL,
          post_id bigint(20) not null,
          user_id bigint(20) not null,
          blog_id bigint(20) NOT NULL,
          modify_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          KEY stream_id (stream_id),
          KEY post_id (post_id,blog_id)
        ) $charset_collate;";
             
       
      }
      // include dbDelta function 
      require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
      
      // for some unknown reason dbDelta refuses to work correctly with multiple queries at once
      // so for now I use a for loop to loop through the necessary queries
      foreach($queries as $q) { 
        dbDelta($q);
      }
    }

    
    /**
     * Add custom javascript scripts from our plugin
     * to the WordPress admin interface
     * 
     * @TODO Play nice: add check so the js will only
     * be included on ou plugin's page
     */
    function admin_js($hook_suffix)
    {
      wp_enqueue_script('bbagg-admin-js', $this->plugin_url . 'js/bbagg-admin.js');
    }



    /** 
     * Retrieves aggregated posts, including author name, blog name, blog url 
     * and nummer of comments made on the post in one array sorted by date/time/title 
     * limited by total number of posts allowed
     *
     * @access public
     * @param string stream name
     * @param int start default 0
     * @param int end default uses the setting made in the plugin options
     * @return array posts
     */
    function aggregate($stream_name, $start = 0, $end = null) 
    {
      // retrieve the current page permalink from which the aggregate function
      // is called from. We use this for the paginate functionality
      $absolute_url =  get_permalink();

      // retrieve the posts and sort them.
      $posts = $this->_sort_posts( $this->_get_posts($stream_name) );

      if( is_array($posts) ) {
        $total_nr_posts = sizeof($posts);
        
        if( is_null($end) ) { 
        // retrieve the stream's max number of posts
        $max = $this->stream->get_option( $this->stream->get_stream_id_by_name($stream_name), 'bbagg_option_max_nr_posts');
        $max_nr_posts = is_numeric($max) ? $max : $total_nr_posts; // default to all posts if no limit was given          
        }    
        
        $posts_per_page = $this->stream->get_option( $this->stream->get_stream_id_by_name($stream_name), 'bbagg_option_posts_per_page');
        $posts_per_page = is_numeric($posts_per_page) ? $posts_per_page : $total_nr_posts; // defaults to all posts

        $args = array( 
          'max_nr_posts'   => $max_nr_posts,
          'total_nr_posts' => $total_nr_posts,
          'posts_per_page' => $posts_per_page,
          'posts'          => $posts,
          'absolute_url'   => $absolute_url
        );
        
        return $this->_calculate_start_offset($args);
      } else {
        return -1;
      }
    } 



    /**
     * _calculate_start_offset  
     * based on the wp_query object calculates start and end of posts query
     * 
     * @param array $args 
     * @access protected
     * @return array of posts
     */
    function _calculate_start_offset($args = array() )
    {
      $defaults = array(
        'max_nr_posts'   => 0,
        'total_nr_posts' => 0,
        'posts_per_page' => 0,
        'posts'          => -1,
        'absolute_url'   => ''
      );

      $r        = wp_parse_args( $args, $defaults );
      extract( $r, EXTR_SKIP ); // extract the parameters into variables
      
      // get the current wp_query object
      global $wp_query;
      // check if the page query parameter is set
      // and retrieve the current page_nr if it has been set
      // this will overwrite the default page_nr which is 1
      $current_page_nr = 1;
      if( is_object($wp_query) ) {
        if( is_array($wp_query->query_vars) && array_key_exists('page', $wp_query->query_vars) ) {
          if( is_numeric($wp_query->query_vars['page']) ) {
            $current_page_nr = (int) $wp_query->query_vars['page'];
            $current_page_nr = ($current_page_nr < 0) ? 1 : $current_page_nr; // make sure the page nr is always 1 or bigger
          }
        }
      }
      // calculate the max nr of pages in total
      $total_nr_pages = ceil($total_nr_posts / $posts_per_page);

      // make sure the current_page_nr is smaller than the total nr pages
      $current_page_nr = ($current_page_nr < $total_nr_pages) ? $current_page_nr : $total_nr_pages;

      $offset = ($current_page_nr * $posts_per_page) - $posts_per_page;
      $offset = ($offset < 0) ? 0 : $offset; // make sure offset is not smaller than zero
      $length = $posts_per_page;

      $this->last_aggregated_pagination_data = array( 
        'total_nr_pages'  => $total_nr_pages, 
        'current_page_nr' => $current_page_nr,
        'absolute_url'    => $absolute_url
      );

      /* Help with debugging */
      //echo "Total nr pages: $total_nr_pages<br />\n";
      //echo "Current page nr: $current_page_nr<br />\n";
      //echo "Offset: $offset<br />\n";
      //echo "Length: $length<br />\n";
      
      if( is_array($posts) ) {
        return array_slice($posts, $offset, $length);
      } else {
        return array(); 
      } 
    }

   
    /**
     * paginate creates the paginate html 
     * 
     * @param array  
     * @access public
     * @return string html
     */
    function paginate($args = array())
    {
      $defaults = array(
        'class'         => 'bbagg_paginate_link', 
        'class_current' => 'selected',
        'html_before'   => '<li>',
        'html_after'    => '</li>'
      );

      $r        = wp_parse_args( $args, $defaults );
      extract( $r, EXTR_SKIP ); // extract the parameters into variables
      extract( $this->last_aggregated_pagination_data); 
      $link ='';
      for ($i = 1; $i <= $total_nr_pages; $i++) {
        $class_link = ($i == $current_page_nr) ? "class=\"$class $class_current\"" : "class=\"$class\""; 
        $url   = $absolute_url . $i; 
        $link .= $html_before . "<a href=\"$url\" $class_link>$i</a>" . $html_after;
      }
      echo $link;
    }  
    


    /**
     * Adds the options subpanel for the plugin
     * Called by WP action 'admin_menu' 
     *
     * @access public 
     * @return void
    */
    function admin_menu_link() 
    {
      // If you change this from add_options_page, MAKE SURE you change the filter_plugin_actions function (below) to
      // reflect the page filename (ie - options-general.php) of the page your plugin is under!
      add_options_page('bbAggregate', 'bbAggregate', 'manage_options', basename(__FILE__), array(&$this,'admin_options_page'));
      add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), array(&$this, 'filter_plugin_actions'), 10, 2 );
      $this->setup_metabox();
    }


    /**
     * Adds the Settings link to the plugins activate/deactivate page
     * Called by WP filter 'plugin_action_links'
     *
     * @param array links
     * @param file 
     * @return array links
    */
    function filter_plugin_actions($links, $file) 
    {
      // If your plugin is under a different top-level menu than 
      // Settiongs (IE - you changed the function above to something other than add_options_page)
      // Then you're going to want to change options-general.php below to the name of your top-level page
      $settings_link = '<a href="options-general.php?page=' . basename(__FILE__) . '">' . __('Settings') . '</a>';
      array_unshift( $links, $settings_link ); // before other links
      return $links;
    }


    /**
     * Show the available streams as a dropdown
     *
     * @access public
     * @param int stream id Selected stream (optional)
     * @param bool echo TRUE echo result, FALSE return the result 
     * @return string html
     **/
    function show_dropdown_streams($chosen_stream_id = null, $echo = false) 
    {
      $streams = $this->get_all_streams();
      if( is_array($streams) ) {
        $html = "<select name=\"bbagg_edit_stream_id\" id=\"bbagg_edit_stream_id\">\n";  
        $selected = ( is_null($chosen_stream_id) ) ? "selected='selected'" : '';
        $html .= "<option value=\"-1\">" .__('Select a stream to edit') . "</option>";
        foreach ($streams as $s) {
          if( is_object($s) ) {
          $selected = ($chosen_stream_id == $s->stream_id) ? "selected='selected'" : '';
          $html .= "<option $selected value=\"$s->stream_id\">$s->stream_name</option>\n";
          }
        }
        $html .= "</select>\n";
        $html .= "<input type=\"submit\" name =\"bbagg_edit_stream_gui\" value=\"" . __('Edit selected stream', $this->localization_domain) . "\">";
      }
      if($echo) {
        echo $html;
      } else {
        return $html;
      }
    }


    /**
     * Show all availabe streams as checkboxes
     *
     * @param array stream ids of streams which need to be selected
     * @param array stream ids of streams not be rendered
     * @param bool echo or return the html defaults to returning
     * @return string html
     */
    function show_checkboxes_streams($selected_streams = array(), $excluded_streams = array(), $echo = false)
    {
      $streams = $this->get_all_streams();
      if( is_array($streams) ) {
        $html = '';
        foreach ($streams as $s){
          if( ! in_array($s->stream_id, $excluded_streams) ) {
            $checked = ( in_array($s->stream_id, $selected_streams) ) ? 'checked="checked"' : '';
            $html .= "<input type=\"checkbox\" value=\"$s->stream_id\" name=\"bbagg_stream_ids[]\" $checked id=\"bbagg_stream_id_$s->stream_id\" />";
            $html .= " <label for=\"bbagg_stream_id_$s->stream_id\">" . esc_html($s->stream_name) .' (' . esc_html($s->stream_nr_items) .')' . "</label><br />\n";
          }
        }
      }
      if($echo) {
        echo $html;
      } else {
        return $html;
      }  
    }

    /**
     * Check if there are streams available
     *
     * @access public
     * @return bool true on streams available, false on none
     */
    function has_streams()
    {
      $nr_streams = $this->stream->nr_streams_available();
      if( is_numeric($nr_streams) && $nr_streams > 0) {
        return true;
      }
      // fall thru 
      return false;
    } 



    /**
     * _get_site_blogs implements a custom get_blog_list which is deprecated in 
     * WordPress 3.
     * 
     * @param int $start defaults to zero 
     * @param string|int $num defaults to 'all' 
     * @access protected
     * @return array with per blog an array with the blogs id, domain and path
     */
    function _get_site_blogs($start = 0, $num = 'all')
    {
      // sets the time to live for the cache / transients
      $cache_time = 60*60*24; // sets the cache for 24 hrs 

      // check if we have a cached result otherwise retrieve the results from the database
      if(false === ($blogs = get_site_transient('bbagg_blogs') ) ) {
        // prepare query
        $query = $this->wpdb->prepare("SELECT blog_id, domain, path FROM {$this->wpdb->blogs} 
          WHERE site_id = %d 
          AND public = '1' 
          AND archived = '0' 
          AND mature = '0' 
          AND spam = '0' 
          AND deleted = '0' 
          ORDER BY registered DESC", $this->wpdb->siteid
        );
        // retrieve the results from the database
        $blogs = $this->wpdb->get_results($query, ARRAY_A );
        // if we have some results cache them so we can use the cache  
        if(is_array($blogs) && sizeof($blogs) > 0) {
          set_site_transient('bbagg_blogs', $blogs, $cache_time);
        }
      }
      
      // setup the array similar to the deprecated get_blog_list function
      foreach ( (array) $blogs as $details ) {
        $blog_list[ $details['blog_id'] ] = $details;
      }
      unset( $blogs );
      $blogs = $blog_list;

      // make sure we always return an array
      if ( false == is_array( $blogs ) ) {
        return array();
      } elseif ( $num == 'all' ) {
        return array_slice( $blogs, $start, count( $blogs ) );
      } else {
        return array_slice( $blogs, $start, $num );
      }
    }


    /**
     * _clear_blogs_transient removes the bbagg_blogs transient
     * upon a blog options change, blog addition or blog removal
     * 
     * @access protected
     * @return void
     */
    function _clear_blogs_transient()
    {
      if(false !== get_site_transient('bbagg_blogs') ) {
        delete_site_transient('bbagg_blogs');
      }
    }



    /**
     * Show all public blogs as checkboxes
     *
     * @access public
     * @param array blog ids OPTIONAL
     * @param bool echo on true or return value on false (default)
     * @return string html checkboxes
     */
    function show_checkboxes_site_blogs($selected_blogs = array(), $echo = false)
    {
      $site_blogs = $this->_get_site_blogs();
      if( is_array($site_blogs) ) {
        $html = '';
        foreach ($site_blogs as $blog){
          $checked = '';
          if( is_array($blog) && is_array($selected_blogs) ) {
            $checked = ( in_array($blog['blog_id'], $selected_blogs) ) ? 'checked="checked"' : '';
          }
          $html .= "<input type=\"checkbox\" value=\"{$blog['blog_id']}\" name=\"bbagg_site_blogs[]\" $checked id=\"bbagg_site_blogs\" />
            <span> {$blog['domain']}{$blog['path']}</span><br />\n";
        }
      }
      if($echo) {
        echo $html;
      } else {
        return $html;
      }  
    }

    /**
     * Adds settings/options page for the plugin. This is
     * the main dispatcher dealing with streams and their settings
     * called by WordPress add_options_page function
     *
     * @access public
     */
    function admin_options_page() 
    {
      
      // continue with a dispatcher like control structure.   
      // this takes care of routing requests to the correct
      // function and shows the correct gui
      if( isset($_POST['bbagg_new_stream_gui']) ) {
        
        // using nonce to make sure the forms originate from our
        // plugin. AFAIK there's no need to use different nonces per 
        // action, so I'll use only one for all operations.
        if ( ! wp_verify_nonce($_POST['bbagg_wpnonce'], 'bbagg-actions') ) { 
            die( __('Whoops! There was a problem with the data you posted. Please go back and try again.', $this->localization_domain) ) ; 
        }

        // show the 'add new stream' gui
        // make sure the entered stream name is unique and not empty
        // or else show an error message
        if( ($stream_ok = $this->is_stream_name_ok($_POST['bbagg_stream_name']) ) === 1 ) {
          $this->_render_stream_gui($_POST['bbagg_stream_name']);
        } else {
          echo $this->_retrieve_error($stream_ok); 
        }

      } elseif( isset($_POST['bbagg_edit_stream_gui']) ) {

        // using nonce to make sure the forms originate from our
        // plugin. AFAIK there's no need to use different nonces per 
        // action, so I'll use only one for all operations.
        if ( ! wp_verify_nonce($_POST['bbagg_wpnonce'], 'bbagg-actions') ) { 
            die( __('Whoops! There was a problem with the data you posted. Please go back and try again.', $this->localization_domain) ) ; 
        }

        // show the 'edit stream' gui
        // use the stream_id to get the requested stream data
        // make sure the id is a number higher than zero
        if( isset($_POST['bbagg_edit_stream_id']) && is_numeric($_POST['bbagg_edit_stream_id']) && $_POST['bbagg_edit_stream_id'] > 0 ) {
          $this->_render_stream_gui($_POST['bbagg_edit_stream_id'], 'edit');
        } else {
          $msg = __('<div class="error fade"><p>Not a valid stream. Perhaps you have no streams to edit yet?</p></div>', $this->localization_domain);
          $this->_render_default_gui($msg, true);
        }
      } elseif( isset($_POST['bbagg_remove_stream']) ) {

        // using nonce to make sure the forms originate from our
        // plugin. AFAIK there's no need to use different nonces per 
        // action, so I'll use only one for all operations.
        if ( ! wp_verify_nonce($_POST['bbagg_wpnonce'], 'bbagg-actions') ) { 
            die( __('Whoops! There was a problem with the data you posted. Please go back and try again.', $this->localization_domain) ) ; 
        }

        // remove one or more streams based on their id
        if( $this->remove_stream($_POST['bbagg_stream_ids']) ) {
          $msg = __('<div class="updated fade"><p>Successfully removed stream(s)</p></div>', $this->localization_domain);
        } else {
          $msg = __('<div class="error fade"><p>Could not remove stream(s)</p></div>', $this->localization_domain);
        }
        // return to the default gui and notify the user
        $this->_render_default_gui($msg, true);
      } elseif( isset($_POST['bbagg_save_stream']) ) {
        
        // process and save the new stream
        // check if the name is unique and not empty 
        if( ($stream_ok = $this->is_stream_name_ok($_POST['bbagg_stream_name']) ) === 1 ) {
          
          // process options into an array, will be un/serialized in Stream class
          $options = array (
            'bbagg_option_posts_per_page' => (int) $_POST['bbagg_option_posts_per_page'], 
            'bbagg_option_max_nr_posts_per_blog' => (int) $_POST['bbagg_option_max_nr_posts_per_blog'],
            'bbagg_option_max_nr_posts'   => (int) $_POST['bbagg_option_max_nr_posts'],  
            'bbagg_site_blogs'  => (array) isset($_POST['bbagg_site_blogs']) ? $_POST['bbagg_site_blogs'] : array() // @todo BjornW: fix this, not it will result in an undefined index 
          );
          
          
          $new_stream = array (
            'stream_name' => $_POST['bbagg_stream_name'],
            'stream_slug'        => $this->sanitize( $_POST['bbagg_stream_name'] ),
            'stream_description' => $_POST['bbagg_stream_description'],
            'stream_options'     => $options);
          
          if($this->add_stream($new_stream) === true) {
            $msg = sprintf( __('<div class="updated fade" id="bbagg-message"><p>The stream <em>%s</em> was successfully created!</p></div>', 
              $this->localization_domain), $_POST['bbagg_stream_name'] );
          }
          
        } else {
            $msg = $this->_retrieve_error($stream_ok); 
        }
        // return to the default menu and notify the user on their actions
        $this->_render_default_gui($msg, true);
      } elseif( isset($_POST['bbagg_update_stream']) ) {
        // process and update an existing stream
        $stream_ok = 1;        
        // check if the name has changed, if it did make sure 
        // the new name is unique, if not the value will be a negative int
        if($this->has_stream_name_changed( (int) $_POST['bbagg_update_stream_id'], $_POST['bbagg_stream_name'] ) ) {
          $stream_ok = $this->is_stream_name_ok($_POST['bbagg_stream_name']);
        }

        if($stream_ok === 1) {
          // process options into an array, will be un/serialized in Stream class
          $options = array (
            'bbagg_option_posts_per_page'        => (int) $_POST['bbagg_option_posts_per_page'], 
            'bbagg_option_max_nr_posts_per_blog' => (int) $_POST['bbagg_option_max_nr_posts_per_blog'],
            'bbagg_option_max_nr_posts'          => (int) $_POST['bbagg_option_max_nr_posts'],  
            'bbagg_site_blogs'                   => (array) $_POST['bbagg_site_blogs']
          );
          
          $new_stream = array (
            'stream_id'          => (int) $_POST['bbagg_update_stream_id'],
            'stream_name'        => $_POST['bbagg_stream_name'],
            'stream_slug'        => $this->sanitize( $_POST['bbagg_stream_name'] ),
            'stream_description' => $_POST['bbagg_stream_description'],
            'stream_options'     => $options);
          
          if($this->update_stream($new_stream) === true) {
            $msg = sprintf( __('<div class="updated fade" id="bbagg-message"><p>The stream <em>%s</em> was successfully updated!</p></div>', 
              $this->localization_domain), $_POST['bbagg_stream_name'] );
          } 
          
        } else {
          $msg = $this->_retrieve_error($stream_ok);
        }
        // return to the default menu and notify the user on their actions
        $this->_render_default_gui($msg, true);
      } else {
        // show the default options gui
        $this->_render_default_gui($msg = '', true);
      }

    }

    
    /** 
     * Renders the default gui for the plugin
     *
     * @access public
     * @param string optional message defaults to empty 
     * @param bool echo or return html defaults to returning
     */
    function _render_default_gui($msg = '', $echo = false)
    {
      // build the options page 
      $html = $msg; 
      $html .= "<div class=\"wrap\">\n";
      $html .= "<h2>bbAggregate</h2>\n";
      $html .= "<form method=\"post\" id=\"bbagg_options\">";
      $html .= wp_nonce_field('bbagg-actions', $name = 'bbagg_wpnonce', true, false);
      $html .= "<table width=\"100%\" cellspacing=\"2\" cellpadding=\"5\" class=\"form-table\">\n"; 
      
      $html .= "<tr valign=\"top\">\n"; 
      $html .= "\t<th width=\"33%\" scope=\"row\">" .  __('Create a new stream:', $this->localization_domain) . "</th>\n"; 
      $html .= "\t<td><input type=\"text\" size=\"45\" maxlength=\"255\" name=\"bbagg_stream_name\" id=\"bbagg_stream_name\" /> ";
      $html .= "\t<input name=\"bbagg_new_stream_gui\" type=\"submit\" id=\"bbagg_new_stream_gui\" value=\"" . __('Add a new stream') . "\" /></td>\n"; 
      $html .= "</tr>\n";
      
      $html .= "<tr valign=\"top\">\n"; 
      $html .= "\t<th width=\"33%\" scope=\"row\">" .  __('Edit an existing stream:', $this->localization_domain) . "</th>\n"; 
      $html .= "\t<td>" . $this->show_dropdown_streams() ."</td>\n"; 
      $html .= "</tr>\n";

      // only show if we have some streams
      if( $this->has_streams() ) {
        $html .= "<tr valign=\"top\">\n"; 
        $html .= "\t<th width=\"33%\" scope=\"row\">" .  __('Remove one or more existing streams:', $this->localization_domain) . "</th>\n"; 
        $html .= "\t<td>" . $this->show_checkboxes_streams(); 
        $html .= "\t<input name=\"bbagg_remove_stream\" type=\"submit\" id=\"bbagg_remove_stream\" value=\"" . __('Remove selected streams') . "\" />\n"; 
        $html .= "</td>\n";
      } 
      
      $html .= "</tr>\n";
      $html .= "</table>\n";
      $html .= "</form>\n";
      
      if($echo) {
        echo $html;
      } else {
        return $html;
      }
    }  



    /**
     * Renders (echo) the new/edit stream interface. 
     *
     * @param string|array stream name or stream as array
     * @param string required action defaults to new
     * 
     */  
    function _render_stream_gui($stream, $action = 'new')
    {
      if( is_numeric($stream) && $action == 'edit' ) {
        if($stream < 0) { return; } // do nothing on values lower than zero
        $stream_data = $this->stream->get($stream);
        if( is_array($stream_data) && sizeof($stream_data) == 1 ) {
          if( is_object($stream_data[0]) ) {
            $stream_name        = $stream_data[0]->stream_name;
            $stream_description = $stream_data[0]->stream_description;
            $stream_options     = $stream_data[0]->stream_options;
            extract($stream_options);
          }
        } 
        $btn = "\t<th colspan=2><input type=\"hidden\" name=\"bbagg_update_stream_id\" value=\"" . (int) $stream . " \" />";
        $btn .="<input type=\"submit\" name=\"bbagg_update_stream\" value=\"" . __('Update stream', $this->localization_domain) . "\"/></th>\n";
      } else {
        $stream_name = $stream;
        $stream_description = '';
        // get the sitwide defaults
        extract($this->options);
        $btn = "\t<th colspan=2><input type=\"submit\" name=\"bbagg_save_stream\" value=\"" . __('Save new stream', $this->localization_domain) . "\"/></th>\n";
      } 

      $html = '';
      $html .= "<div class=\"wrap\">\n";
      $html .= "<h2>bbAggregate</h2>\n";
      $html .= "<form method=\"post\" id=\"bbagg_options\">";
      $html .= wp_nonce_field('bbagg-add-stream', $name = 'bbagg_wpnonce', $referer = true, $echo = false);
      $html .= "<table width=\"100%\" cellspacing=\"2\" cellpadding=\"5\" class=\"form-table\">\n"; 
      
      $html .= "<tr valign=\"top\">\n"; 
      $html .= "\t<th width=\"33%\" scope=\"row\">" .  __('Stream name:', $this->localization_domain) . "</th>\n"; 
      $html .= "\t<td><input name=\"bbagg_stream_name\" type=\"text\" maxlength=\"255\" size=\"45\" id=\"bbagg_stream_name\" value=\"$stream_name\" /></td>\n"; 
      $html .= "</tr>\n";
      
      $html .= "<tr valign=\"top\">\n"; 
      $html .= "\t<th width=\"33%\" scope=\"row\">" .  __('Stream description:', $this->localization_domain) . "</th>\n"; 
      $html .= "\t<td><textarea name=\"bbagg_stream_description\" rows=\"10\" cols=\"42\" id=\"bbagg_stream_description\">$stream_description</textarea></td>\n"; 
      $html .= "</tr>\n";
     
      $html .= "<tr valign=\"top\">\n"; 
      $html .= "\t<th width=\"33%\" colspan=\"2\" scope=\"row\"><strong>" .  __('Stream Options', $this->localization_domain) . "</strong></th>\n"; 
      $html .= "</tr>\n";


      $html .= "<tr valign=\"top\">\n"; 
      $html .= "\t<th width=\"33%\" scope=\"row\">" .  __('Maximum number of posts in this stream:', $this->localization_domain) . "</th>\n"; 
      $html .= "\t<td><input name=\"bbagg_option_max_nr_posts\" type=\"text\" id=\"bbagg_option_max_nr_posts\" size=\"45\" value=\"$bbagg_option_max_nr_posts\" /></td>\n"; 
      $html .= "</tr>\n";

      $html .= "<tr valign=\"top\">\n"; 
      $html .= "\t<th width=\"33%\" scope=\"row\">" .  __('Maximum number of posts per blog in this stream:', $this->localization_domain) . "</th>\n"; 
      $html .= "\t<td><input name=\"bbagg_option_max_nr_posts_per_blog\" type=\"text\" id=\"bbagg_option_max_nr_posts_per_blog\" size=\"45\" value=\"$bbagg_option_max_nr_posts_per_blog\" /></td>\n"; 
      $html .= "</tr>\n";

      $html .= "<tr valign=\"top\">\n"; 
      $html .= "\t<th width=\"33%\" scope=\"row\">" .  __('Number of posts per page:', $this->localization_domain) . "</th>\n"; 
      $html .= "\t<td><input name=\"bbagg_option_posts_per_page\" type=\"text\" id=\"bbagg_option_posts_per_page\" size=\"45\" value=\"$bbagg_option_posts_per_page\" /></td>\n"; 
      $html .= "</tr>\n";

      $html .= "<tr valign=\"top\">\n";
      $html .= "\t<th width=\"33%\" scope=\"row\">" . __('Exclude these blogs from aggregating in this stream:', $this->localization_domain) . "</th>\n"; 
      $html .= "\t<td>" . $this->show_checkboxes_site_blogs($bbagg_site_blogs) . "</td>\n"; 
      $html .= "</tr>\n";

      $html .= "<tr>\n";
      $html .= $btn;
      $html .= "</tr>\n";

      $html .= "</tr>\n";
      $html .= "</table>\n";
      $html .= "</form>\n";
      echo $html;
    }



    
    /**
     * Renders a gui for the sitewide default options of the plugin.
     * Displayed at /wp-admin/ms-options.php 
     * Called by wpmu_options action
     *
     * @access public
     * @return string html
     */
    function network_options_gui()
    {
      // get the defaults from an array
      extract($this->options);

     // build the options page  
      $html  = '';
      $html .= "<h3>bbAggregate sitewide defaults</h3>\n";
      $html .= wp_nonce_field('bbagg-update-options', $name = 'bbagg_wpnonce', $referer = true, $echo = false);
      $html .= "<table width=\"100%\" cellspacing=\"2\" cellpadding=\"5\" class=\"form-table\">\n"; 
      
      $html .= "<tr valign=\"top\">\n"; 
      $html .= "\t<th width=\"33%\" scope=\"row\">" .  __('Maximum total of posts to aggregate:', $this->localization_domain) . "</th>\n"; 
      $html .= "\t<td><input name=\"bbagg_option_max_nr_posts\" type=\"text\" id=\"bbagg_option_max_nr_posts\" size=\"45\" value=\"$bbagg_option_max_nr_posts\" /></td>\n"; 
      $html .= "</tr>\n";

      $html .= "<tr valign=\"top\">\n"; 
      $html .= "\t<th width=\"33%\" scope=\"row\">" .  __('Maximum number of posts per blog aggregated:', $this->localization_domain) . "</th>\n"; 
      $html .= "\t<td><input name=\"bbagg_option_max_nr_posts_per_blog\" type=\"text\" id=\"bbagg_option_max_nr_posts_per_blog\" size=\"45\" value=\"$bbagg_option_max_nr_posts_per_blog\" /></td>\n"; 
      $html .= "</tr>\n";

      $html .= "<tr valign=\"top\">\n"; 
      $html .= "\t<th width=\"33%\" scope=\"row\">" .  __('Show number of posts at a time (used for pagination) :', $this->localization_domain) . "</th>\n"; 
      $html .= "\t<td><input name=\"bbagg_option_posts_per_page\" type=\"text\" id=\"bbagg_option_posts_per_page\" size=\"45\" value=\"$bbagg_option_posts_per_page\" /></td>\n"; 
      $html .= "</tr>\n";

      $html .= "<tr valign=\"top\">\n";
      $html .= "\t<th width=\"33%\" scope=\"row\">" . __('Exclude blogs from aggregating:', $this->localization_domain) . "</th>\n"; 
      $html .= "\t<td>" . $this->show_checkboxes_site_blogs($bbagg_site_blogs) . "</td>\n"; 
      $html .= "</tr>\n";

      $html .= "</table>\n";
      // show the built page
      echo $html; 
    }

    

    /**
     * Retrieves the plugin options from the database.
     *
     * @access public
     * @return options array
    */
    function get_sitewide_options() 
    {
      // don't forget to set up the default options
      if ( ! $the_options = get_site_option( $this->options_name) ) {
        // defaults
        $the_options = array(
          'bbagg_version'                      => $this->bbagg_version,
          'bbagg_option_max_nr_posts_per_blog' => 20, 
          'bbagg_option_max_nr_posts'          => 250,
          'bbagg_option_posts_per_page'        => 10,
          'bbagg_site_blogs'                   => array()
        );

        update_site_option($this->options_name, $the_options);
      }
      $this->options = $the_options;
    }

    /**
     * Saves/updates the sitewide options
     *
     * @param mixed data
     * @return void
     */
    function network_options_save() 
    {
      $options = array (
        'bbagg_option_posts_per_page'        => (int) $_POST['bbagg_option_posts_per_page'], 
        'bbagg_option_max_nr_posts_per_blog' => (int) $_POST['bbagg_option_max_nr_posts_per_blog'],
        'bbagg_option_max_nr_posts'          => (int) $_POST['bbagg_option_max_nr_posts'],  
        'bbagg_site_blogs'                   => (array) $_POST['bbagg_site_blogs']
      );
      update_site_option($this->options_name, $options);
    }


    /** 
     * Wraper for Stream object to add a new stream
     * to the database 
     *
     * @access public
     * @param array stream data as an associative array
     * @return int|false Number of rows affected/selected or false on error
     */
    function add_stream($stream) 
    {
      return $this->stream->save($stream);      
    } 


    /** 
     * Wrapper for Stream object. 
     * Updates an existing stream, needs a stream id otherwise
     * a new stream will be created
     *
     * @access public
     * @param array stream data as an associative array
     *
     */
    function update_stream($stream)
    {
      return $this->stream->save($stream);
    }


    /**
     * Removes a stream and all its items 
     * from the database. 
     *
     * @access 
     * @param int|array one stream id or multiple stream ids in an array
     * @return 
     */
    function remove_stream($stream)
    {
      if( is_numeric($stream) ) {
        $stream = array( (int) $stream);
      }

      if( is_array($stream) ) {
        foreach($stream as $stream_id) {
          $this->stream_item->remove_all_items($stream_id);
          $this->stream->remove($stream);
        }
      }
      return true;
    }


    function get_all_streams() 
    {
      return $this->stream->get('all');
      
    }

    /** 
     * Check if a stream name has changed 
     * based on it's stream id and the name 
     *
     * @access public
     * @param int stream id
     * @param string stream name
     * @return bool true on the name has changed false on the not changed
     */  
    function has_stream_name_changed($stream_id, $stream_name) 
    {
      $stream = $this->stream->get($stream_id);
      if(is_array($stream) && sizeof($stream) == 1) {
        if($stream[0]->stream_name == $stream_name) {
          return false;
        }
      } 
      // fall thru 
      return true;
    }


    /**
     * Checks if the given stream name is not empty 
     * and unique. 
     *
     * @acccess public
     * @param string stream name
     * @result 
     */
    function is_stream_name_ok($stream_name) 
    {
      $result = 1;
      if( empty($stream_name) ) {
        $result = -1;
      } elseif ( ! $this->stream->is_unique_stream( $this->sanitize($stream_name) ) ) {
        $result = -2;
      }
      return $result;
    }


    /** 
     * Removes the item from one or more streams and lowers the item count
     * on the streams of which the item was removed.
     *
     * @access public
     * @param int post_id
     * @param int blog_id
     *
     *
     */
    function remove_item($post_id, $blog_id) 
    {
      // first get all stream ids where the item is being used
      $stream_ids = $this->get_item_streams($post_id, $blog_id);
      
      // if the stream ids array is bigger than zero the item is in use
      // with at least one stream and thus can be removed, otherwise the item 
      // is not used by any streams and thus does not exist
      if( is_array($stream_ids) && sizeof($stream_ids) > 0 ){
        // then remove the item
        $removed = $this->stream_item->remove($post_id, $blog_id);
        // now lower the item count of the streams the item was part of
        foreach($stream_ids as $stream_id) {
          $this->stream->set_item_count($stream_id, '-');
        }
      }
    }

    /** 
     * Adds an item to a stream
     *
     * @param int post_id
     * @param int blog_id
     * @param int stream_id
     * @return bool true on success, false on failure  
     */
    function save_item($post_id, $blog_id, $stream_id, $user_id) 
    {
      // save the item
      if( $this->stream_item->save($post_id, $blog_id, $stream_id, $user_id) !== FALSE) {
        // item saved successfully, add another count to the nr of items 
        // of the stream in question
        if( $this->stream->set_item_count($stream_id, '+') !== FALSE) {
          return true;
        }
      }
      return false;
    }


    /**
     * Retrieve the stream ids from a specific item based on
     * item's post_id and blog_id
     *
     * @access public
     * @param int post_id
     * @param int blog_id
     * @return array with stream ids or empty array
     */
    function get_item_streams($post_id, $blog_id) 
    {
      $stream_ids = array();
      $results = $this->stream_item->get($post_id, $blog_id);
      if( is_array($results) ) {
        foreach($results as $item) {
          if( is_object($item) ) {
            $stream_ids[] = $item->stream_id;
          }
        }
      }
      return $stream_ids;
    }



    /**
     * Checks if a given blog id is part
     * of the excluded list of blogs from a stream
     * 
     * @param int $blog_id 
     * @access protected
     * @return bool true on blog is excluded or false if blog is not excluded
     */
    function _is_blog_excluded($blog_id, $stream_id)
    {
      // get all the blogs we want to exclude
      $excluded_blogs = $this->stream->get_option($stream_id, 'bbagg_site_blogs');
      
      if( is_array($excluded_blogs) && sizeof($excluded_blogs) > 0 ) { 
        if( in_array($blog_id, $excluded_blogs) ) {
          return true;
        }
      }
      // fall thru
      return false;
    }


    /**
     * Checks if a given blog_id is still valid 
     * 
     * @param int $blog_id 
     * @access protected
     * @return bool true if the blog_id is still active otherwise false
     */
    function _is_blog_active($blog_id) 
    {
      $q = $this->wpdb->prepare("SELECT blog_id FROM {$this->wpdb->blogs} 
        WHERE site_id = %d 
        AND blog_id = %d
        AND public = '1' 
        AND archived = '0' 
        AND mature = '0' 
        AND spam = '0' 
        AND deleted = '0'
        LIMIT 1", $this->wpdb->siteid, $blog_id
      );

      $result = $this->wpdb->get_var($q);
      if($result == $blog_id) { 
        return true;
      }
      // fall thru 
      return false;
    }


    /** 
     * Retrieves the posts from all public and active blogs
     * in the category set to be aggregated using the options
     * set in the settings of the plugin. 
     *
     * @access private
     * @param string stream name
     * @param bool true if post content needs to be processed else false, defaults true
     * @return array posts or -1 on error 
     */ 
    function _get_posts($stream_name, $process_content = true) 
    {
      // 1. get stream by name
      // 2. get all post ids per blog
      $stream_id = $this->stream->get_stream_id_by_name($stream_name);       
      $aggregated_posts = array();
      if( is_numeric($stream_id) ) {
        $posts = $this->stream_item->get_all($stream_id);
        if( is_array($posts) ) {
          foreach($posts as $post) {
            if( is_object($post) ) {
              // check if the excluded blogs list 
              if( ! $this->_is_blog_excluded($post->blog_id, $stream_id) && $this->_is_blog_active($post->blog_id) ) {
                switch_to_blog($post->blog_id);
                $blog_name = get_bloginfo('name');
                $blog_url  = get_bloginfo('url');
                $post_data = get_post($post->post_id);
                if( is_object($post_data) ) {
                  $post_data->blog_name   = $blog_name;
                  $post_data->blog_url    = $blog_url;
                  $post_data->nr_comments = $this->_get_nr_comments($post->blog_id, $post->post_id);
                  $post_data->author_name = $this->_get_author_name($post_data->post_author);
                  if($process_content) {
                   $post_data->post_content = $this->process_post_content($post_data->post_content, $post->post_id, $post->blog_id);
                  }                
                  $key = $post_data->post_date . ' ' . $post_data->post_title;
                  $aggregated_posts[ $key ] = $post_data;
                }
                restore_current_blog();
              }
            }
          }
        }
        return $this->_sort_posts($aggregated_posts);
      }
      
      // fall thru
      return -1;
    }


    /**
     * Sort posts. For now we use a reversed natural sort on the
     * array keys, so the last created post is first. Might change in
     * the future
     *
     * @access private
     * @param array post objects. 
     */
    function _sort_posts( $posts = array() ) 
    {
      // sort the posts so the oldest post is last 
      return $this->natkrsort($posts);
    } 


    /** 
     * Retrieve author name from wp_users based on post_author
     *
     * @access private
     * @param int user id
     * @return string user displayname or int -1 on error 
     */
    function _get_author_name($author_id) 
    {
      if( ! is_numeric($author_id) ) { return -1; }
        
      $q = sprintf("SELECT display_name FROM %s WHERE ID = '%s' LIMIT 1", $this->wpdb->users, $author_id);
      $post_author = $this->wpdb->get_var($q);
      return $post_author;
    }


    /** 
     * Retrieve number of comments based in the blog and post id
     *
     * @access private
     * @param int blog id
     * @param int post id
     * @return int number of comments or -1 on error 
     */ 
    function _get_nr_comments($blog_id, $post_id) 
    {
      if( ! is_numeric($blog_id) || ! is_numeric($post_id) ) { return -1; }
      switch_to_blog($blog_id);
      $q = sprintf("SELECT COUNT(*) FROM %s WHERE comment_post_ID = '%s'", $this->wpdb->comments, $post_id);
      $nr_post_comments = $this->wpdb->get_var($q); 
      restore_current_blog();
      return $nr_post_comments;
    }
      
    
    /**
     * Sort an array with case insensitive natural key sorting 
     * thanks to http://nl3.php.net/manual/en/function.krsort.php#55577
     * 
     * @access public
     * @param array 
     * @return array sorted or empty array on error
     */
    function natkrsort($array)
    {
      // prevent errors,
      if( ! is_array($array) ) { 
        return array(); 
      }

      $keys = array_keys($array);
      natcasesort($keys);
      
      $new_array = array();
      foreach ($keys as $k) {
        $new_array[$k] = $array[$k];
      }
  
      $new_array = array_reverse($new_array, true);

      return $new_array;
    }

    
    
    /** 
     * Wrapper for WordPress sanitize_title_with_dashes function
     * Sanitizes a string replacing whitespace with dashes. 
     * Limits the output to alphanumeric characters, underscore (_) and dash (-).
     * Whitespace becomes a dash.
     *
     * @access public
     * @param string
     * @return string  
     */
    function sanitize($string) 
    {
      return sanitize_title_with_dashes($string);
    }


    /**
     * this is a hack to make the post content and more tag behave. 
     * The reason for having this function is to have more control over
     * the output of the more url and more text. 
     * It is modeled after the IMHO not to so useful (due to the use of globals!) Wordpress
     * the_content function in wp-includes/post-template.php 
     *
     * @access public
     * @param string content
     * @param numeric post id
     * @param numeric blog id
     * @param array optional 
     * @return string transformed content
     */
    function process_post_content($content, $post_id, $blog_id, $args = '') 
    {
      $defaults = array('more_link_text'     => __('Lees verder', $this->localization_domain), 
                        'more_link_title'    =>  __('Lees verder', $this->localization_domain),
                        'class'              => 'more-link',
                        'override_permalink' => null, 
                        'post_object'        => null);

      $r        = wp_parse_args( $args, $defaults );
      extract( $r, EXTR_SKIP ); // extract the parameters into variables

      // set initial values 
      // make sure we at least get some output back
      // it will be overwritten if needed..
      $output   = $content;
      
      // switch to the blog needed, useful for permalinks and such
      switch_to_blog($blog_id);

      // get the more text if there is any..
      if ( preg_match('/<!--more(.*?)?-->/', $content, $matches) ) {
        $content = explode($matches[0], $content, 2);
        if ( ! empty($matches[1]) && ! empty($more_link_text) ) {
          $more_link_text = strip_tags(wp_kses_no_null(trim($matches[1]) ) );
        }
      } else {
        $content = array($content); // make sure we have an array
      }
      
      // unique id for css
      $css_id = "bid-$blog_id-pid-$post_id";

      if ( count($content) > 1 ) {
        $output  = $content[0]; // clear the output and only add the first part (content before the more tag)
        if ( $more ) {
          $output .= '<span id="more-' . $css_id . '"></span>' . $content[1];
        } else {
          if ( ! empty($more_link_text) ) {
            if( ! is_null($override_permalink) ) {
              $output .= apply_filters( 'the_content_more_link', ' <a href="' . $override_permalink . "#more-$css_id\" title=\"$more_link_title\" class=\"$class\">$more_link_text</a>", $more_link_text );
            } else {
              $output .= apply_filters( 'the_content_more_link', ' <a href="' . get_permalink($post_id) . "#more-$css_id\" title=\"$more_link_title\" class=\"$class\">$more_link_text</a>", $more_link_text );
            }
            $output = force_balance_tags($output);
          }
        }
      }
      // clean up and get ready to output the content, we even allow other plugins to mess with the content 
      // by using the the_content filter
      $output = apply_filters('the_content', $output);
      $output = str_replace(']]>', ']]&gt;', $output);

      // restore to the previous blog
      restore_current_blog();
      return $output;
    }





    /** 
     * Returns error specific messages for the user
     * based on an error code 
     *
     * @access private
     * @param int (negative) error code
     * @return string html message
     */
    function _retrieve_error($error_code)
    {
      switch ($error_code) {
        case -1:
          $result = '<div class="error"><p>' . __('You forgot to enter a stream name!', $this->localization_domain) . '</p></div>';
          break;
        
        case -2:
          $result = '<div class="error"><p>' . __('Sorry, the stream name you chose already exists. Please use a different name.', $this->localization_domain) . '</p></div>';
          break;
        
        case -3:
          // 
          break;

        default:
          $result = __('Whoops something weird happened. Sorry about that', $this->localization_domain);  

      }
      return $result;
    }
  
    
    /**
     * uninstall removes the sitewide options and the database tables 
     * used by this plugin 
     * 
     * @access public
     * @return void
     */
    function uninstall()
    {
      // remove sitewide options
      remove_site_options($this->options_name);  

      // remove the database tables
      if(is_array($this->db) && sizeof($this->db) > 0 ) {
        foreach($this->db as $table) {
          $this->wpdb->query( $this->wpdb->prepare("DROP TABLE IF EXISTS '%s'", $table) );
        }
      } 
    }

  }
} 

// instantiate the class
if ( class_exists('bbAggregate') ) { 
  $bbagg_var = new bbAggregate();
  
  /** 
   * Makes live easier for themers
   */ 
  if( ! function_exists ('bbagg_aggregate') ) {
    function bbagg_aggregate($stream_name, $start = 0, $end = null)
    {
      global $bbagg_var;
      return $bbagg_var->aggregate($stream_name, $start, $end);
    }  
  }

  /** 
   * Makes live easier for themers
   */ 
  if( ! function_exists ('bbagg_paginate') ) {
    function bbagg_paginate( $args = array() )
    {
      global $bbagg_var;
      return $bbagg_var->paginate($args);
    }  
  }

}
?>
