<?php
/**
 * Plugin Name: Offbeat Content Flagging
 * Description: Content flagging via email for Buddypress
 * Version: 1
 * Author: Kellbot
 * License: A short license name. Example: GPL2
 */

class OffbeatContentFlagging {

	function __construct(){
		add_action('wp_enqueue_scripts', array( $this, 'load_scripts' ), 12);

		//ajax targets
		add_action( 'wp_ajax_ocf_send_flag', array( $this, 'send_flag'));

		//the buttons themselves
		add_filter( 'comment_text', array( $this, 'append_button_for_comment'));

		add_filter( 'bbp_get_reply_content', array( $this, 'append_button_for_post') );

	}


	function load_scripts(){
		//we only need our scripts on flaggable views
		if ( is_singular( array( 'post', 'topic', 'reply' )) ) {
			wp_enqueue_script('jquery-ui-dialog', null, array( 'bootstrap' ));
			wp_enqueue_style( 'jquery-ui-south-street', 'https://code.jquery.com/ui/1.10.4/themes/south-street/jquery-ui.css');
		}
		if (is_singular('post')) {
			add_filter( 'the_content', array( $this, 'append_button_for_post') );
		}

		$is_topic = false;
		if (function_exists('bb_is_topic'))
			$is_topic = bb_is_topic();

		if (is_single() || $is_topic) {
			//add our dialog box to the footer
			add_action('wp_footer', array( $this, 'output_flagging_form' ));
		}

	}

	//output a generic flagging form for the page
	function output_flagging_form() {
		?>
		<script>
		$.fn.serializeObject = function()
		{
		   var o = {};
		   var a = this.serializeArray();
		   $.each(a, function() {
		       if (o[this.name]) {
		           if (!o[this.name].push) {
		               o[this.name] = [o[this.name]];
		           }
		           o[this.name].push(this.value || '');
		       } else {
		           o[this.name] = this.value || '';
		       }
		   });
		   return o;
		};
		  $(function() {
		    
		    $( "#dialog-flag" ).dialog({
		      autoOpen: false,
		      modal: true,
		      width: 500,
		      buttons: {
		        Send: function() {

		        	var data = $('#flag-form').serializeObject();
		        	data['action'] = 'ocf_send_flag';

		         	$.ajax({
		         		url: ajaxurl, 
		         		type: "POST",
		         		data: data, 
		         		success: function(response) {
			          		$('#dialog-flag').dialog("close");
			          		//reset the form
			          		$('#flag-error').html('');
			          		$('#flag-comments').val('');

			        	},
			        	error: function(response) {
			        		response = JSON.parse(response.responseText);
			        		$.each( response, function(name, message) {
			        			$('#flag-error').append(message+"<br />").show();

			        		});
			        	}
			        });
		        }
		      }
		    });
		    $( '.flag-link' ).click(function(){
		    	var title = $(this).data('title');
				var author = $(this).data('author');
		    	var flagged_object_id = $(this).data('object-id'); 
		    	var flagged_comment_id = $(this).data('comment-id'); 
		    	$( '#flagged-object-title').html(title);
		    	$( '#flagged-object-author').html(author);
		    	$( '#flagged-object-id').val(flagged_object_id);
		    	$( '#flagged-comment-id').val(flagged_comment_id);
		    	$( '#dialog-flag').dialog('open');
		    	return false;
		    });
		  });
		  </script>
		  <div id="dialog-flag" title="Flag for a moderator">
		  		<p>The moderation team does their best but we also rely on our members to help us keep an eye on the community and let us know when they see content that doesn't adhere 
		  			to our <a href="/help/code-of-conduct">Code of Conduct</a>. If you think this post is objectionable, please let us know why in the comments, and we'll look at it ASAP.</p>
		  		<form id="flag-form" method="post">
			  		<label>Item</label><br />
			  		<span id="flagged-object-title"></span> by <span id="flagged-object-author"></span>
			  		<br />
			  		<label for="flag_comments">Comments</label>
			  		<div id="flag-error" class="alert alert-danger" style="display: none;"></div>
			  		<textarea id="flag-comments" name="flag_comments"></textarea>
			  		<input id="flagged-object-id" type="hidden" name="object_id" />
			  		<input id="flagged-comment-id" type="hidden" name="comment_id" />
			  		<input id="flagger-id" type="hidden" name="flagger_id" value="<?php echo get_current_user_id( ); ?>" />
			  	</form>
		  </div>
  		<?
	}

