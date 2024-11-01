<?php
/*
	Plugin Name: Terillion Reviews
	Plugin URI:  http://terillion.com/
	Description: Easily add Terillion Reviews to your site.
	Version:     1.2
	Author:      Terillion, Inc.
	Author URI:  http://terillion.com
	Requires at least: 3.1.0
*/

if (!class_exists('TerillionReviews'))
{
	class TerillionReviews
	{
		function __construct()
		{
			$this->TextDomain = 'TerillionReviews';
			$this->PluginFile = __FILE__;
			$this->PluginName = __('Terillion Reviews', $this->TextDomain);
			$this->PluginPath = dirname($this->PluginFile) . '/';
			$this->PluginURL = WP_PLUGIN_URL.'/'.dirname(plugin_basename($this->PluginFile)).'/';
			$this->SettingsName = 'TerillionReviews';
			$this->Settings = get_option($this->SettingsName);
			$this->Version = 1.0;

			$this->SettingsDefaults = array
			(
				'ProfileId' => '',
				'ProfileName' => '',
				'StickyPost' => 0,
			);

			$this->PostParmsDefaults = array
			(
				'method' => 'POST',
				'timeout' => 20,
				'redirection' => 5,
				'httpversion' => '1.0',
				'blocking' => true,
				'decompress' => true,
				'headers' => array('Content-Type' => 'application/json', 'Accept-Encoding' => 'gzip, deflate'),
				'body' => array(),
				'cookies' => array()
			);

			$this->ReviewType = array
			(
			   '1' => '',
			   '2' => 'Digital Pen',
			   '3' => 'Digital Pen',
			   '4' => 'Mobile',
			   '5' => '',
			   '6' => 'Handwritten',
			   '7' => 'Handwritten',
			   '8' => 'SMS',
			   '9' => '',
			   '10' => '',
			   '11' => 'ReviewStand Handwritten',
			);

			$this->ReviewRecommend = array
			(
				'' => 'N/A',
				'0' => 'N/A',
				'1' => 'No',
				'2' => 'Yes',
			);

			register_activation_hook($this->PluginFile, array($this, 'Activate'));
			register_deactivation_hook($this->PluginFile, array($this, 'Deactivate'));

			add_filter('plugin_action_links_'.plugin_basename($this->PluginFile), array($this, 'ActionLinks'));
			add_action('TerillionReviewsCron', array($this, 'Cron'));
			add_action('init', array($this, 'Init'));
			add_action('admin_menu', array($this, 'AdminMenu'));
			add_filter('the_posts', array($this, 'ThePosts'));
			add_action('wp_head', array($this, 'WPHead'), 1);
			add_filter('the_excerpt', array($this, 'TheExcerpt'));
			add_filter('the_content', array($this, 'TheContent'));
//trigger_error('User - ' . var_export($User, true), E_USER_WARNING);
//print '<pre>'; print_r ($APIFolders); print '</pre>';
		}


		function Activate()
		{
			if (is_array($this->Settings))
			{
				$Settings = array_merge($this->SettingsDefaults, $this->Settings);
				$Settings = array_intersect_key($Settings, $this->SettingsDefaults);

				update_option($this->SettingsName, $Settings);
			}
			else
			{
				add_option($this->SettingsName, $this->SettingsDefaults);
			}

			$Timestamp = wp_next_scheduled('TerillionReviewsCron', array('Type' => 'Recurring'));

			if ($Timestamp)
			{
				wp_clear_scheduled_hook('TerillionReviewsCron', array('Type' => 'Recurring'));
			}

			wp_schedule_event(strtotime('Next Midnight'), 'hourly', 'TerillionReviewsCron', array('Type' => 'Recurring')); // date('00:00')
			wp_schedule_single_event(time()+1, 'TerillionReviewsCron', array('Type' => 'Once'));
		}

		function Deactivate()
		{
			$Timestamp = wp_next_scheduled('TerillionReviewsCron', array('Type' => 'Recurring'));

			if ($Timestamp)
			{
				wp_clear_scheduled_hook('TerillionReviewsCron', array('Type' => 'Recurring'));
			}
			$this->DeleteAllCustomPosts();
		}


		function ActionLinks($Links)
		{
			$Link = '<a href="options-general.php?page='.$this->SettingsName.'">' . __('Settings', $this->TextDomain) . '</a>';

			array_push($Links, $Link);

			return $Links;
		}


		function Cron($Type = '')
		{
			if (!$this->Settings['ProfileId'])
			{
				return true;
			}

			$Response = wp_remote_post('http://reviews.terillion.com/json/'.$this->Settings['ProfileId'], $this->PostParmsDefaults);

			if (is_wp_error($Response) || !isset($Response['body']) || $Response['body'] == '')
			{
				print '<span class="error">No connectivity or service not available. Try later.</span>';
			}
			else
			{
				$ResponseJson = json_decode($Response['body'], true);


				if (function_exists('get_users')) // Since: 3.1.0
				{
					$PostAuthor = get_users(array('role' => 'administrator', 'orderby' => 'registered', 'order' => 'ASC', 'number' => 1, 'fields' => 'ID'));
					$PostAuthor = $PostAuthor ? reset($PostAuthor) : 1;
				}
				else
				{
					$PostAuthor = 1;
				}


				if ($ResponseJson['name'] != '')
				{
					$this->Settings['ProfileName'] = $ResponseJson['name'];
					update_option($this->SettingsName, $this->Settings);

					$StickyPost = get_posts(array('posts_per_page' => -1, 'meta_key' => '_ReviewSticky', 'meta_value' => 1, 'post_type' => 'reviewstand'));
					$ProfileName = $this->Settings['ProfileName'];

					if (count($StickyPost))
					{
						$StickyPostId = $StickyPost[0]->ID;
					}
					else
					{
						$StickyPostId = wp_insert_post(array('post_author' => $PostAuthor, 'post_status' => 'publish', 'post_title' => 'Our Verified Reviews', 'post_type' => 'reviewstand'), true);
					}

					if (!is_wp_error($StickyPostId))
					{
						$this->Settings['StickyPost'] = $StickyPostId;
						update_option($this->SettingsName, $this->Settings);

						update_post_meta($StickyPostId, '_ReviewSticky', 1);
						update_post_meta($StickyPostId, '_ReviewName', $ResponseJson['name']);
						update_post_meta($StickyPostId, '_ReviewRating', $ResponseJson['overall']);
						update_post_meta($StickyPostId, '_ReviewRatingCount', $ResponseJson['numberofratings']);
						update_post_meta($StickyPostId, '_NumberAnswering', $ResponseJson['numberanswering']);
						update_post_meta($StickyPostId, '_ReviewRecommend', $ResponseJson['pctrecommend']);
					}
				}

				if (is_array($ResponseJson['reviews']) && count($ResponseJson['reviews']))
				{
					global $wpdb;

					$ReviewsPublished = $wpdb->get_col("SELECT DISTINCT $wpdb->postmeta.meta_value FROM $wpdb->posts, $wpdb->postmeta WHERE $wpdb->posts.post_type = 'reviewstand' AND $wpdb->posts.post_status = 'publish' AND $wpdb->posts.ID = $wpdb->postmeta.post_id AND $wpdb->postmeta.meta_key = '_ReviewId'");

					foreach ($ResponseJson['reviews'] as $Review)
					{
						if (in_array($Review['id'], $ReviewsPublished))
						{
							continue;
						}

						$PostParam = array
						(
							'post_author' => $PostAuthor,
							'post_date' => $Review['datetime'],
							'post_excerpt' => $Review['comments'],
							'post_name' => $Review['id'],
							'post_status' => 'publish',
							'post_title' => 'Verified '.$this->ReviewType[$Review['input_method']].' Review',
							'post_type' => 'reviewstand',
						);

						$PostId = wp_insert_post($PostParam, true);

						if (!is_wp_error($PostId))
						{
							update_post_meta($PostId, '_ReviewId', $Review['id']);
							update_post_meta($PostId, '_ReviewAverage', $Review['average']);
							update_post_meta($PostId, '_ReviewComment', $Review['comments']);
							update_post_meta($PostId, '_ReviewImage', $Review['image']);
							update_post_meta($PostId, '_ReviewRecommend', $Review['recommend']);
						}
					}
				}
			}

			if ($Type == 'Once')
			{
				global $wp_rewrite;
				$wp_rewrite->flush_rules();
			}
		}


		function Init()
		{
			load_plugin_textdomain($this->TextDomain, false, dirname(plugin_basename($this->PluginFile)).'/languages');

			register_post_type('reviewstand',
				array(
					'labels' => array(
							'name' => __('Reviews', $this->TextDomain),
							'singular_name' => __('Review', $this->TextDomain),
							'add_new' => __('Add Review', $this->TextDomain),
							'add_new_item' => __('Add New Review', $this->TextDomain),
							'edit' => __('Edit', $this->TextDomain),
							'edit_item' => __('Edit Review', $this->TextDomain),
							'new_item' => __('New Review', $this->TextDomain),
							'all_items' => __('All Reviews', $this->TextDomain),
							'view' => __('View Review', $this->TextDomain),
							'view_item' => __('View Review', $this->TextDomain),
							'search_items' => __('Search Reviews', $this->TextDomain),
							'not_found' => __('No Reviews found', $this->TextDomain),
							'not_found_in_trash' => __('No Reviews found in trash', $this->TextDomain),
							'parent' => __('Parent Review', $this->TextDomain)
						),
					'description' => __('This is where you can add new Reviews to your site.', $this->TextDomain),
					'public' => true,
					'exclude_from_search' => true,
					'publicly_queryable' => true,
					'show_ui' => true,
					'show_in_admin_bar' => false,
					'show_in_nav_menus' => false,
					'hierarchical' => false,
					'supports' => array('title', 'editor', 'excerpt', 'custom-fields'),
					'has_archive' => true,
					'rewrite' => array('slug' => 'reviews', 'with_front' => false),
					'query_var' => true,			
				)
			);
		}


		function AdminMenu()
		{
			add_options_page($this->PluginName, $this->PluginName, 'manage_options', $this->SettingsName, array($this, 'AdminOptions'));
		}


		function AdminOptions()
		{
			if (!current_user_can('manage_options'))
			{
				wp_die(__('You do not have sufficient permissions to access this page.'));
			}

			if (isset($_POST['action']) && !wp_verify_nonce($_POST['nonce'], $this->SettingsName))
			{
				wp_die(__('Security check failed! Settings not saved.'));
			}
			
			if (isset($_POST['action']) && $_POST['action'] == 'Run Cron Update')
				$this->Cron();

			elseif (isset($_POST['action']) && $_POST['action'] == 'Delete ALL Reviews')
				$this->DeleteAllCustomPosts();

			elseif (isset($_POST['action']) && $_POST['action'] == 'Save Settings')
			{
				foreach ($_POST as $Key => $Value)
				{
					if (array_key_exists($Key, $this->SettingsDefaults))
					{
						if ($Key == 'ProfileId' && $this->Settings[$Key] != $_POST[$Key])
						{
							wp_schedule_single_event(time()+1, 'TerillionReviewsCron', array('Type' => 'Once'));
							$Value = trim($Value);
							$this->Settings[$Key] = (int) $Value;	
						}
					}
				}

				if (update_option($this->SettingsName, $this->Settings))
				{
					print '<div class="updated"><p><strong>Settings saved.</strong></p></div>';
				}
			}

		?>

			<div class="wrap">
				<h2><?php print $this->PluginName; ?> Settings</h2>
				<p>You can see your published reviews <a href="/reviews">here</a></p>
				<form method="post" action="">
					<h3>Common Settings</h3>
					<table class="form-table">
						<tr valign="top">
							<th scope="row">
								Profile Id
							</th>
							<td>
								<input name="ProfileId" type="text" size="10" maxlength="9" value="<?php print $this->Settings['ProfileId']; ?>" class="rxegular-text" />
							</td>
						</tr>
					</table>
					<input name="nonce" type="hidden" value="<?php print wp_create_nonce($this->SettingsName); ?>" />
					<div class="submit">
					<input name="action" type="submit" value="Save Settings" class="button-primary" />
					<input name="action" type="submit" value="Run Cron Update" class="button" />
					</div>
					<div class="submit">
					<input name="action" type="submit" value="Delete ALL Reviews" class="button" />
					</div>
				</form>
			</div>

		<?php

		}


		function ThePosts($Posts)
		{
			if (!is_admin() && is_post_type_archive('reviewstand'))
			{
				$StickyPost = get_post($this->Settings['StickyPost']);

				if ($StickyPost)
				{
					for ($I = 0; $I < count($Posts); $I++)
					{
						if ($Posts[$I]->ID == $this->Settings['StickyPost'])
						{
							unset($Posts[$I]);
						}
					}

					array_unshift($Posts, $StickyPost);
				}
			}

			return $Posts;
		}


		function WPHead()
		{
			//if (is_singular('reviewstand'))
			//{
				global $post;

				$ReviewComment = get_post_meta($post->ID, '_ReviewComment', true);
				$ReviewImage = ($ReviewImageTemp = get_post_meta($post->ID, '_ReviewImage', true)) ? $ReviewImageTemp : 'http://reviews.terillion.com/images/verified_badge.png';
				$ReviewURL = get_permalink($post->ID);

				print '
					<!-- Terillion Review: Google Meta -->
					<meta itemprop="name" content="'.$this->Settings['ProfileName'].' Verified Review">
					<meta itemprop="description" content="Review Comments: '.$ReviewComment.'">
					<meta itemprop="image" content="'.$ReviewImage.'">
					<!-- Terillion Review: Twitter Meta -->
					<meta name="twitter:card" content="'.($ReviewImageTemp ? 'photo' : 'summary').'">
					<meta name="twitter:site" content="@terillion">
					<meta name="twitter:creator" content="@terillion">
					<meta name="twitter:url" content="'.$ReviewURL.'">
					<meta name="twitter:title" content="Verified Review of '.$this->Settings['ProfileName'].'">
					<meta name="twitter:description" content="Comments: '.$ReviewComment.'">
					<meta name="twitter:image" content="'.$ReviewImage.'"/>
					<!-- Terillion Review: Facebook Meta -->
					<meta property="og:title" content="'.$this->Settings['ProfileName'].' Verified Review"/>
					<meta property="og:type" content="website"/>
					<meta property="og:image" content="'.$ReviewImage.'"/>
					<meta property="og:url" content="'.$ReviewURL.'"/>
					<meta property="og:site_name" content="'.$this->Settings['ProfileName'].' Verified Reviews"/>
					<meta property="og:description" content="Comments: '.$ReviewComment.'"/>
					<!-- Terillion Review: CSS -->
					<link rel="stylesheet" type="text/css" href="http://reviews.terillion.com/css/wordpress.css"> 
				';
			//}
		}


		function TheExcerpt($Content)
		{
			global $post;

			if (get_post_type($post) == 'reviewstand')
			{
				$ReviewSticky = get_post_meta($post->ID, '_ReviewSticky', true);

				if ($ReviewSticky)
				{
					$Data = '<div class="review review-sticky" itemscope="" itemtype="http://schema.org/LocalBusiness">
								<h1 class="terillionprofilename"><span itemprop="name">'.$this->Settings['ProfileName'].'</span></h1>
								
<div class="terillionsection terilliongroup" itemprop="aggregateRating" itemscope="" itemtype="http://schema.org/AggregateRating">
									<div class="terillioncol terillionspan_1_of_3">

<h2 class="terilliontitle">Overall Rating</h2>
<table class="ratingTable" cellspacing="0">
        <tbody><tr>
          <td class="rating" style="color:#09bfeb;"><span itemprop="ratingValue">'.get_post_meta($post->ID, '_ReviewRating', true).'</span></td>
        </tr>
		<tr>
		  <td class="outCell" style="color:#09bfeb;">(out of <span itemprop="bestRating">5</span>)</td>
        </tr>
      </tbody></table></div>
									<div class="terillioncol terillionspan_1_of_3"><h2 class="terilliontitle">Number of Ratings</h2>
<table class="ratingTable" cellspacing="0">
        <tbody><tr>
          <td class="rating" style="color:#fba82c;"><span itemprop="reviewCount">'.get_post_meta($post->ID, '_ReviewRatingCount', true).'</span></td>
        </tr>
		<tr>
		  <td class="outCell" style="color:#fba82c;">Verified Reviews</td>  
        </tr>
      </tbody></table></div>
		<div class="terillioncol terillionspan_1_of_3"><h2 class="terilliontitle">Recommendation</h2>';

if (get_post_meta($post->ID, '_NumberAnswering', true) > 5) {

$Total= '<table class="ratingTable" cellspacing="0">
        <tbody><tr><td class="rating" style="color:#add13d;"> '.get_post_meta($post->ID, '_ReviewRecommend', true).'<span style="font-size:50%; line-height:1em; vertical-align:super;">%</span></td>
        </tr>
		<tr>
		  <td class="outCell" style="color:#add13d;">Would Recommend</td>  
        </tr>
      </tbody></table></div>
		</div>
		</div>
		<br />
		<hr />';
}
else {

$Total= '<table class="ratingTable" cellspacing="0">
        <tbody><tr><td class="rating" style="color:#add13d;">N/A</td>
        </tr>
		<tr>
		  <td class="outCell" style="color:#add13d;">Not Enough Ratings</td>  
        </tr>
      </tbody></table></div>
		</div>
		</div>
		<br />
		<hr />';
	 }


					$Content = $Data . $Total;
				}
				else
				{
					$ReviewAverage = get_post_meta($post->ID, '_ReviewAverage', true);
					$ReviewComment = get_post_meta($post->ID, '_ReviewComment', true);
					$ReviewImage = get_post_meta($post->ID, '_ReviewImage', true);
					$ReviewImageSocial = $ReviewImage ? $ReviewImage : 'http://reviews.terillion.com/images/verified_badge.png';
					$ReviewRecommend = get_post_meta($post->ID, '_ReviewRecommend', true);
					$ReviewURL = get_permalink($post->ID);
					$ReviewId = get_post_meta($post->ID, '_ReviewId', true);
					$PID = $this->Settings['ProfileId'];

					$ReviewRatingStars = '';
					$ReviewRating = number_format(round($ReviewAverage * 4) / 4, 2);
					$ReviewRatingDecimal = ($ReviewRating - intval($ReviewRating)) * 100;
					for ($I = 1; $I <= 5; $I++)
					{
						if ($ReviewRating >= 1)
						{
							$ReviewRatingStars .= '<img src="'.$this->PluginURL.'images/rating-star-100.png" alt="" border="0" />';  
						}
						elseif ( ($ReviewRating > 0) && ($ReviewRating < 1) )
						{
							$ReviewRatingStars .= '<img src="'.$this->PluginURL.'images/rating-star-'.$ReviewRatingDecimal.'.png" alt="" border="0" />';
						}
						elseif ($ReviewRating <= 0) 
						{
							$ReviewRatingStars .= '<img src="'.$this->PluginURL.'images/rating-star-0.png" alt="" border="0" />';
						}

						$ReviewRating--;
					}

					$Data = '<div class="review" itemprop="review" itemscope="" itemtype="http://schema.org/Review">
								<p>Date: <meta itemprop="datePublished" content="'.$post->post_date.'">'.date(get_option('date_format'), strtotime($post->post_date)).'</p>
								<p itemprop="reviewRating" itemscope itemtype="http://schema.org/Rating">Rating: '.$ReviewRatingStars.' <meta itemprop="worstRating" content = "1"> <span itemprop="ratingValue">'.$ReviewAverage.'</span> out of <span itemprop="bestRating">5</span></p>
								<p>Would Recommend: '.$this->ReviewRecommend[$ReviewRecommend].'</p>
								<p>Comments: "<span itemprop="description">'.$ReviewComment.'</span>"</p>'.($ReviewImage ? '<p><img src="'.$ReviewImage.'" alt="" style="width:100%; max-width:920px;"/></p>' : '').'
							</div>
							<p> Share this Review: <a class="terillionsocial" href="https://www.facebook.com/dialog/feed?app_id=321478941298750&amp;link=https://terillion.com/'.$PID.'&amp;picture='.$ReviewImageSocial.'&amp;name='.$this->Settings['ProfileName'].'&amp;caption=Check out this Verified Review of '.$this->Settings['ProfileName'].'&amp;description=Comments:%20'.$ReviewComment.'&amp;redirect_uri=http://business.terillion.com/'.$PID.'"><img class="social" src="http://reviews.terillion.com/images/facebook.jpg"></a>&nbsp;<a class="terillionsocial" href="https://twitter.com/intent/tweet?original_referer='.$ReviewURL.'&amp;source=tweetbutton&amp;text=Check out this review of '.$this->Settings['ProfileName'].':&amp;url=http://business.terillion.com/review-details/'.$ReviewId.'"><img class="social" src="http://reviews.terillion.com/images/twitter.jpg"></a>&nbsp;<a class="terillionsocial" href="https://plus.google.com/share?url='.$ReviewURL.'"><img class="social" src="http://reviews.terillion.com/images/google.jpg"></a>&nbsp;<a class="terillionsocial" href="http://www.linkedin.com/shareArticle?mini=true&amp;url='.$ReviewURL.'&amp;title=Check out this review of '.$this->Settings['ProfileName'].':&amp;summary=Comments:%20'.$ReviewComment.'&amp;source='.$ReviewURL.'"><img class="social" src="http://reviews.terillion.com/images/linkedin.png"></a>&nbsp;<a class="terillionsocial" href="http://pinterest.com/pin/create/button/?url='.$ReviewURL.'&amp;media='.$ReviewImageSocial.'&amp;description=Verified Review of '.$this->Settings['ProfileName'].': '.$ReviewComment.'"><img class="social" src="http://reviews.terillion.com/images/pinterest.png"></a> </p><br /><hr />';

						$Content = $Data;
					
				}
			}

			return $Content;
		}

		function TheContent($Content)
		{
			global $post;

			if (get_post_type($post) == 'reviewstand')
			{
				$ReviewSticky = get_post_meta($post->ID, '_ReviewSticky', true);

				if ($ReviewSticky)
				{
					$Data = '<div class="review review-sticky" itemscope="" itemtype="http://schema.org/LocalBusiness">
								<h1 class="terillionprofilename"><span itemprop="name">'.$this->Settings['ProfileName'].'</span></h1>
								
<div class="terillionsection terilliongroup" itemprop="aggregateRating" itemscope="" itemtype="http://schema.org/AggregateRating">
									<div class="terillioncol terillionspan_1_of_3">

<h2 class="terilliontitle">Overall Rating</h2>
<table class="ratingTable" cellspacing="0">
        <tbody><tr>
          <td class="rating" style="color:#09bfeb;"><span itemprop="ratingValue">'.get_post_meta($post->ID, '_ReviewRating', true).'</span></td>
        </tr>
		<tr>
		  <td class="outCell" style="color:#09bfeb;">(out of <span itemprop="bestRating">5</span>)</td>
        </tr>
      </tbody></table></div>
									<div class="terillioncol terillionspan_1_of_3"><h2 class="terilliontitle">Number of Ratings</h2>
<table class="ratingTable" cellspacing="0">
        <tbody><tr>
          <td class="rating" style="color:#fba82c;"><span itemprop="reviewCount">'.get_post_meta($post->ID, '_ReviewRatingCount', true).'</span></td>
        </tr>
		<tr>
		  <td class="outCell" style="color:#fba82c;">Verified Reviews</td>  
        </tr>
      </tbody></table></div>
									<div class="terillioncol terillionspan_1_of_3"><h2 class="terilliontitle">Recommendation</h2>';

if (get_post_meta($post->ID, '_NumberAnswering', true) > 5) {

$Total2= '<table class="ratingTable" cellspacing="0">
        <tbody><tr><td class="rating" style="color:#add13d;"> '.get_post_meta($post->ID, '_ReviewRecommend', true).'<span style="font-size:50%; line-height:1em; vertical-align:super;">%</span></td>
        </tr>
		<tr>
		  <td class="outCell" style="color:#add13d;">Would Recommend</td>  
        </tr>
      </tbody></table></div></div></div><br /><a href="http://terillion.com">Powered by Terillion</a>';
}
else {

$Total2= '<table class="ratingTable" cellspacing="0">
        <tbody><tr><td class="rating" style="color:#add13d;">N/A</td>
        </tr>
		<tr>
		  <td class="outCell" style="color:#add13d;">Not Enough Ratings</td>  
        </tr>
      </tbody></table>
</div>
</div>
</div>
<br /><a href="http://terillion.com">Powered by Terillion</a>';
	 }
				
$Data = $Data . $Total2;		
$Content = $Data . $Content;
				}
				else
				{
					$ReviewAverage = get_post_meta($post->ID, '_ReviewAverage', true);
					$ReviewComment = get_post_meta($post->ID, '_ReviewComment', true);
					$ReviewImage = get_post_meta($post->ID, '_ReviewImage', true);
					$ReviewImageSocial = $ReviewImage ? $ReviewImage : 'http://reviews.terillion.com/images/verified_badge.png';
					$ReviewRecommend = get_post_meta($post->ID, '_ReviewRecommend', true);

					$ReviewURL = get_permalink($post->ID);
					$ReviewId = get_post_meta($post->ID, '_ReviewId', true);
					$PID = $this->Settings['ProfileId'];

					$ReviewRatingStars = '';
					$ReviewRating = number_format(round($ReviewAverage * 4) / 4, 2);
					$ReviewRatingDecimal = ($ReviewRating - intval($ReviewRating)) * 100;
					for ($I = 1; $I <= 5; $I++)
					{
						if ($ReviewRating >= 1)
						{
							$ReviewRatingStars .= '<img src="'.$this->PluginURL.'images/rating-star-100.png" alt="" border="0" />';  
						}
						elseif ( ($ReviewRating > 0) && ($ReviewRating < 1) )
						{
							$ReviewRatingStars .= '<img src="'.$this->PluginURL.'images/rating-star-'.$ReviewRatingDecimal.'.png" alt="" border="0" />';
						}
						elseif ($ReviewRating <= 0) 
						{
							$ReviewRatingStars .= '<img src="'.$this->PluginURL.'images/rating-star-0.png" alt="" border="0" />';
						}

						$ReviewRating--;
					}

					$Data = '<div class="review" itemprop="review" itemscope="" itemtype="http://schema.org/Review">
								<p>Date: <meta itemprop="datePublished" content="'.$post->post_date.'">'.date(get_option('date_format'), strtotime($post->post_date)).'</p>
								<p itemprop="reviewRating" itemscope itemtype="http://schema.org/Rating">Rating: '.$ReviewRatingStars.' <meta itemprop="worstRating" content = "1"> <span itemprop="ratingValue">'.$ReviewAverage.'</span> out of <span itemprop="bestRating">5</span></p>
								<p>Would Recommend: '.$this->ReviewRecommend[$ReviewRecommend].'</p>
								<p>Comments: "<span itemprop="description">'.$ReviewComment.'</span>"</p>'.($ReviewImage ? '<p><img src="'.$ReviewImage.'" alt="" style="width:100%; max-width:920px;"/></p>' : '').'
							</div>
							<p> Share this Review: <a class="terillionsocial" href="https://www.facebook.com/dialog/feed?app_id=321478941298750&amp;link=https://terillion.com/'.$PID.'&amp;picture='.$ReviewImageSocial.'&amp;name='.$this->Settings['ProfileName'].'&amp;caption=Check out this Verified Review of '.$this->Settings['ProfileName'].'&amp;description=Comments:%20'.$ReviewComment.'&amp;redirect_uri=http://business.terillion.com/'.$PID.'"><img class="social" src="http://reviews.terillion.com/images/facebook.jpg"></a>&nbsp;<a class="terillionsocial" href="https://twitter.com/intent/tweet?original_referer='.$ReviewURL.'&amp;source=tweetbutton&amp;text=Check out this review of '.$this->Settings['ProfileName'].':&amp;url=http://business.terillion.com/review-details/'.$ReviewId.'"><img class="social" src="http://reviews.terillion.com/images/twitter.jpg"></a>&nbsp;<a class="terillionsocial" href="https://plus.google.com/share?url='.$ReviewURL.'"><img class="social" src="http://reviews.terillion.com/images/google.jpg"></a>&nbsp;<a class="terillionsocial" href="http://www.linkedin.com/shareArticle?mini=true&amp;url='.$ReviewURL.'&amp;title=Check out this review of '.$this->Settings['ProfileName'].':&amp;summary=Comments:%20'.$ReviewComment.'&amp;source='.$ReviewURL.'"><img class="social" src="http://reviews.terillion.com/images/linkedin.png"></a>&nbsp;<a class="terillionsocial" href="http://pinterest.com/pin/create/button/?url='.$ReviewURL.'&amp;media='.$ReviewImageSocial.'&amp;description=Verified Review of '.$this->Settings['ProfileName'].': '.$ReviewComment.'"><img class="social" src="http://reviews.terillion.com/images/pinterest.png"></a> </p><br /><a href="http://terillion.com">Powered by Terillion</a>						
					';

					$Content = $Content . $Data;
				}
			}

			return $Content;
		}


		function CheckSelected($ValueOne, $ValueTwo, $Type = 'select')
		{
			if ( (is_array($ValueOne) && in_array($ValueTwo, $ValueOne)) || ($ValueOne == $ValueTwo) )
			{
				switch ($Type)
				{
					case 'select':
						print 'selected="selected"';
						break;
					case 'radio':
					case 'checkbox':
						print 'checked="checked"';
						break;
				}
			}
		}


		function DeleteAllCustomPosts()
		{
			$mycustomposts = get_posts( array( 'numberposts' => -1, 'post_type' => 'reviewstand') );
			if ($mycustomposts)
				foreach ( $mycustomposts as $mypost )
					wp_delete_post( $mypost->ID, false); // Set to true to send them to Trash.
		}
		
	}


	$TerillionReviews = new TerillionReviews();
}