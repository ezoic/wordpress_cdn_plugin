<?php
/**
 * Admin Page for Ezoic CDN Manager Plugin
 *
 * @since 1.0.0
 * @version 1.1.2
 * @package ezoic-cdn-manager
 * @author Ezoic Inc
 */

?>
<div style="margin-top:100px;">
<form action="options.php" method="post">
	<a href="https://www.ezoic.com/"><img src="<?php echo esc_url( plugin_dir_url( __FILE__ ) ); ?>ezoic-transparent.png" alt="Ezoic" title="Ezoic" width="400" height="84" /></a>

	<p><em>To use the Ezoic CDN you'll need access to the CDN API, talk with your Optimization Specialist about
	enabling access to the API on your account.  Once you have the CDN API enabled, you can
	<a href="https://pubdash.ezoic.com/settings/apigateway/app?action=load" target="_blank">find your API key here</a>.</em></p>

	<?php
		settings_fields( 'ezoic_cdn' );
		do_settings_sections( 'ezoic_cdn' );
		submit_button();
	?>
</form>
</div>
