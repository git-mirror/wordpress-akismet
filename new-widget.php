<?php
/**
 * @package Akismet
 */
class Akismet_New_Widget extends WP_Widget {

	function __construct() {
		parent::__construct(
			'akismet_new_widget',
			'Akismet New Widget',
			array( 'description' => __( 'Display the number of spam comments Akismet has caught' ) )
		);

		if ( is_active_widget( false, false, $this->id_base ) ) {
			add_action( 'wp_head', 'Akismet_New_Widget::css' );
		}
	}

	function css() {
?>

<style type="text/css">
#aka,#aka:link,#aka:hover,#aka:visited,#aka:active{color:#fff;text-decoration:none}
#aka:hover{border:none;text-decoration:none}
#aka:hover #akismet1{display:none}
#aka:hover #akismet2,#akismet1{display:block}
#akismet2{display:none;padding-top:2px}
#akismeta{font-size:16px;font-weight:bold;line-height:18px;text-decoration:none}
#akismetcount{display:block;font:15px Verdana,Arial,Sans-Serif;font-weight:bold;text-decoration:none}
#akismetwrap #akismetstats{background:url('<?php echo plugin_dir_url( __FILE__ ); ?>akismet.gif') no-repeat top left;border:none;color:#fff;font:11px 'Trebuchet MS','Myriad Pro',sans-serif;height:40px;line-height:100%;overflow:hidden;padding:8px 0 0;text-align:center;width:120px}
</style>

<?php
	}

	function form( $instance ) {
		if ( $instance ) {
			$title = esc_attr( $instance['title'] );
		}
		else {
			$title = __( 'Spam Blocked' );
		}
?>

		<p>
		<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:' ); ?></label> 
		<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo $title; ?>" />
		</p>

<?php 
	}

	function update( $new_instance, $old_instance ) {
		$instance['title'] = strip_tags( $new_instance['title'] );
		return $instance;
	}

	function widget( $args, $instance ) {
		$count = get_option( 'akismet_spam_count' );

		echo $args['before_widget'];
		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'];
			echo esc_html( $instance['title'] );
			echo $args['after_title'];
		}
?>

	<div id="akismetwrap">
		<div id="akismetstats">
			<a id="aka" href="http://akismet.com" title=""><?php
				printf(
					_n(
						'%1$s%2$s%3$s %4$sspam comment%5$s %6$sblocked by%7$s<br />%8$sAkismet%9$s',
						'%1$s%2$s%3$s %4$sspam comments%5$s %6$sblocked by%7$s<br />%8$sAkismet%9$s',
						$count
					),
					'<span id="akismet1">',
						'<span id="akismetcount">',
							number_format_i18n( $count ),
						'</span>',
						'<span id="akismetsc"></span></span>',
					'<span id="akismet2">',
						'<span id="akismetbb"></span>',
						'<span id="akismeta">',
					'</span></span>'
				); 
			?></a>
		</div>
	</div>

<?php
		echo $args['after_widget'];
	}
}

function akismet_register_widgets() {
	register_widget( 'Akismet_New_Widget' );
}

add_action( 'widgets_init', 'akismet_register_widgets' );
