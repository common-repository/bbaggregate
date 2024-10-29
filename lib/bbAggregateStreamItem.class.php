<?php

if ( ! class_exists('bbAggregateStreamItem') ) {
  class bbAggregateStreamItem {

    /**
     * @var WordPress database object
     */
    var $wpdb;

    /**
     * @var table name for all stream data
     */
    var $table_name;

    /**
     * PHP 4 Compatible Constructor
    */
    function bbAggregateStreamItem($wpdb){ $this->__construct($wpdb); }
    
    /**
     * PHP 5 Constructor
    */        
    function __construct($wpdb)
    {
      $this->wpdb = $wpdb;
      $this->table_name = $this->wpdb->base_prefix . 'bbAggregate_item';
    }


    /**
     * Remove an item from one or all streams it belongs to
     *
     * @access public
     * @param int post_id
     * @param int blog_id
     * @param int stream_id Optional, defaults to null
     * @return int|false Number of rows affected or false on error
     */ 
    function remove($post_id, $blog_id, $stream_id = null)
    {
      $sql = $this->wpdb->prepare("DELETE FROM $this->table_name WHERE post_id=%d AND blog_id=%d", $post_id, $blog_id);
      
      // if we only want to remove one specific item from one stream we need a stream_id
      if( ! is_null($stream_id) ) {
        $sql .= $this->wpdb->prepare(" AND stream_id=%d", $stream_id);
      }
      return $this->wpdb->query($sql);
    } 
    
    
    /**
     * Retrieve a specific item based on the post_id, blog_id and optionally 
     * the stream id. It allows you to seek an item and all of its occurences 
     * in multiple streams or one specific item in one specific stream.
     *
     * @acccess public
     * @param int post_id
     * @param int blog_id
     * @param int stream_id Optional, defaults to null
     * @return array|false array with results as objects or boolean false
     */ 
    function get($post_id, $blog_id, $stream_id = null)
    {
      
      $sql = $this->wpdb->prepare("SELECT * FROM $this->table_name WHERE post_id=%d AND blog_id=%d", $post_id, $blog_id);
      
      // if we only want one specific item from one specific stream we need a stream_id
      if( ! is_null($stream_id) ) {
        $sql .= $this->wpdb->prepare(" AND stream_id=%d", $stream_id);
      }
      return $this->wpdb->get_results($sql);
    }


    /** 
     * Save an item to the database
     *
     * @access public
     * @param int post_id
     * @param int blog_id
     * @param int stream_id
     * @return int|false Number of rows affected or false on error
     */
    function save($post_id, $blog_id, $stream_id, $user_id) 
    {
      $sql = $this->wpdb->prepare("INSERT INTO $this->table_name (stream_id, post_id, blog_id, user_id) VALUES(%d, %d, %d, %d)", 
        $stream_id, $post_id, $blog_id, $user_id);
      return $this->wpdb->query($sql);
    }    

    /**
     * Remove all items from a stream
     *
     * @access public
     * @param int stream_id
     * @return int|false Number of rows affected or false on error
     */
    function remove_all_items($stream_id) 
    {
      $sql = $this->wpdb->prepare("DELETE FROM $this->table_name WHERE stream_id=%d", $stream_id);
      return $this->wpdb->query($sql);
    }  


    /**
     * Get all posts based on the stream_id
     *
     * @param int stream_id
     * @return mixed Database query results
     */
    function get_all($stream_id)
    {
      $sql = $this->wpdb->prepare("SELECT * FROM $this->table_name WHERE stream_id=%d", $stream_id);
      return $this->wpdb->get_results($sql);
    }

    
    /**
     * Check if an item exists in a stream. This function allows
     * searching a specific item in a specific stream or 
     * searching a specific item regardless of the stream
     *
     * @access public
     * @param int post_id
     * @param int blog_id
     * @param int stream_id
     * @return bool true on item exists false on item does not exist
     */
    function does_item_exist($post_id, $blog_id, $stream_id = null)
    {
      $results = $this->get($post_id, $blog_id, $stream_id);
      if( is_array($results) && sizeof($results) > 0 ) {
        return true;
      }
      // fall thru
      return false;
    }

  }
}
?>
