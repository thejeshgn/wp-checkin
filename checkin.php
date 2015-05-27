<?php
/*
Plugin Name: CheckIn
Plugin URI: http://github.com/thejeshgn/wp-checkin
Description: Displays post geotag information using mapsmaker. Also cheaters a marker and adds it to a layer.
Version: 0.1.1
Author: Thejesh GN
Author URI: http://thejeshgn.com
License: GPL2
*/

/*  
	Copyright 2010 Chris Boyd (email : chris@chrisboyd.net)
	Copyright 2015 Thejesh GN (email : i@thejeshgn.com)

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

add_action('admin_menu', 'add_settings');
add_filter('the_content', 'display_location', 5);
admin_init();
register_activation_hook(__FILE__, 'activate');
wp_enqueue_script("jquery");

define('SHORTCODE', '[checkin]');


function calculate_signature($expires) {
    $api_key = get_option('checkin_api_key');
    $private_key = get_option('checkin_api_pvt_key');
	$string_to_sign = sprintf("%s:%s", $api_key, $expires);
	$hash = hash_hmac("sha1", $string_to_sign, $private_key, true);
	$sig = rawurlencode(base64_encode($hash));
	return $sig;
}

function jsonp_decode($jsonp, $assoc = false) {
	if($jsonp[0] !== '[' && $jsonp[0] !== '{') {
		$jsonp = substr($jsonp, strpos($jsonp, '('));
	}
	return json_decode(trim($jsonp,'();'), $assoc);
}


function activate() {
	register_settings();
	add_option('checkin_map_width', '90');
	add_option('checkin_map_height', '400');
	add_option('checkin_default_zoom', '16');
	add_option('checkin_map_position', 'shortcode');
	add_option('checkin_layer_id', '1');
	add_option('checkin_api_key', '1');
	add_option('checkin_api_pvt_key', '1');
	add_option('checkin_api_endpoint', 'http://localhost:8080/wp-content/plugins/leaflet-maps-marker/leaflet-api.php');
	
}

function checkin_add_custom_box() {
	add_meta_box('checkin_sectionid', __( 'checkin', 'myplugin_textdomain' ), 'checkin_inner_custom_box', 'post', 'advanced' );
}

function checkin_inner_custom_box() {
	echo '<input type="hidden" id="checkin_nonce" name="checkin_nonce" value="' . 
	wp_create_nonce(plugin_basename(__FILE__) ) . '" />';
	echo '
		<label class="screen-reader-text" for="checkin-address">checkin</label>
		<div id="checkin-map" style="border:solid 1px #c6c6c6;width:600px;height:400px;margin-top:5px;"></div>
		<div style="margin:5px 0 0 0;">			
			<label for="checkin-latitude">Lat</label>
			<input type="text" id="checkin-latitude" name="checkin-latitude" />
			<label for="checkin-longitude">Lon</label>
			<input type="text" id="checkin-longitude" name="checkin-longitude" />
			<label for="checkin-marker-id">Marker Id</label>
			<input type="text" id="checkin-marker-id" name="checkin-marker-id" value="1" />
			<div style="float:right">
				<input id="checkin-public" name="checkin-public" type="checkbox" value="1" />
				<label for="checkin-public">Public</label>
				<input id="checkin-enabled" name="checkin-on" type="radio" value="1" />
				<label for="checkin-enabled">On</label>
				<input id="checkin-disabled" name="checkin-on" type="radio" value="0" />
				<label for="checkin-disabled">Off</label>
			</div>
		</div>
	';
}

//This will work from the web page update
function checkin_save_postdata($post_id) {
  // Check authorization, permissions, autosave, etc
  //This wont happen from wordpress and hence removing it
  if (!wp_verify_nonce($_POST['checkin_nonce'], plugin_basename(__FILE__)))
		return $post_id;
  
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE){
		return $post_id;
  }
   if(wp_is_post_revision($post_id)){
		return $post_id;
	}
  
  if('page' == $_POST['post_type'] ) {
    if(!current_user_can('edit_page', $post_id))
		return $post_id;
  } else {
    if(!current_user_can('edit_post', $post_id)) 
		return $post_id;
  }
  
																																		
  $post = get_post($post_id);
  $latitude = clean_coordinate($_POST['checkin-latitude']);
  $longitude = clean_coordinate($_POST['checkin-longitude']);
  $marker_id = $_POST['checkin-marker-id'];
  //$marker_id = get_post_meta($post_id, 'checkin_marker_id', true);
  $address = reverse_geocode($latitude, $longitude);
  $public = $_POST['checkin-public'];
  $on = $_POST['checkin-on'];
  $api_endpoint = get_option('checkin_api_endpoint');
  $api_key = get_option('checkin_api_key');
  $expires = strtotime("+60 mins");
  $sign_key = calculate_signature($expires);
  $markername = $post->post_title; 
  $layer = get_option('checkin_layer_id');
  $zoom = get_option('checkin_default_zoom');
  $height = get_option('checkin_map_height');
  $width = get_option('checkin_map_width');
  $blog_url = get_bloginfo('url');
  $popuptext = '<a href="'.$blog_url.'?p='.$post_id.'">'.$post->post_title.'</a>';
  
	
  if((clean_coordinate($latitude) != '') && (clean_coordinate($longitude)) != '') {
  	update_post_meta($post_id, 'geo_latitude', $latitude);
  	update_post_meta($post_id, 'geo_longitude', $longitude);
  	
  	if(esc_html($address) != '')
  		update_post_meta($post_id, 'geo_address', $address);
  		
  	if($on) {
  		update_post_meta($post_id, 'geo_enabled', 1);
  		
	  	if($public)
	  		update_post_meta($post_id, 'geo_public', 1);
	  	else
	  		update_post_meta($post_id, 'geo_public', 0);
  	}
  	else {
  		update_post_meta($post_id, 'geo_enabled', 0);
  		update_post_meta($post_id, 'geo_public', 1);
  	}

	
	if( empty($marker_id) || $marker_id == '0' || $marker_id == '' || $marker_id == 0 ){
		//create the marker
		$body = array( 'key' => $api_key, 'signature' => $sign_key,'expires'=>$expires, 'action'=>'add','type'=>'marker','markername'=>$markername, 'lat'=> $latitude,'lon'=>$longitude,'layer'=>$layer,'zoom'=>$zoom,'mapwidth'=>$width,'mapheight'=>$height,'mapwidthunit'=>'%','popuptext'=>$popuptext);
		$response_data = wp_remote_post($api_endpoint, array('method' => 'POST', 'body'=>$body));
		//var_dump($api_endpoint);
		//var_dump($response_data);
		$json_data = jsonp_decode($response_data['body']);
		if($json_data->{'success'}){
			update_post_meta($post_id, 'checkin_marker_id', $json_data->{'data'}->{'id'});
		}
	}else
	{
		//update the marker
		$body = array( 'key' => $api_key, 'signature' => $sign_key,'expires'=>$expires, 'action'=>'update','type'=>'marker','markername'=>$markername, 'lat'=> $latitude,'lon'=>$longitude,'layer'=>$layer,'zoom'=>$zoom,'mapwidth'=>$width,'mapheight'=>$height,'mapwidthunit'=>'%','id'=>intval($marker_id));
		$response_data = wp_remote_post($api_endpoint, array('method' => 'POST', 'body'=>$body));
		update_post_meta($post_id, 'checkin_marker_id', $marker_id);
	}
	
	
  }
  
  return $post_id;
}



//This will work from the web page update
function checkin_save_mobile($post_id) {
  
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE){
		return $post_id;
	}
	
   if(wp_is_post_revision($post_id)){
		return $post_id;
	}

	$post = get_post($post_id);
	$latitude = clean_coordinate(get_post_meta($post_id, 'geo_latitude', true));
	$longitude = clean_coordinate(get_post_meta($post_id, 'geo_longitude', true));
	$address = get_post_meta($post_id, 'geo_address', true);
	$public = (bool)get_post_meta($post_id, 'geo_public', true);
	$marker_id = get_post_meta($post_id, 'checkin_marker_id', true); 
	
	$on = true;
	if(get_post_meta($post_id, 'geo_enabled', true) != '')
		$on = (bool)get_post_meta($post_id, 'geo_enabled', true);
	
	if(empty($address))
		$address = reverse_geocode($latitude, $longitude);

	$api_endpoint = get_option('checkin_api_endpoint');
	$api_key = get_option('checkin_api_key');
	$expires = strtotime("+60 mins");
	$sign_key = calculate_signature($expires);
	$markername =  $post->post_title;
	$blog_url = get_bloginfo('url');
	$popuptext = '<a href="'.$blog_url.'?p='.$post_id.'">'.$post->post_title.'</a>';
	$layer = get_option('checkin_layer_id');
	$zoom = get_option('checkin_default_zoom');
	$height = get_option('checkin_map_height');
	$width = get_option('checkin_map_width');
	
  
	if($latitude != '' && $longitude != '' && (!empty($latitude)) && (!empty($longitude)) && ( $marker_id == '0' || $marker_id == '' || $marker_id == 0)){

		$body = array( 'key' => $api_key, 'signature' => $sign_key,'expires'=>$expires, 'action'=>'add','type'=>'marker','markername'=>$markername, 'lat'=> $latitude,'lon'=>$longitude,'layer'=>$layer,'zoom'=>$zoom,'mapwidth'=>$width,'mapheight'=>$height,'mapwidthunit'=>'%','popuptext'=>$popuptext);
		$response_data = wp_remote_post($api_endpoint, array('method' => 'POST', 'body'=>$body));
		$json_data = jsonp_decode($response_data['body']);
		if($json_data->{'success'}){
			update_post_meta($post_id, 'checkin_marker_id', $json_data->{'data'}->{'id'});
		}
	}else{
		//update the marker
		$body = array( 'key' => $api_key, 'signature' => $sign_key,'expires'=>$expires, 'action'=>'update','type'=>'marker','markername'=>$markername, 'lat'=> $latitude,'lon'=>$longitude,'layer'=>$layer,'zoom'=>$zoom,'mapwidth'=>$width,'mapheight'=>$height,'mapwidthunit'=>'%','id'=>intval($marker_id));
		$response_data = wp_remote_post($api_endpoint, array('method' => 'POST', 'body'=>$body));
	}  
  return $post_id;
}



function admin_init() {
	add_action('admin_head-post-new.php', 'admin_head');
	add_action('admin_head-post.php', 'admin_head');
	add_action('admin_menu', 'checkin_add_custom_box');
	add_action('save_post', 'checkin_save_postdata');
	add_action('xmlrpc_publish_post', 'checkin_save_mobile');
	
}

function admin_head() {
	global $post;
	$post_id = $post->ID;
	$post_type = $post->post_type;
	$zoom = (int) get_option('checkin_default_zoom');
	?>
		<script type="text/javascript" src="http://www.google.com/jsapi"></script>
		<script type="text/javascript" src="http://maps.google.com/maps/api/js?sensor=true"></script>
		<script type="text/javascript">
		 	var $j = jQuery.noConflict();
			$j(function() {
				$j(document).ready(function() {
				    var hasLocation = false;
					var center = new google.maps.LatLng(0.0,0.0);
					var postLatitude =  '<?php echo esc_js(get_post_meta($post_id, 'geo_latitude', true)); ?>';
					var postLongitude =  '<?php echo esc_js(get_post_meta($post_id, 'geo_longitude', true)); ?>';
					var postmarkerId =  '<?php echo esc_js(get_post_meta($post_id, 'checkin_marker_id', true)); ?>';
					var public = '<?php echo get_post_meta($post_id, 'geo_public', true); ?>';
					var on = '<?php echo get_post_meta($post_id, 'geo_enabled', true); ?>';
					
					if(public == '0')
						$j("#checkin-public").attr('checked', false);
					else
						$j("#checkin-public").attr('checked', true);
					
					if(on == '0')
						disableGeo();
					else
						enableGeo();
					
					if((postLatitude != '') && (postLongitude != '')) {
						center = new google.maps.LatLng(postLatitude, postLongitude);
						hasLocation = true;
						$j("#checkin-latitude").val(center.lat());
						$j("#checkin-longitude").val(center.lng());
						$j("#checkin-marker-id").val(postmarkerId);
					}
						
				 	var myOptions = {
				      'zoom': <?php echo $zoom; ?>,
				      'center': center,
				      'mapTypeId': google.maps.MapTypeId.ROADMAP
				    };
				    var image = '<?php echo esc_js(esc_url(plugins_url('img/wp_pin.png', __FILE__ ))); ?>';
				    var shadow = new google.maps.MarkerImage('<?php echo esc_js(esc_url(plugins_url('img/wp_pin_shadow.png', __FILE__ ))); ?>',
						new google.maps.Size(39, 23),
						new google.maps.Point(0, 0),
						new google.maps.Point(12, 25));
						
				    var map = new google.maps.Map(document.getElementById('checkin-map'), myOptions);	
					var marker = new google.maps.Marker({
						position: center, 
						map: map, 
						title:'Post Location'<?php if(get_option('checkin_wp_pin')) { ?>,
						icon: image,
						shadow: shadow
					<?php } ?>
					});
					
					if((!hasLocation) && (google.loader.ClientLocation)) {
				      center = new google.maps.LatLng(google.loader.ClientLocation.latitude, google.loader.ClientLocation.longitude);
				      reverseGeocode(center);
				    }
				    else if(!hasLocation) {
				    	map.setZoom(1);
				    }
					

					
					var currentAddress;
					var customAddress = false;
					$j("#checkin-address").click(function(){
						currentAddress = $j(this).val();
						if(currentAddress != '')
							$j("#checkin-address").val('');
					});
					
					
					$j("#checkin-enabled").click(function(){
						enableGeo();
					});
					
					$j("#checkin-disabled").click(function(){
						disableGeo();
					});
									
					
					
					function geocode(address) {
						var geocoder = new google.maps.Geocoder();
					    if (geocoder) {
							geocoder.geocode({"address": address}, function(results, status) {
								if (status == google.maps.GeocoderStatus.OK) {
									placeMarker(results[0].geometry.location);
									if(!hasLocation) {
								    	map.setZoom(16);
								    	hasLocation = true;
									}
								}
							});
						}
						$j("#geodata").html(latitude + ', ' + longitude);
					}
					
					function reverseGeocode(location) {
						var geocoder = new google.maps.Geocoder();
					    if (geocoder) {
							geocoder.geocode({"latLng": location}, function(results, status) {
							if (status == google.maps.GeocoderStatus.OK) {
							  if(results[1]) {
							  	var address = results[1].formatted_address;
							  	if(address == "")
							  		address = results[7].formatted_address;
							  	else {
									$j("#checkin-address").val(address);
							  	}
							  }
							}
							});
						}
					}
					
					function enableGeo() {
						$j("#checkin-address").removeAttr('disabled');
						$j("#checkin-marker-id").removeAttr('disabled');
						$j("#checkin-latitude").removeAttr('disabled');
						$j("#checkin-longitude").removeAttr('disabled');
						$j("#checkin-load").removeAttr('disabled');
						$j("#checkin-map").css('filter', '');
						$j("#checkin-map").css('opacity', '');
						$j("#checkin-map").css('-moz-opacity', '');
						$j("#checkin-public").removeAttr('disabled');
						$j("#checkin-map").removeAttr('readonly');
						$j("#checkin-disabled").removeAttr('checked');
						$j("#checkin-enabled").attr('checked', 'checked');
						
						if(public == '1')
							$j("#checkin-public").attr('checked', 'checked');
					}
					
					function disableGeo() {
						$j("#checkin-address").attr('disabled', 'disabled');
						$j("#checkin-marker-id").attr('disabled', 'disabled');
						$j("#checkin-latitude").attr('disabled', 'disabled');
						$j("#checkin-longitude").attr('disabled', 'disabled');
						$j("#checkin-load").attr('disabled', 'disabled');
						$j("#checkin-map").css('filter', 'alpha(opacity=50)');
						$j("#checkin-map").css('opacity', '0.5');
						$j("#checkin-map").css('-moz-opacity', '0.5');
						$j("#checkin-map").attr('readonly', 'readonly');
						$j("#checkin-public").attr('disabled', 'disabled');
						
						$j("#checkin-enabled").removeAttr('checked');
						$j("#checkin-disabled").attr('checked', 'checked');
						
						if(public == '1')
							$j("#checkin-public").attr('checked', 'checked');
					}
				});
			});
		</script>
	<?php
}



function geo_has_shortcode($content) {
	$pos = strpos($content, SHORTCODE);
	if($pos === false)
		return false;
	else
		return true;
}

function display_location($content)  {
	default_settings();
	global $post, $shortcode_tags, $post_count;

	// Backup current registered shortcodes and clear them all out
	$orig_shortcode_tags = $shortcode_tags;
	$shortcode_tags = array();
	$post_id = $post->ID;
	$latitude = clean_coordinate(get_post_meta($post->ID, 'geo_latitude', true));
	$longitude = clean_coordinate(get_post_meta($post->ID, 'geo_longitude', true));
	$address = get_post_meta($post->ID, 'geo_address', true);
	$public = (bool)get_post_meta($post->ID, 'geo_public', true);
	$marker_id = get_post_meta($post->ID, 'checkin_marker_id', true); 
	
	
	
	$on = true;
	if(get_post_meta($post->ID, 'geo_enabled', true) != '')
		$on = (bool)get_post_meta($post->ID, 'geo_enabled', true);
	
	if(empty($address))
		$address = reverse_geocode($latitude, $longitude);
	
	if((!empty($latitude)) && (!empty($longitude) && ($public == true) && ($on == true))  && ($marker_id) != '0' && (!empty($marker_id)) ) {
		$html =  do_shortcode('[mapsmarker marker="'.$marker_id.'"]');
		
		
		switch(esc_attr(get_option('checkin_map_position')))
		{
			case 'before':
				$content = str_replace(SHORTCODE, '', $content);
				$content = $html.'<br/><br/>'.$content;
				break;
			case 'after':
				$content = str_replace(SHORTCODE, '', $content);
				$content = $content.'<br/><br/>'.$html;
				break;
			case 'shortcode':
				$content = str_replace(SHORTCODE, $html, $content);
				break;
		}
	}
	else {
		$content = str_replace(SHORTCODE, '', $content);
	}

	// Put the original shortcodes back
	$shortcode_tags = $orig_shortcode_tags;
	
    return $content;
}

function reverse_geocode($latitude, $longitude) {
	$url = "http://maps.google.com/maps/api/geocode/json?latlng=".$latitude.",".$longitude."&sensor=false";
	$result = wp_remote_get($url);
	$json = json_decode($result['body']);
	foreach ($json->results as $result)
	{
		foreach($result->address_components as $addressPart) {
			if((in_array('locality', $addressPart->types)) && (in_array('political', $addressPart->types)))
	    		$city = $addressPart->long_name;
	    	else if((in_array('administrative_area_level_1', $addressPart->types)) && (in_array('political', $addressPart->types)))
	    		$state = $addressPart->long_name;
	    	else if((in_array('country', $addressPart->types)) && (in_array('political', $addressPart->types)))
	    		$country = $addressPart->long_name;
		}
	}
	
	if(($city != '') && ($state != '') && ($country != ''))
		$address = $city.', '.$state.', '.$country;
	else if(($city != '') && ($state != ''))
		$address = $city.', '.$state;
	else if(($state != '') && ($country != ''))
		$address = $state.', '.$country;
	else if($country != '')
		$address = $country;
		
	return $address;
}

function clean_coordinate($coordinate) {
	$pattern = '/^(\-)?(\d{1,3})\.(\d{1,15})/';
	preg_match($pattern, $coordinate, $matches);
	return $matches[0];
}

function add_settings() {
	if ( is_admin() ){ // admin actions
		add_options_page('CheckIn Plugin Settings', 'CheckIn', 'administrator', 'checkin.php', 'checkin_settings_page', __FILE__);
  		add_action( 'admin_init', 'register_settings' );
	} else {
	  // non-admin enqueues, actions, and filters
	}
}

function register_settings() {
  register_setting( 'checkin-settings-group', 'checkin_map_width', 'intval' );
  register_setting( 'checkin-settings-group', 'checkin_map_height', 'intval' );
  register_setting( 'checkin-settings-group', 'checkin_default_zoom', 'intval' );
  register_setting( 'checkin-settings-group', 'checkin_map_position' );
  register_setting( 'checkin-settings-group', 'checkin_layer_id', 'intval');
  register_setting( 'checkin-settings-group', 'checkin_api_key');
  register_setting( 'checkin-settings-group', 'checkin_api_pvt_key');
  register_setting( 'checkin-settings-group', 'checkin_api_endpoint');
  
}

function is_checked($field) {
	if (get_option($field))
 		echo ' checked="checked" ';
}

function is_value($field, $value) {
	if (get_option($field) == $value) 
 		echo ' checked="checked" ';
}

function default_settings() {
	if(get_option('checkin_map_width') == '0')
		update_option('checkin_map_width', '600');
		
	if(get_option('checkin_map_height') == '0')
		update_option('checkin_map_height', '400');
		
	if(get_option('checkin_default_zoom') == '0')
		update_option('checkin_default_zoom', '16');
		
	if(get_option('checkin_map_position') == '0')
		update_option('checkin_map_position', 'shortcode');
		
	if(get_option('checkin_layer_id') == '0')
		update_option('checkin_layer_id', '1');
		

}

function checkin_settings_page() {
	default_settings();
	$zoomImage = get_option('checkin_default_zoom');
	if(get_option('checkin_wp_pin'))
		$zoomImage = 'wp_'.$zoomImage.'.png';
	else
		$zoomImage = $zoomImage.'.png';
	?>
	<style type="text/css">
		#zoom_level_sample { background: url('<?php echo esc_url(plugins_url('img/zoom/'.$zoomImage, __FILE__)); ?>'); width:390px; height:190px; border: solid 1px #999; }
		#preload { display: none; }
		.dimensions strong { width: 50px; float: left; }
		.dimensions input { width: 50px; margin-right: 5px; }
		.zoom label { width: 50px; margin: 0 5px 0 2px; }
		.position label { margin: 0 5px 0 2px; }
	</style>
	<script type="text/javascript">
		var file;
		var zoomlevel = <?php echo (int) esc_attr(get_option('checkin_default_zoom')); ?>;
		var path = '<?php echo esc_js(plugins_url('img/zoom/', __FILE__)); ?>';
		function swap_zoom_sample(id) {
			zoomlevel = document.getElementById(id).value;
			pin_click();
		}
		
		function pin_click() {
			var div = document.getElementById('zoom_level_sample');
			file = path + zoomlevel + '.png';
			if(document.getElementById('checkin_wp_pin').checked)
				file = path + 'wp_' + zoomlevel + '.png';
			div.style.background = 'url(' + file + ')';
		}
	</script>
	<div class="wrap"><h2>checkin Plugin Settings</h2></div>
	
	<form method="post" action="options.php">
    <?php settings_fields( 'checkin-settings-group' ); ?>
    <table class="form-table">
        <tr valign="top">
	        <tr valign="top">
	        <th scope="row">Dimensions</th>
	        <td class="dimensions">
	        	<strong>Width:</strong><input type="text" name="checkin_map_width" value="<?php echo esc_attr(get_option('checkin_map_width')); ?>" />%<br/>
	        	<strong>Height:</strong><input type="text" name="checkin_map_height" value="<?php echo esc_attr(get_option('checkin_map_height')); ?>" />px
	        </td>
        </tr>
        <tr valign="top">
        	<th scope="row">Position</th>
        	<td class="position">        	
				<input type="radio" id="checkin_map_position_before" name="checkin_map_position" value="before"<?php is_value('checkin_map_position', 'before'); ?>><label for="checkin_map_position_before">Before the post.</label><br/>
				
				<input type="radio" id="checkin_map_position_after" name="checkin_map_position" value="after"<?php is_value('checkin_map_position', 'after'); ?>><label for="checkin_map_position_after">After the post.</label><br/>
				<input type="radio" id="checkin_map_position_shortcode" name="checkin_map_position" value="shortcode"<?php is_value('checkin_map_position', 'shortcode'); ?>><label for="checkin_map_position_shortcode">Wherever I put the <strong>[checkin]</strong> shortcode.</label>
	        </td>
        </tr>
        <tr valign="top">
	        <th scope="row">Default Zoom Level</th>
	        <td class="zoom">        	
				<input type="radio" id="checkin_default_zoom_globe" name="checkin_default_zoom" value="1"<?php is_value('checkin_default_zoom', '1'); ?> onclick="javascipt:swap_zoom_sample(this.id);"><label for="checkin_default_zoom_globe">Globe</label>				
				<input type="radio" id="checkin_default_zoom_country" name="checkin_default_zoom" value="3"<?php is_value('checkin_default_zoom', '3'); ?> onclick="javascipt:swap_zoom_sample(this.id);"><label for="checkin_default_zoom_country">Country</label>
				<input type="radio" id="checkin_default_zoom_state" name="checkin_default_zoom" value="6"<?php is_value('checkin_default_zoom', '6'); ?> onclick="javascipt:swap_zoom_sample(this.id);"><label for="checkin_default_zoom_state">State</label>
				<input type="radio" id="checkin_default_zoom_city" name="checkin_default_zoom" value="9"<?php is_value('checkin_default_zoom', '9'); ?> onclick="javascipt:swap_zoom_sample(this.id);"><label for="checkin_default_zoom_city">City</label>
				<input type="radio" id="checkin_default_zoom_street" name="checkin_default_zoom" value="16"<?php is_value('checkin_default_zoom', '16'); ?> onclick="javascipt:swap_zoom_sample(this.id);"><label for="checkin_default_zoom_street">Street</label>
				<input type="radio" id="checkin_default_zoom_block" name="checkin_default_zoom" value="18"<?php is_value('checkin_default_zoom', '18'); ?> onclick="javascipt:swap_zoom_sample(this.id);"><label for="checkin_default_zoom_block">Block</label>
				<br/>
				<div id="zoom_level_sample"></div>
	        </td>
        </tr>
        <tr valign="top">
        	<th scope="row"></th>
        	<td class="position">        	
				<input type="checkbox" id="checkin_wp_pin" name="checkin_wp_pin" value="1" <?php is_checked('checkin_wp_pin'); ?> onclick="javascript:pin_click();"><label for="checkin_wp_pin">Show your support for WordPress by using the WordPress map pin.</label>
	        </td>
        </tr>
		<tr valign="top">
	        <th scope="row">Layer ID</th>
	        <td class="dimensions">
	        	<input type="text" name="checkin_layer_id" value="<?php echo esc_attr(get_option('checkin_layer_id')); ?>" /><br/>
	        </td>
        </tr>
		<tr valign="top">
	        <th scope="row">Public Public Key</th>
	        <td class="dimensions">
	        	<input type="text" name="checkin_api_key"  style="width:300px;"  value="<?php echo esc_attr(get_option('checkin_api_key')); ?>" /><br/>
	        </td>
        </tr>
		<tr valign="top">
	        <th scope="row">Private API key</th>
	        <td class="dimensions">
				<input type="text" name="checkin_api_pvt_key" style="width:300px;"  value="<?php echo esc_attr(get_option('checkin_api_pvt_key')); ?>" /><br/>
	        </td>
        </tr>
		<tr valign="top">
	        <th scope="row">API Endpoint</th>
	        <td class="dimensions">
				<input type="text" name="checkin_api_endpoint" style="width:300px;" value="<?php echo esc_attr(get_option('checkin_api_endpoint')); ?>" />
	        </td>
        </tr>
		
		
    </table>
    
    <p class="submit">
    <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
    </p>
	<input type="hidden" name="action" value="update" />
	<input type="hidden" name="page_options" value="checkin_map_width,checkin_map_height,checkin_default_zoom,checkin_map_position,checkin_wp_pin,checkin_layer_id,checkin_api_key,checkin_api_pvt_key,checkin_api_endpoint" />
</form>
	<div id="preload">
		<img src="<?php echo esc_url(plugins_url('img/zoom/1.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/3.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/6.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/9.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/16.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/18.png', __FILE__)); ?>"/>
		
		<img src="<?php echo esc_url(plugins_url('img/zoom/wp_1.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/wp_3.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/wp_6.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/wp_9.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/wp_16.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/wp_18.png', __FILE__)); ?>"/>
	</div>
	<?php
}

?>
