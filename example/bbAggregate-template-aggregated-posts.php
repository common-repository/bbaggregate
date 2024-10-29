<?php
/*
 * Template Name: Aggregated posts 
 */
get_header(); 

/** 
 * Use the bbagg_aggregate function to retrieve the 
 * a stream's aggregated posts. Use a custom field to
 * communicate the stream's name to the function. In this
 * example I use the custom field name bbagg_stream to retrieve
 * the stream's aggregated posts
 */
if( function_exists('bbagg_aggregate') ) {
  $stream_name = get_post_meta($post->ID, 'bbagg_stream', true); 
  if( isset($stream_name) && ! empty($stream_name) ) {
    $aggregated_posts = bbagg_aggregate($stream_name);
  }
} 
?>

<?php 
/** 
 * Below this comment you will see something very similar to the WordPress Loop
 * all the possible data you can use has been used here. You can freely change
 * the html to reflect your design. Keep in mind though to escape the results
 * according to their use.
 */
?>
<div id="container">
	<div id="content" role="main">
<?php if( isset($aggregated_posts) && is_array($aggregated_posts) ) :  ?>
  <h1>Aggregated Posts</h1> 
  <?php foreach($aggregated_posts as $post) : ?>
    <div class="post">
      <h2><a href="<?php echo esc_url($post->guid);?>" title="Visit <?php echo esc_html($post->post_title); ?>"><?php echo esc_html($post->post_title); ?></a></h2>
      <span class="author"><strong>Author:</strong> <?php echo esc_html($post->author_name)?></span><br />
      <span class="nr_comments"><strong>Number of comments:</strong> <?php echo esc_html($post->nr_comments); ?></span><br />  
      <span class="blog"><strong>Posted on blog:</strong> <a href="<?php echo esc_url($post->blog_url); ?>" title="Visit <?php echo esc_html($post->blog_name); ?>"><?php echo esc_html($post->blog_name)?></a></span><br />
      <span class="date"><strong>Written on:</strong> <?php echo esc_html($post->post_date); ?></span>
      <div class="content"><?php echo $post->post_content; ?></div> 
    </div>
  <?php endforeach; ?>
<?php 
/** 
 * Below this comment you will see the bbagg_paginate function. It does all the hard work of pagination for you!
 * The only thing you need to take care of is styling it to your liking :) 
 */ 
?>
    <div class="paginate">
      <?php bbagg_paginate();?> 
    </div>
<?php else : ?>
  <h1>Sorry, no aggregated posts found</h1>
<?php endif; ?> 
  </div>
</div>
<?php get_sidebar(); ?>
<?php get_footer(); ?>
