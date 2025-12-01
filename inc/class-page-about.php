<?php
/**
 * Render plugin about Page.
 */
class BackWPup_Page_About
{
    /**
     * Enqueue style.
     */
    public static function admin_print_styles()
    {
        ?>
		<style type="text/css" media="screen">
			#backwpup-page {
				background: #fff;
				margin-top: 22px;
				padding: 0 20px;
			}
			#backwpup-page .inpsyde + h2 {
				visibility: hidden;
			}
			.welcome {
/* 				max-width: 960px; */
			}
			.welcome .welcome_inner {
				margin:0 auto;
				max-width: 960px;
			}
			.welcome .welcome_inner h1{
				font-size:42px;
			}
			.welcome .welcome_inner .welcometxt {
				margin-bottom: 40px;
				overflow: hidden;
				border-bottom: 1px #ccc dotted;
				text-align: center;
				padding-bottom: 25px;
				position: relative;
			}
			.welcome .welcome_inner .welcometxt p{
				line-height:20px;
				font-size:18px;
			}
			.welcome .welcome_inner .feature-box{
				clear: both;
				margin-bottom: 40px;
				overflow: hidden;
			}
			.welcome .welcome_inner .feature-box .feature-image{
				float: left;
				width:18%;
				height:auto;
			}
			.welcome .welcome_inner .feature-box .feature-image img{
				width:100%;
				height:auto;
				max-width:350px;
			}
			.welcome .welcome_inner .feature-box .feature-text{
				float: left;
				width:72%;
				padding: 0 0 20px 20px;
			}
			.welcome .welcome_inner .feature-box-right .feature-text {
				padding: 0 20px 20px 0;
			}
			.welcome .welcome_inner .feature-box .feature-text h3{
				color:rgb(0, 155, 204);
				font-weight:normal;
				font-size:24px;
				margin:0 0 10px 0;
				text-align:left;
			}
			.welcome .welcome_inner .feature-box .left {
				float:left;
			}
			.welcome .welcome_inner .feature-box .right {
				float:right;
			}
			.welcome .welcome_inner .featuretitle h3 {
				font-size:28px;
				font-weight:normal;
				text-align:left;
				margin-bottom:25px;
			}
			.welcome .button-primary-bwp {
				float:left;
				padding:15px;
				font-size:18px;
				text-decoration:none;
				background-color:#38b0eb;
				color:#fff;
				border:none;
				cursor:pointer;
				margin: 35px 0;
			}
			.welcome .button-primary-bwp:hover {
				background-color:#064565;
				cursor:pointer;
			}
			@media only screen and (max-width: 1100px), only screen and (max-device-width: 1100px) {
				.welcome .welcome_inner h1{
					font-size:32px;
				}
				.welcome .welcome_inner .featuretitle h3 {
					font-size:22px;
					font-weight:normal;
					text-align:left;
					margin-bottom:25px;
				}
				.welcome .welcome_inner .welcometxt p{
					line-height:20px;
					font-size:14px;
				}
				.welcome .welcome_inner .feature-box .feature-text h3{
					font-weight:normal;
					font-size:20px;
					margin:0 0 10px 0;
					text-align:left;
				}
				.welcome .welcome_inner .feature-box .feature-text{
					width:72%;
					font-size:14px;
					line-height:20px;
				}
				.welcome .button-primary-bwp {
					float:left;
					padding:10px;
					font-size:16px;
					text-decoration:none;
					background-color:#38b0eb;
					color:#fff;
					border:none;
					cursor:pointer;
					margin: 35px 0;
				}
			}
			@media only screen and (max-width: 780px), only screen and (max-device-width: 780px) {
				.welcome .welcome_inner h1{
					font-size:22px;
				}
				.welcome .welcome_inner .featuretitle h3 {
					font-size:22px;
					font-weight:normal;
					text-align:left;
				}
				.welcome .welcome_inner .welcometxt p{
					line-height:20px;
					font-size:14px;
				}
				.welcome .welcome_inner .feature-box .feature-text h3{
					font-weight:normal;
					font-size:16px;
					margin:0 0 10px 0;
				}
				.welcome .welcome_inner .feature-box .feature-text{
					width:72%;
					font-size:12px;
					line-height:16px;
					text-align:left;
				}
				.welcome .button-primary-bwp {
					float:left;
					padding:10px;
					font-size:16px;
					text-decoration:none;
					background-color:#38b0eb;
					color:#fff;
					border:none;
					cursor:pointer;
					margin: 35px 0;
				}
			}

			.backwpup_comp {
				margin: 20px auto;
				width: 100%;
				font-family: "HelveticaNeue-Light", "Helvetica Neue Light", "Helvetica Neue", Helvetica, Arial, "Lucida Grande", sans-serif;
				font-size: 16px;
			}

			.backwpup_comp table {
				border: none;
			}

			.backwpup_comp table tbody tr.even td {
				border: none;
				background: none;
				padding: 15px;
				margin: 0;
			}

			.backwpup_comp table tbody tr.odd td {
				border: none;
				background: none;
				padding: 15px;
				margin: 0;
			}

			.backwpup_comp h3 {
				font-family: "Arial", sans-serif;
				font-size: 42px;
				text-align: center;
				font-weight: normal;
				color: #333;
				line-height: 44px;
				margin: 20px 0;
			}

			.backwpup_comp table tbody tr.ub {
				font-family: 'MisoRegular', "HelveticaNeue-Light", "Helvetica Neue Light", "Helvetica Neue", Helvetica, Arial, "Lucida Grande", sans-serif;
				font-size: 26px;
			}

			.backwpup_comp table tbody tr.ubdown {
				font-family: 'MisoRegular', "HelveticaNeue-Light", "Helvetica Neue Light", "Helvetica Neue", Helvetica, Arial, "Lucida Grande", sans-serif;
				font-size: 26px;
				background: none !important;
			}

			.backwpup_comp table tbody tr.even {
				background-image: url(<?php echo BackWPup::get_plugin_data('URL'); ?>/assets/images/even.png);
			}

			.backwpup_comp table tbody tr.odd {
				background-image: url(<?php echo BackWPup::get_plugin_data('URL'); ?>/assets/images/odd.png);
			}

			.backwpup_comp table tbody tr.ub td.pro {
				height: 50px;
				text-align: center;
				background: url(<?php echo BackWPup::get_plugin_data('URL'); ?>/assets/images/hgtopgreen.png) no-repeat bottom center;
				color: #fff;
				border-left: 1px solid #112a32;
			}

			.backwpup_comp table tbody tr.ub td.free {
				height: 50px;
				text-align: center;
				background: url(<?php echo BackWPup::get_plugin_data('URL'); ?>/assets/images/hgtopgreen.png) no-repeat bottom center;
				color: #fff;
			}

			.backwpup_comp table tbody tr.ubdown td.pro {
				height: 50px;
				text-align: center;
				background: url(<?php echo BackWPup::get_plugin_data('URL'); ?>/assets/images/hgbtgreen.png) no-repeat top center;
				color: #fff;
				border-left: 1px solid #1c3e49;
				background-color: #153540;
			}

			.backwpup_comp table tbody tr.ubdown td.pro a {
				color: #fff;
				text-decoration: none;
				cursor: auto;
				font-weight: 300;
				line-height: 1.4em;
				font-size: 18px;
			}

			.backwpup_comp table tbody tr.ubdown td.free {
				height: 50px;
				text-align: center;
				background: url(<?php echo BackWPup::get_plugin_data('URL'); ?>/assets/images/hgbtred.png) no-repeat top center;
				color: #fff;
			}

			.backwpup_comp table tbody tr.ubdown td.free a {
				color: #fff;
			}

			.backwpup_comp table tbody tr.even td.tick {
				width: 100px;
				background: url(<?php echo BackWPup::get_plugin_data('URL'); ?>/assets/images/tickeven.png) no-repeat center;
				border-bottom: 1px solid #799e14;
				border-top: 1px solid #a2d123;
				border-left: 1px solid #799e14;
			}

			.backwpup_comp table tbody tr.odd td.tick {
				width: 100px;
				background: url(<?php echo BackWPup::get_plugin_data('URL'); ?>/assets/images/tickodd.png) no-repeat center;
				border-left: 1px solid #799e14;
			}

			.backwpup_comp table tbody tr.even td.error {
				width: 100px;
				background: url(<?php echo BackWPup::get_plugin_data('URL'); ?>/assets/images/erroreven.png) no-repeat center;
				border-bottom: 1px solid #b13020;
				border-top: 1px solid #e84936;
			}

			.backwpup_comp table tbody tr.odd td.error {
				width: 100px;
				background: url(<?php echo BackWPup::get_plugin_data('URL'); ?>/assets/images/errorodd.png) no-repeat center;
			}

			.backwpup_comp table tbody tr.even:hover {
				background-image: url(<?php echo BackWPup::get_plugin_data('URL'); ?>/assets/images/hover.png);
			}

			.backwpup_comp table tbody tr.odd:hover {
				background-image: url(<?php echo BackWPup::get_plugin_data('URL'); ?>/assets/images/hover.png);
			}

			.backwpup_comp table tbody tr.even:hover td.tick {
				background: url(<?php echo BackWPup::get_plugin_data('URL'); ?>/assets/images/tickhover.png) center;
			}

			.backwpup_comp table tbody tr.odd:hover td.tick {
				background: url(<?php echo BackWPup::get_plugin_data('URL'); ?>/assets/images/tickhover.png) center;
			}

			.backwpup_comp table tbody tr.even:hover td.error {
				background: url(<?php echo BackWPup::get_plugin_data('URL'); ?>/assets/images/errorhover.png) center;
			}

			.backwpup_comp table tbody tr.odd:hover td.error {
				background: url(<?php echo BackWPup::get_plugin_data('URL'); ?>/assets/images/errorhover.png) center;
			}

			.backwpup_comp table tbody tr.ubdown:hover td.pro:hover {
				background: url(<?php echo BackWPup::get_plugin_data('URL'); ?>/assets/images/hgbtgreenhover.png) no-repeat top center;
				background-color: #255667;
			}

			.backwpup_comp table tbody tr.ubdown:hover td.free:hover {
				background: url(<?php echo BackWPup::get_plugin_data('URL'); ?>/assets/images/hgbtredhover.png) no-repeat top center;
			}
		</style>
		<?php
    }

    /**
     * Enqueue script.
     */
    public static function admin_print_scripts()
    {
        wp_enqueue_script('backwpupgeneral');
    }

    /**
     * Print the markup.
     */
    public static function page()
    {
        ?>
        <div class="wrap" id="backwpup-page">
			<?php BackWPup_Admin::display_messages(); ?>
            <div class="welcome">
            	<div class="welcome_inner">
                    <div class="welcometxt">
                        <div class="backwpup-welcome">
                            <h1><?php esc_html_e('Bienvenue sur Agence Oueb Backup', 'backwpup'); ?></h1>
                            <p class="about-text"><?php esc_html_e('Cette extension est une version optimisée et maintenue par l\'Agence Oueb pour assurer la sécurité de vos sites WordPress.', 'backwpup'); ?></p>
                            
                            <div class="ao-header-banner" style="margin-top: 20px; padding: 30px; background: #003366; color: white; border-radius: 8px; text-align: center;">
                                <h2 style="color: white; margin-bottom: 15px;">Besoin d'aide ou de maintenance ?</h2>
                                <p style="font-size: 16px; margin-bottom: 20px;">L'Agence Oueb vous propose des services complets de maintenance, dépannage, hébergement et assistance WordPress.</p>
                                <a href="https://wpservices.fr" target="_blank" class="button button-primary button-hero" style="background: #ff6600; border-color: #ff6600;">Découvrir nos offres sur WPservices.fr</a>
                            </div>
                        </div>
                    </div>

            		<div class="features" style="margin-top: 40px;">
                        <h2 style="text-align: center; margin-bottom: 30px;">Pourquoi choisir l'Agence Oueb ?</h2>
                        
                    	<div class="feature-box <?php self::feature_class(); ?>">
                        	<div class="feature-image">
                                <!-- Placeholder for icon -->
                            </div>
                            <div class="feature-text">
                            	<h3><?php esc_html_e('Expertise WordPress', 'backwpup'); ?></h3>
                                <p><?php esc_html_e('Nous sommes spécialisés dans la maintenance et le développement sur mesure pour WordPress.', 'backwpup'); ?></p>
                            </div>
                        </div>
                        
                        <div class="feature-box <?php self::feature_class(); ?>">
                            <div class="feature-text">
                            	<h3><?php esc_html_e('Sauvegardes Sécurisées', 'backwpup'); ?></h3>
                                <p><?php esc_html_e('Ne perdez plus jamais vos données. Nos solutions de sauvegarde sont fiables et automatisées.', 'backwpup'); ?></p>
                            </div>
                        </div>
                        
                        <div class="feature-box <?php self::feature_class(); ?>">
                            <div class="feature-text">
                            	<h3><?php esc_html_e('Support Réactif', 'backwpup'); ?></h3>
                                <p><?php esc_html_e('Une équipe à votre écoute pour résoudre vos problèmes techniques rapidement.', 'backwpup'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
	<?php
    }

    /**
     * Alternate between 'left' and 'right' CSS class.
     *
     * @since  2013.02.19
     */
    protected static function feature_class()
    {
        static $class = 'feature-box-left';

        echo $class;

        $class = 'feature-box-left' === $class ? 'feature-box-right' : 'feature-box-left';
    }

	protected static function row_class() {
		static $class = 'even';

        echo $class;

		$class = $class === 'odd' ? 'even' : 'odd';
	}
}
