<?php

if ( ! class_exists('bbAggregateStream') ) {
  class bbAggregateStream {

    /**
     * @var WordPress database object
     */
    var $wpdb;

    /**
     * @var table name for all stream data
     */
    var $table_name;

    /**
     * @var array with all allowed table columns
     */
    var $table_cols = array();


    /**
     * PHP 4 Compatible Constructor
    */
    function bbAggregateStream($wpdb){ $this->__construct($wpdb); }
    
    /**
     * PHP 5 Constructor
    */        
    function __construct($wpdb)
    {
      $this->wpdb = $wpdb;
      $this->table_name = $this->wpdb->base_prefix . 'bbAggregate_stream';
      $this->table_cols = array('stream_id', 'stream_name', 'stream_slug', 'stream_description', 'stream_options', 'stream_nr_items');
    }

    /**
     * Retrieves a stream from the database
     * 
     * @access public
     * @param int or array stream id Get data based on one stream id, multiple stream ids as an array or by default all streams 
     * @array stream database object
     */ 
    function get($stream) 
    {
      $deserialized_streams = array();

      // retrieve all existing streams from the database
      if( 'all' == $stream) {
        $sql = $this->wpdb->prepare("SELECT * FROM $this->table_name");
        $streams = $this->wpdb->get_results($sql); 
      } else {
        
        // if we receive one id
        // transform it into an array for easier processing
        if( is_numeric($stream) ) {
          $stream = array( (int) $stream);        
        } 

        // processing one or multiple stream ids
        if( is_array($stream) && sizeof($stream) > 0) {
          foreach ($stream as $stream_id) {
            $sql = $this->wpdb->prepare("SELECT * FROM $this->table_name WHERE stream_id = %d", $stream_id);
            $streams[] = $this->wpdb->get_row($sql);        
          }
        } 
      }
      // make sure the options are deserialized
      if(is_array($streams) && sizeof($streams) > 0 ) {
        foreach($streams as $stream_object) {
          if( is_object($stream_object) ) {
           $deserialized_streams[] = $this->_deserialize_options($stream_object);
          } 
        }
      }
      return $deserialized_streams;
    }

    /**
     * Removes one or more streams from the database
     *
     * @access public
     * @param int|array one or more stream ids 
     * @return array per stream id the affected rows
     */
    function remove($stream) 
    {
      if( 'all' == $stream) {
        $sql = $this->wpdb->prepare("DELETE FROM $this->table_name WHERE 1=1"); /* clears table */
        $affected_streams['all'] = $this->wpdb->query($sql);        
      } else {
      
        // if we receive one id
        // transform it into an array for easier processing
        if( is_numeric($stream) ) {
          $stream = array( (int) $stream);        
        } 

        // processing one or multiple stream ids
        if( is_array($stream) && sizeof($stream) > 0) {
          foreach ($stream as $stream_id) {
            $sql = $this->wpdb->prepare("DELETE FROM $this->table_name WHERE stream_id = %d", $stream_id);
            $affected_streams[$stream_id] = $this->wpdb->query($sql);        
          }
        } 
      }
      return $affected_streams;
    } 


    /**
     * Saves a stream to the database
     *
     * @access public
     * @param array stream information $stream['stream_name'], $stream['stream_description'], $stream['options']
     * @return int|false Number of rows affected/selected or false on error
     */
    function save($stream) 
    {
      if( ! is_array($stream) ) { return -1; }

      $defaults = array('stream_id' => null, 'stream_name' => '', 'stream_slug' => '', 'stream_description' => '', 'stream_options' => array() );
      $stream   = array_merge($defaults, $stream);
      
      // serialize the options
      $stream['stream_options'] = serialize( $stream['stream_options'] );

        
      // update if a stream id is supplied, otherwise insert a new stream in the database
      if( is_numeric($stream['stream_id']) ) {
        $sql = $this->wpdb->prepare("UPDATE $this->table_name SET stream_name=%s, stream_slug=%s, stream_description=%s, stream_options=%s 
          WHERE stream_id=%d", $stream['stream_name'], $stream['stream_slug'], $stream['stream_description'], $stream['stream_options'], $stream['stream_id']);
      } else {
        // prepare the sql
        $sql = $this->wpdb->prepare("INSERT INTO $this->table_name (stream_name, stream_slug, stream_description, stream_options) VALUES(%s, %s, %s, %s)",
          $stream['stream_name'], $stream['stream_slug'], $stream['stream_description'], $stream['stream_options']);
      } 
      
      $nr_affected = $this->wpdb->query($sql);
      if($nr_affected == 1) { 
        return true;
      } else {
        return false;
      }
    }

    /** 
     * Add or subtract a given number from the current stream's nr items
     *
     * @param int stream id 
     * @param string operator either + or - 
     * @param int number to add or subtract from the current stream_nr_items field Defaults to 1
     * @return int|false Number of items in the stream or false on error
     */ 
    function set_item_count($stream_id, $operator, $number = 1) 
    {
      if( ('+' == $operator) || ( '-' == $operator) ) {
        $sql = $this->wpdb->prepare("UPDATE $this->table_name SET stream_nr_items = stream_nr_items $operator %d WHERE stream_id=%d", $number, $stream_id);
        $affected = $this->wpdb->query($sql);
        return $this->_get_field($stream_id, 'stream_nr_items');
      }
    }


    /**
     * Get a stream's id by it's name
     *
     * @access public
     * @param string stream name
     * @return string|null stream id
     */
    function get_stream_id_by_name($stream_name)
    {
      $sql = $this->wpdb->prepare("SELECT stream_id FROM $this->table_name WHERE stream_name=%s", $stream_name);
      return $this->wpdb->get_var($sql);
    }


    

    /**
     * Get a specific column field value from a given stream
     *
     * @param int stream_id
     * @param string an existing table column field
     * @return mixed|false mixed value or false on error
     */
    function _get_field($stream_id, $table_col)
    {
      // make sure the table col is one of the possible table columns
      if( in_array ($table_col, $this->table_cols) ) {
        $sql = $this->wpdb->prepare("SELECT $table_col FROM $this->table_name WHERE stream_id=%d", $stream_id);
        return $this->wpdb->get_var($sql);
      }
      return false;
    }

    /**
     * Checks if the stream name already exists based on the stream slug
     * 
     * @access public
     * @param string stream slug (already sanitized)
     */
    function is_unique_stream($stream_slug) 
    {
      $sql = $this->wpdb->prepare("SELECT stream_slug FROM $this->table_name WHERE stream_slug = %s", $stream_slug);
      $result = $this->wpdb->get_var($sql);
      // no result found so the name is unique
      if( is_null($result) ) { 
        return true;
      }
      // fall thru
      return false;
    }

    /**
     * Check how many streams are available
     *
     * @access public
     * @return string number of streams
     */
    function nr_streams_available()
    {
      $sql = $this->wpdb->prepare("SELECT COUNT(stream_id) FROM $this->table_name");
      return $this->wpdb->get_var($sql);
    }


    /**
     * Deserialize options before returning a stream object 
     *
     * @access private
     * @param stream object
     * @return stream object with unserialized options or -1 on failure
     */
    function _deserialize_options($stream_object)  
    {
      if( ! is_object($stream_object) ) { return -1; }
        if( isset($stream_object->stream_options) ) {
        $stream_object->stream_options = unserialize($stream_object->stream_options);
        return $stream_object; 
      }  
    }


    /**
     * Set or update a stream option
     *
     * @access public
     * @param int|array One or multiple stream ids
     * @param string option name
     * @param unknown option value
     */
    function set_option($stream_id, $option_name, $option_value)
    {
      // either set or update an option
      
    }


    /**
     * Return a specific option's value from a stream based on the stream id 
     * and the option name
     *
     * @access public
     * @param int stream id
     * @param string option name aka a stream table column fieldname
     * @result unknown or -1 upon failure
     */
    function get_option($stream_id, $option_name)
    {
      $options = $this->_get_options($stream_id);
      if( is_array($options) && array_key_exists($option_name, $options) ) {
        return $options[$option_name];
      }
      // fall thru
      return -1;
    }


    /**
     * Returns the unserialized options array based on the given stream id
     *
     * @access private
     * @param int stream id
     * @return array|bool unserialized array or false on failure
     */
    function _get_options($stream_id) 
    {
      // deserialize options before returning
      $sql = $this->wpdb->prepare("SELECT stream_options FROM $this->table_name WHERE stream_id=%d", $stream_id);
      $result = $this->wpdb->get_var($sql);
      if( ! is_null($result) ) {
        return unserialize($result);
      } 
      // fall thru
      return false;
    }

    function _save_options($stream_id, $options = array() ) 
    {
      // serialize options before inserting
      $options = serialize($options);  
      $sql = $this->wpdb->prepare("INSERT INTO $this->table_name (stream_options) VALUES (%s) WHERE stream_id = %d", $options, $stream_id);
      $result = $this->wpdb->query($sql);
      return $result;
    }
      

  }
} 
?>
