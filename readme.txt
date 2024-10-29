=== bbAggregate ===
Contributors: BjornW
Donate link: http://burobjorn.nl
Tags: multisite, aggregate, aggregator, global terms, sitewide categories, sitewide content
Requires at least: 3.0
Tested up to: 3.01
Stable tag: 1.0

Create streams of sitewide aggregated content from your multisite WordPress installation with bbAggregate
 
== Description ==

**2012-08-30: I'm currently looking into updating this plugin, but I need testers to do this. Are you using bbAggregate, please share your experiences with me. We can use the WordPress forums for this. Thanks!**

Create streams of sitewide aggregated content from your multisite WordPress installation with bbAggregate. 

A stream contains posts from those blogs allowed to participate in the stream. Anyone writing posts can add his/her post to one or more streams. 
The streams are shown by using a specific page template. (see also the examples directory). It offers functionality similar 
to the sitewide categories / global terms, but with more control and (hopefully) less buggy behaviour. 

This plugin adds two database tables to your installation:
  
* $prefix_bbAggregate_item (links an item to a stream)
* $prefix_bbAggregate_stream (contains the stream info)   

NB: This plugin is only useful for a WordPress Multisite installation in which multiple
blogs are being used. 

Feel free to send feedback, patches or sponsor the development! 

= How does it work? = 

First, you create a stream (neccessary user capabillity: 'manage_options',admin role works perfectly). (See screenshot-4.png)
It is recommended to only use letters, numbers and underscores or dashes in the stream name. Spaces in the name may cause problems at the moment. 

Streams are defined by a name, a description (which is saved but not yet accessible for public use) and some options (see screenshot-5.png) such as:

* maximum number of posts (limits the total amount of posts in a stream)
* maximum number of posts per blog (limits the amount of posts per blog in a stream)
* number of posts per page (limits the amount of posts per page. Pagination is included)
* excluded blogs (blogs which have been explicitly excluded from using this stream. Posts from these blogs will not be shown in this stream) 

After creating a stream (See screenshot-6.png and screenshot-7.png), create a post in a blog which is not excluded from this stream. Below the Post Editing Area you will see a new metabox called
Streams (see screenshot-8.png) with a list of available streams (streams which have excluded this blog are not shown). By ticking the checkbox in front of the stream name 
you've added your post to this stream. Every user able to add a post may add his/her posts to the streams shown.

To show a stream of content you (or the theme designer of the theme you use) need to prepare the theme for showing the items. You need a page template
for this. An example of this can be found in bbAggregate/examples and the template is called: bbAggregate-template-aggregated-posts. For now you can copy
it into the directory of your active theme so you can test bbAggregated. Keep in mind though that it will not use your theme's style! 
Create a page in the blog where you also have copied the page template and select the Aggregated Posts template
as the page's template. Add the customfield with the name 'bbagg_stream' (without quotes) and customfield value the exact(!) stream name of the stream you want to show (see screenshot-11.png). 
By the way, the example page template the custom field name is hardcoded, but you can change it in the template as long as you also use the new name
for your customfield. After adding the customfield name and value and publishing the page you can view the page and you should now see your post part
of the stream's aggregated content (see screenshot-12.png).     

_Note_: The stream default options can be changed sitewide by visiting the Super Admin->Options (See screenshot-9.png). Look for 'bbAggregate sitewide defaults' (see screenshot-10.png). After changing
the options' values and saving the changes new streams will use your new defaults. This can only be done by users with super admin rights. Keep in mind that streams are sitewide. Thus streams created in blog 1
are also visible in the bbAggregate settings of blog 2. 

== Installation == 

1. Unzip the plugin's zip file
2. Upload the bbAggregate directory to the `/wp-content/plugins/` directory
4. Activate the plugin through the 'Plugins' menu using Network Activate. 
This ensures that the plugin will work for all blogs (see screenshot-1.png). 
5. After activation (see screenshot-2.png) the plugin is installed. 
You can now start using the plugin. See Usage for more information about using the plugin. 

== Screenshots ==

1. Plugins administration interface with bbAggregate Network Activate highlighted 
2. bbAggregated has been activated (note the plugin activated message, top left corner) 
and the settings 
button is highlighted.
3. Streams overview screen and streams create section highlighted
4. Creating the stream 'Technology'
5. Showing the extra options of the 'Technology' stream 
6. Finishing the creation of the 'Technology' stream by setting the options and adding a description
7. The stream has been created
8. Adding a post to a stream. Streams are highlighted
9. Super Admin options highlighted
10. bbAggregated sitewide options shown
11. Add a page on which a stream can be shown. Highlighted are the page template and the customfield, both necessarry to show aggregated content. 
12. The result of the default page template used with the Twenty-Ten default theme. Highlighted are the post from Testblog 3 in the Technoloy stream shown on
the main blog and the pagination (not styled!)  

== Changelog ==

= 1.0 =
First version

== Upgrade notice == 

Intentionally left blank.


== Frequently Asked Questions ==

= How do the limitations work? = 

A stream is limited by the option 'maximum number of posts' which 
controls the total amount of posts a stream will display. So you 
can keep on adding posts indefinitely, but it only the last created
'maximum number of posts' will be shown.  

You also have the option 'maximum number of posts per blog'. This will limit
the display of posts from one blog to the 'maximum number of posts per blog'. As 
with the previous option it will not prevent you from adding posts to a stream, it 
only limits the posts shown. This allows you to prevent one blog from taking over the 
stream content completely and promotes diversity in a stream.

= Blogs marked as Spam, Archived or Mature are not displayed? =

Correct, by design this plugin will only take active, public blogs into 
consideration for the streams.


== TODO ==

Feel free to help out by sending patches or sponsoring development!

Feedback very much appreciated!

* Remove database tables upon delete of plugin 
* Add .po and .mo files for translations
* Create php doc files
* Might want to rename the menu from bbAggregate to Streams. Might even 
be better to call the plugin streams?  (Thanks Ovidiu for your feedback!)
* Limiting blogs is akward with lists of hundreds of blogs. Need to rework 
the interface to make this more userfriendly. (Thanks Ovidiu for your feedback!)
* Add explicitly include posts from a given set of blogs
* Add option to limit the creation of streams to a certain role,
currently it is limited to per site admin and this will be the default. (Thanks Ovidiu for your feedback!) 
* Since every admin can create and edit streams, they all can 
edit each others streams and drive themselves crazy! its kinda strange: 
the  site admin sets the limits for streams and yet any admin can 
create/edit streams and set the limit higher. I exclude a site from 
being aggregated in a stream and the very same admin can add himself 
back into that stream. This is related to the previous todo of the roles
for creating streams. Need to have a closer look at the roles. (Thanks Ovidiu for your feedback!)
* Need to have another look at sorting aggregated post. Maybe add an option for sorting
* customfield for retrieving stream items is now hardcoded 'bbagg_stream' Add option to change it.
* Include feeds of aggregated content
* Change installation from plugins directory to mu-plugins directory?


 
