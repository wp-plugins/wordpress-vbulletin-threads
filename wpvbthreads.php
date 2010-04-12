<?php
/*
Plugin Name: Wordpress-vBulletin Threads
Plugin URI: http://dev.whatniche.com/
Description: Allows automatic posting of certain WP content into a vB forum
Version: 1.0
Author: WhatNiche
Author URI: http://dev.whatniche.com/
License: GPL2
*/
/*  Copyright 2010  WhatNiche  (email : webmaster@whatniche.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Action Hooks and Definitions
add_action('admin_menu', 'wpvbt_admin_menu');
add_action('admin_init', 'wpvbt_settings');
add_action('publish_post', 'wpvbt_exec');
define('THIS_SCRIPT', 'wpvbthreads');

// Admin Functions
function wpvbt_admin_menu() {
    add_submenu_page('options-general.php', 'Wordpress-vBulletin Threads Settings', 'WPvB-Threads', 'activate_plugins', 'wpvb-threads-options', 'wpvbt_settings_page'); 
}

function wpvbt_settings() {
    register_setting('wpvbt-settings', 'wpvbt_categories');
    register_setting('wpvbt-settings', 'wpvbt_user', 'intval');
    register_setting('wpvbt-settings', 'wpvbt_post');
    register_setting('wpvbt-settings', 'wpvbt_forum_path');
}

function wpvbt_settings_page() {
?>
<div class="wrap">
<h2>Wordpress-vBulletin Threads</h2>

<form method="post" action="options.php">
    <?php settings_fields('wpvbt-settings'); ?>
    <table class="form-table">
        <tr valign="top">
        <th scope="row">Forum User (Used to Post Threads)</th>
        <td><input type="text" name="wpvbt_user" value="<?php echo get_option('wpvbt_user'); ?>" /></td>
        </tr>
         
        <tr valign="top">
        <th scope="row">Categories (Separate by comma, in format WP_CAT:VB_CAT, WP_CAT may be *)</th>
        <td><input type="text" name="wpvbt_categories" value="<?php echo get_option('wpvbt_categories'); ?>" /></td>
        </tr>
        
        <tr valign="top">
        <th scope="row">Post Template<br />May contain vB BBCODE<br />Tags: {date}, {content}, {title}, {excerpt}, {slug}</th>
        <td><textarea name="wpvbt_post" rows="8" cols="60"><?php echo get_option('wpvbt_post'); ?></textarea></td>
        </tr>
        
        <tr valign="top">
        <th scope="row">Forum Path (e.g. forums/)</th>
        <td><input type="text" name="wpvbt_forum_path" value="<?php echo get_option('wpvbt_forum_path'); ?>" /></td>
        </tr>
    </table>
    
    <p class="submit">
    <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
    </p>

</form>
</div>
<?php
}

// Main Functions

// Copy of WP's excerpt code
// Seems there isn't a function/method to
// generate an excerpt from a string
// Correct me if i'm wrong.
// Which makes me wonder,
// why on earth it has a $text parameter.
function wpvbt_excerpt($txt) {
    $txt = strip_shortcodes($txt);
    $txt = str_replace(']]>', ']]&gt;', $txt);
    $txt = strip_tags($txt);
    $len = 55;
    $more = ' [...]';
    $words = preg_split("/[\n\r\t ]+/", $txt, $len+1, PREG_SPLIT_NO_EMPTY);
    if(count($words) > $len) {
        array_pop($words);
        $txt = implode(' ', $words);
        $txt = $txt.$more;
    } else {
        $txt = implode(' ', $words);
    }
    return $txt;
}

// Exec function, does everything important
function wpvbt_exec($pid) {
    global $vbulletin;
    
    // Updating?
    if($_POST['original_post_status'] == 'publish')
        return;
    
    // vB Require
    if(!$wpvbt_fp = get_option('wpvbt_forum_path'))
        return;
    $cwd = getcwd();
    chdir($wpvbt_fp);
    require_once('./global.php');
    require_once('./includes/functions_newpost.php');
    require_once('./includes/class_dm.php');
    require_once('./includes/class_dm_threadpost.php');
    require_once('./includes/functions_databuild.php');
    chdir($cwd);
    
    // Get WP Post
    $post = get_post($pid);
    
    // Set Thread Options
    $uid = get_option('wpvbt_user');
    $fids = get_option('wpvbt_categories');
    
    // No forum IDs?
    if(empty($fids))
        return;
    
    // Parse them
    $fids = explode(",", $fids); // array('1:2','4:3')
    $forums = array();
    foreach($fids as $fid) {
        if(strpos($fid, ":") === false)
            continue;
        $fid_exp = explode(":",$fid);
        if($fid_exp[0] == $post->post_category || $fid_exp[0] == '*')
            $forums[] = $fid_exp[1];
    }
    
    // No Forums?
    if(empty($forums))
        return;
    
    // Parse Message
    $vbpost_message = get_option('wpvbt_post');
    $vbpost_message = str_replace(
        array('{date}', '{content}', '{title}', '{excerpt}', '{slug}'),
        array(
              $post->post_date,
              strip_tags($post->post_content),
              $post->post_title,
              (empty($post->post_excerpt) ? wpvbt_excerpt($post->post_content) : strip_tags($post->excerpt)),
              $post->post_name
              ),
        $vbpost_message
    );
    
    // User Info
    $uinfo = fetch_userinfo($uid);
    $vbulletin->userinfo = $uinfo;
    
    // Loop Through
    foreach($forums as $forum_id) {
        // Forum Info
        $finfo = fetch_foruminfo($forum_id);
        
        // TDM Settings
        $tdm =& datamanager_init('Thread_FirstPost', $vbulletin, ERRTYPE_ARRAY, 'threadpost');
        $tdm->set('userid', $uinfo['userid']);
        $tdm->set('title', $post->post_title);
        $tdm->set('pagetext', $vbpost_message);
        $tdm->set('allowsmilie', 1);
        $tdm->set('visible', 1);
        $tdm->set_info('forum', $finfo);
        $tdm->set('forumid', $forum_id);
        $tdm->set('dateline', time());
        $tdm->save();
    }
}
?>