	function append_button_for_post($content) {
		$flagged_object_id = get_the_ID();
		$content .= $this->get_button_html($flagged_object_id);
		return $content;
	}


	function append_button_for_comment($content) {
		$flagged_object_id = get_comment_ID();
		$content .= $this->get_comment_button_html($flagged_object_id);
		return $content;
	}

	function get_button_html($flagged_object_id) {
		$title = get_the_title($flagged_object_id);
		$author = get_the_author($flagged_object_id);
		return '<div class="btn btn-warning flag"><a  id="flag-'.$flagged_object_id.'" class="flag-link" href="#" data-object-id="'.$flagged_object_id.'" data-title="'.$title.'" data-author="'.$author.'">Flag</a></div>';

	}

	function get_comment_button_html($flagged_object_id) {
		$title = 'Comment on ' . get_the_title();
		$author = get_comment_author();
		return '<div class="btn btn-warning flag"><a  id="flag-'.$flagged_object_id.'" class="flag-link" href="#" data-comment-id="'.$flagged_object_id.'" data-title="'.$title.'" data-author="'.$author.'">Flag</a></div>';

	}

	//email a flag to administrator
	function send_flag(){
		$object_id = intval( $_POST['object_id']);
		$comment_id = intval( $_POST['comment_id']);
		$comments = sanitize_text_field( $_POST['flag_comments'] );
		$flagger_id = intval( $_POST['flagger_id'] );

		//error checking
		$errors = array();
		if(empty($object_id) && empty( $comment_id )) {
			$errors['object_id'] = "No object ID sent";
		}
		if(empty($flagger_id)) {
			$errors['flagger_id'] = "No flagger ID sent";
		}
		if(empty($comments)) {
			$errors['flag_comments'] = "Please enter a comment";
		}

		//send errors if we have them
		if($errors) {
			status_header(400);
			echo json_encode($errors);
		}

		if ($object_id) {
			$success = $this->send_flag_email($object_id, $comments, $flagger_id);
		} else if( $comment_id ) {
			$success = $this->send_flag_email($comment_id, $comments, $flagger_id, true);
		}
		if ($success) {
			wp_send_json_success();
		}

		wp_die();
	}


	/* Constructs and sends the email
	send_flag_email( int $post_id, string $comments, int $flagger_id, bool $is_comment )
	*/
	function send_flag_email($object_id, $comments, $flagger_id, $is_comment = false){

		if ($is_comment) {
			$comment = get_comment($object_id);
			$comment_text = "A comment by " . $comment->comment_author. ' on ';
			$object = get_post( $comment->comment_post_ID );
			$permalink = get_comment_link($comment);
		} else {
			$object = get_post( $object_id );
			$permalink = get_permalink($object);
		}

		$post_type =  $object->post_type;

		//give replies a title
		if ( $post_type == 'reply') {
			$comment_text = "On the forum thread ";
			$title = bbp_get_reply_topic_title($object_id);
		} else {
			$title = $object->post_title;		
		}

		$author_id = $object->post_author;
		$author = get_userdata($author_id);
		$author_name = $author->user_login;
		$flagger = get_userdata($flagger_id);
		$flagger_name = $flagger->user_login;
		$admin_email = get_option( 'admin_email' );


		$template = $comment_text .
"\"%title%\", a %type% by %author% has been flagged by %flagger% \n
%link% \n
Comments: %comments%";
		$message = str_replace(
			array('%title%', '%flagger%', '%link%', '%comments%', '%type%', '%author%'), 
			array($title, $flagger_name, $permalink, stripslashes($comments), $post_type, $author_name), 
			$template);
		wp_mail($admin_email, "New flag on ".$title, $message);

		return true;
	}

}

new OffbeatContentFlagging;