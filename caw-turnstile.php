<?php
/**
 * Cloudflare Turnstile — site-wide human verification.
 *
 * Replaces Google reCAPTCHA everywhere it used to run. Covers:
 *   - the Mayosis MSV auth modal (login / register / forgot password, all AJAX)
 *   - wp-login.php (login / register / lost password)
 *   - EDD FES front-end forms (vendor login, vendor contact, vendor registration)
 *
 * EDD Pro (3.6.1+) and Contact Form 7 (5.9+) ship their own Turnstile support —
 * those are configured from their own settings screens, not from here.
 *
 * Keys come from wp-config.php constants if defined, otherwise from
 * Settings → Cloudflare Turnstile.
 *
 * @package Mayosis Child
 */

defined( 'ABSPATH' ) || exit;

/* =============================================================================
   KEYS / CONFIG
   ============================================================================= */

/**
 * Turnstile site key (public).
 */
function caw_turnstile_site_key() {
	if ( defined( 'CAW_TURNSTILE_SITE_KEY' ) && CAW_TURNSTILE_SITE_KEY ) {
		return (string) CAW_TURNSTILE_SITE_KEY;
	}
	return (string) get_option( 'caw_turnstile_site_key', '' );
}

/**
 * Turnstile secret key (private).
 */
function caw_turnstile_secret_key() {
	if ( defined( 'CAW_TURNSTILE_SECRET_KEY' ) && CAW_TURNSTILE_SECRET_KEY ) {
		return (string) CAW_TURNSTILE_SECRET_KEY;
	}
	return (string) get_option( 'caw_turnstile_secret_key', '' );
}

/**
 * Turnstile only runs when both keys are present — so a half-configured site
 * degrades to "no captcha" rather than "nobody can log in".
 */
function caw_turnstile_is_configured() {
	return caw_turnstile_site_key() !== '' && caw_turnstile_secret_key() !== '';
}

/**
 * Whether Turnstile should guard a given context.
 *
 * @param string $context One of: msv_login, msv_register, msv_forgot,
 *                        wp_login, wp_register, wp_lostpassword,
 *                        fes_login, fes_registration, fes_vendor_contact.
 */
function caw_turnstile_enabled( $context ) {
	$enabled = caw_turnstile_is_configured();

	/**
	 * Turn Turnstile off for one specific form.
	 *
	 * @param bool   $enabled Whether the widget renders and is validated.
	 * @param string $context The form context.
	 */
	return (bool) apply_filters( 'caw_turnstile_enabled', $enabled, $context );
}

/* =============================================================================
   WIDGET MARKUP
   ============================================================================= */

/**
 * Build the Turnstile widget container.
 *
 * Rendering is explicit (our JS calls turnstile.render) so the widget can pick
 * up the site's cookie-driven night mode and mount lazily inside the modal.
 *
 * @param string $context Form context — also sent to Cloudflare as the action.
 * @param array  $args    appearance|size|extra_class overrides.
 */
function caw_turnstile_field( $context, $args = array() ) {
	if ( ! caw_turnstile_enabled( $context ) ) {
		return '';
	}

	$args = wp_parse_args(
		$args,
		array(
			/* interaction-only keeps the widget invisible unless Cloudflare
			   actually needs the visitor to do something. */
			'appearance'  => 'interaction-only',
			'size'        => 'flexible',
			'extra_class' => '',
		)
	);

	/** Filter the widget attributes for one context. */
	$args = apply_filters( 'caw_turnstile_widget_args', $args, $context );

	return sprintf(
		'<div class="caw-turnstile %1$s" data-caw-turnstile="1" data-sitekey="%2$s" data-action="%3$s" data-appearance="%4$s" data-size="%5$s"></div>',
		esc_attr( $args['extra_class'] ),
		esc_attr( caw_turnstile_site_key() ),
		esc_attr( str_replace( '_', '-', $context ) ),
		esc_attr( $args['appearance'] ),
		esc_attr( $args['size'] )
	);
}

/**
 * Echoing wrapper for action hooks.
 */
function caw_turnstile_render( $context, $args = array() ) {
	echo caw_turnstile_field( $context, $args ); // phpcs:ignore WordPress.Security.EscapeOutput
}

/* =============================================================================
   VERIFICATION
   ============================================================================= */

/**
 * The token Cloudflare's widget put in the request, if any.
 */
function caw_turnstile_posted_token() {
	if ( empty( $_POST['cf-turnstile-response'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return '';
	}
	return sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
}

/**
 * Best-effort visitor IP, honouring Cloudflare's header when present.
 */
function caw_turnstile_ip() {
	foreach ( array( 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR' ) as $key ) {
		if ( ! empty( $_SERVER[ $key ] ) ) {
			$ip = trim( (string) wp_unslash( $_SERVER[ $key ] ) );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
	}
	return '';
}

/**
 * Verify a token against Cloudflare's siteverify endpoint.
 *
 * Results are memoised per token: Turnstile tokens are single-use, so a form
 * whose submission passes through two of our guards (e.g. the MSV modal calls
 * wp_signon, which fires the `authenticate` filter) must not spend the token
 * twice — the second call would legitimately fail.
 *
 * A transport failure (Cloudflare unreachable, timeout) is treated as a pass:
 * an outside attacker cannot induce one, and failing closed would lock every
 * customer out of login and checkout. An explicit `success: false` from
 * Cloudflare always fails. Flip with the `caw_turnstile_fail_open` filter.
 *
 * @param string $token The cf-turnstile-response value.
 * @return bool True when the visitor is verified.
 */
function caw_turnstile_verify( $token ) {
	static $seen = array();

	if ( ! caw_turnstile_is_configured() ) {
		return true;
	}

	$token = (string) $token;
	if ( '' === $token ) {
		return false;
	}

	$cache_key = md5( $token );
	if ( isset( $seen[ $cache_key ] ) ) {
		return $seen[ $cache_key ];
	}

	$response = wp_safe_remote_post(
		'https://challenges.cloudflare.com/turnstile/v0/siteverify',
		array(
			'timeout' => 10,
			'body'    => array(
				'secret'   => caw_turnstile_secret_key(),
				'response' => $token,
				'remoteip' => caw_turnstile_ip(),
			),
		)
	);

	$fail_open = (bool) apply_filters( 'caw_turnstile_fail_open', true );

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		$seen[ $cache_key ] = $fail_open;
		return $fail_open;
	}

	$body   = json_decode( wp_remote_retrieve_body( $response ), true );
	$passed = ! empty( $body['success'] );

	$seen[ $cache_key ] = $passed;
	return $passed;
}

/**
 * Verify whatever token came in with the current request.
 */
function caw_turnstile_request_passes() {
	return caw_turnstile_verify( caw_turnstile_posted_token() );
}

/**
 * The message shown when verification fails.
 */
function caw_turnstile_error_message() {
	return apply_filters(
		'caw_turnstile_error_message',
		__( 'Human verification failed. Please try again.', 'mayosis-child' )
	);
}

/* =============================================================================
   ASSETS
   ============================================================================= */

add_action( 'wp_enqueue_scripts', 'caw_turnstile_enqueue' );
/**
 * Front-end assets. Only loaded for logged-out visitors — every form we guard
 * is a logged-out form.
 */
function caw_turnstile_enqueue() {
	if ( is_user_logged_in() || ! caw_turnstile_is_configured() ) {
		return;
	}
	caw_turnstile_enqueue_assets();
}

/**
 * Register + enqueue the Cloudflare API and our glue script.
 */
function caw_turnstile_enqueue_assets() {
	$js_path = get_stylesheet_directory() . '/js/caw-turnstile.js';

	/* EDD Pro registers this exact handle for its own captcha. In WordPress the
	   first registration owns the URL, so passing a src here would be silently
	   discarded whenever EDD got there first — reuse its registration instead,
	   and only register our own when nobody else has. Either URL works: our
	   widgets carry the .caw-turnstile class, not Cloudflare's auto-render
	   .cf-turnstile, so implicit mode leaves them for us to render explicitly. */
	if ( ! wp_script_is( 'cloudflare-turnstile', 'registered' ) ) {
		wp_register_script(
			'cloudflare-turnstile',
			'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
			array(),
			null, // Cloudflare warns about an unknown ?ver= parameter.
			true
		);
	}
	wp_enqueue_script( 'cloudflare-turnstile' );

	wp_enqueue_script(
		'caw-turnstile',
		get_stylesheet_directory_uri() . '/js/caw-turnstile.js',
		array( 'cloudflare-turnstile' ),
		file_exists( $js_path ) ? filemtime( $js_path ) : null,
		true
	);

	/* The MSV modal markup lives in the parent theme and offers no hook, so its
	   three widgets are mounted by JS — hand the script what PHP knows. */
	$modal_args = apply_filters(
		'caw_turnstile_widget_args',
		array(
			'appearance' => 'interaction-only',
			'size'       => 'flexible',
		),
		'msv_modal'
	);

	wp_localize_script(
		'caw-turnstile',
		'cawTurnstile',
		array(
			'sitekey'    => caw_turnstile_site_key(),
			'appearance' => isset( $modal_args['appearance'] ) ? $modal_args['appearance'] : 'interaction-only',
			'size'       => isset( $modal_args['size'] ) ? $modal_args['size'] : 'flexible',
			'modal'      => caw_turnstile_enabled( 'msv_login' ) ? 1 : 0,
			'waitMsg'    => __( 'Verifying…', 'mayosis-child' ),
			'failMsg'    => caw_turnstile_error_message(),
		)
	);
}

/* =============================================================================
   MSV AUTH MODAL (Mayosis AJAX login / register / forgot)
   The modal markup lives in the parent theme and has no hooks, so the widget is
   mounted client-side; these guards run at priority 1, ahead of the plugin's
   own handlers, and answer in the JSON shape the modal's JS expects.
   ============================================================================= */

add_action( 'wp_ajax_nopriv_mayosis_mbw_lrb_ajax_login', 'caw_turnstile_guard_msv_login', 1 );
function caw_turnstile_guard_msv_login() {
	caw_turnstile_guard_ajax( 'msv_login', array( 'loggedin' => false ) );
}

add_action( 'wp_ajax_nopriv_mayosis_mbw_lrb_ajax_register', 'caw_turnstile_guard_msv_register', 1 );
function caw_turnstile_guard_msv_register() {
	caw_turnstile_guard_ajax( 'msv_register', array( 'registered' => false ) );
}

add_action( 'wp_ajax_nopriv_mayosis_mbw_lrb_ajax_lost_password', 'caw_turnstile_guard_msv_forgot', 1 );
add_action( 'wp_ajax_mayosis_mbw_lrb_ajax_lost_password', 'caw_turnstile_guard_msv_forgot', 1 );
function caw_turnstile_guard_msv_forgot() {
	caw_turnstile_guard_ajax( 'msv_forgot', array( 'reset' => false ) );
}

/**
 * Short-circuit an MSV AJAX action when verification fails.
 *
 * @param string $context Form context.
 * @param array  $shape   The failure flag the modal's JS reads for this form.
 */
function caw_turnstile_guard_ajax( $context, $shape ) {
	if ( ! caw_turnstile_enabled( $context ) ) {
		return;
	}
	if ( caw_turnstile_request_passes() ) {
		return;
	}
	wp_send_json( array_merge( $shape, array( 'message' => caw_turnstile_error_message() ) ) );
}

/* =============================================================================
   wp-login.php — login / register / lost password
   ============================================================================= */

add_action( 'login_enqueue_scripts', 'caw_turnstile_login_page_assets' );
/**
 * wp-login.php doesn't load the theme stylesheet, so the widget gets its
 * spacing from a small inline rule.
 */
function caw_turnstile_login_page_assets() {
	if ( ! caw_turnstile_is_configured() ) {
		return;
	}
	caw_turnstile_enqueue_assets();
	wp_add_inline_style(
		'login',
		'.caw-turnstile{margin:0 0 16px}.caw-turnstile:empty{margin:0}.caw-turnstile iframe{max-width:100%}'
	);
}

add_action( 'login_form', 'caw_turnstile_wp_login_field' );
function caw_turnstile_wp_login_field() {
	caw_turnstile_render( 'wp_login', array( 'appearance' => 'always' ) );
}

add_action( 'register_form', 'caw_turnstile_wp_register_field' );
function caw_turnstile_wp_register_field() {
	caw_turnstile_render( 'wp_register', array( 'appearance' => 'always' ) );
}

add_action( 'lostpassword_form', 'caw_turnstile_wp_lostpassword_field' );
function caw_turnstile_wp_lostpassword_field() {
	caw_turnstile_render( 'wp_lostpassword', array( 'appearance' => 'always' ) );
}

add_filter( 'authenticate', 'caw_turnstile_check_wp_login', 30, 3 );
/**
 * Guard the wp-login.php sign-in POST.
 *
 * Deliberately scoped to a real wp-login.php form post: `authenticate` also
 * fires for XML-RPC, REST application passwords and the modal's own wp_signon
 * call, none of which carry a Turnstile token.
 *
 * Note this does NOT bail out when $user is already a WP_Error. Core's
 * wp_authenticate_username_password() runs at priority 20 and reports bad
 * credentials; if we deferred to that, a bot could grind through passwords
 * without ever being asked for a token — the captcha would only ever gate
 * logins that were going to succeed. Our error replaces core's instead.
 *
 * @param null|WP_User|WP_Error $user     Current authentication result.
 * @param string                $username Submitted username.
 * @param string                $password Submitted password.
 */
function caw_turnstile_check_wp_login( $user, $username, $password ) {
	if ( ! caw_turnstile_enabled( 'wp_login' ) ) {
		return $user;
	}
	/* Let core report an empty form rather than blaming the captcha. */
	if ( '' === $username || '' === $password ) {
		return $user;
	}
	if ( ! caw_turnstile_is_wp_login_post() ) {
		return $user;
	}
	if ( caw_turnstile_request_passes() ) {
		return $user;
	}

	return new WP_Error( 'caw_turnstile_failed', caw_turnstile_error_message() );
}

/**
 * True when the current request is a wp-login.php form submission.
 */
function caw_turnstile_is_wp_login_post() {
	if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
		return false;
	}
	if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
		return false;
	}
	$self = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : '';

	return 'wp-login.php' === $self;
}

add_filter( 'registration_errors', 'caw_turnstile_check_wp_register', 10, 3 );
/**
 * Guard wp-login.php?action=register.
 *
 * @param WP_Error $errors Registration errors so far.
 */
function caw_turnstile_check_wp_register( $errors, $sanitized_user_login, $user_email ) {
	if ( ! caw_turnstile_enabled( 'wp_register' ) || ! caw_turnstile_is_wp_login_post() ) {
		return $errors;
	}
	if ( ! caw_turnstile_request_passes() ) {
		$errors->add( 'caw_turnstile_failed', '<strong>' . esc_html__( 'Error:', 'mayosis-child' ) . '</strong> ' . esc_html( caw_turnstile_error_message() ) );
	}
	return $errors;
}

add_action( 'lostpassword_post', 'caw_turnstile_check_wp_lostpassword', 10, 1 );
/**
 * Guard wp-login.php?action=lostpassword.
 *
 * @param WP_Error $errors Lost-password errors so far.
 */
function caw_turnstile_check_wp_lostpassword( $errors ) {
	if ( ! caw_turnstile_enabled( 'wp_lostpassword' ) || ! caw_turnstile_is_wp_login_post() ) {
		return;
	}
	if ( ! caw_turnstile_request_passes() ) {
		$errors->add( 'caw_turnstile_failed', '<strong>' . esc_html__( 'Error:', 'mayosis-child' ) . '</strong> ' . esc_html( caw_turnstile_error_message() ) );
	}
}

/* =============================================================================
   EDD REGISTRATION  (the /register/ page's edd/register block, and the
   [edd_register] shortcode — both fire the same hooks)
   ============================================================================= */

add_action( 'edd_register_form_fields_after', 'caw_turnstile_edd_register_field' );
/**
 * Put a widget on EDD's own registration form.
 *
 * The hook fires inside the <form>, just before </form>, in both the block view
 * and the shortcode template.
 */
function caw_turnstile_edd_register_field() {
	caw_turnstile_render( 'edd_register', array( 'appearance' => 'always' ) );
}

add_action( 'edd_pre_process_register_form', 'caw_turnstile_check_edd_register' );
/**
 * Guard EDD's registration POST.
 *
 * EDD does render a Turnstile widget here, but never checks it server-side:
 * `edd_process_register_form()` contains no captcha check, and
 * `EDD\Captcha\Validate` subscribes only to two AJAX actions plus
 * `edd_pre_process_purchase` (checkout, which is off). The whole gate is
 * client-side — the block's JS fetches a token, validates it over admin-ajax,
 * then clicks submit — so a direct POST to `edd_user_register` walks straight
 * past it. That left an unguarded signup endpoint that also bypasses the
 * verified-email flow in caw_verified_popup_registration().
 *
 * We check our OWN token rather than re-verifying EDD's. By the time the form
 * posts, EDD's `edd_captcha_validate` AJAX call has already spent its token,
 * and Turnstile tokens are single-use — a second siteverify on it would come
 * back `timeout-or-duplicate` and lock out every genuine signup. Reading EDD's
 * cached result instead would work, but only by reaching into a private session
 * key that a plugin update is free to rename.
 *
 * `edd_process_register_form()` bails whenever `edd_get_errors()` is non-empty,
 * so setting an error here is enough to stop the registration. Checkout is
 * unaffected: this hook only fires for `edd_register_submit` posts.
 */
function caw_turnstile_check_edd_register() {
	if ( ! caw_turnstile_enabled( 'edd_register' ) ) {
		return;
	}
	if ( ! caw_turnstile_request_passes() ) {
		edd_set_error( 'caw_turnstile_failed', caw_turnstile_error_message() );
	}
}

/* =============================================================================
   EDD FES — vendor login / registration / contact
   FES's own reCAPTCHA field is force-excluded here; its keys and toggles were
   cleared in settings, and this keeps the field gone even if a stray option
   comes back.
   ============================================================================= */

foreach ( array( 'login', 'registration', 'vendor-contact', 'profile', 'submission' ) as $caw_fes_form ) {
	add_filter( "fes_templates_to_exclude_render_{$caw_fes_form}_form_frontend", 'caw_turnstile_drop_fes_recaptcha' );
	add_filter( "fes_templates_to_exclude_validate_{$caw_fes_form}_form_frontend", 'caw_turnstile_drop_fes_recaptcha' );
	add_filter( "fes_templates_to_exclude_save_{$caw_fes_form}_form_frontend", 'caw_turnstile_drop_fes_recaptcha' );
}
unset( $caw_fes_form );

function caw_turnstile_drop_fes_recaptcha( $templates ) {
	$templates   = (array) $templates;
	$templates[] = 'recaptcha';
	return $templates;
}

/* Priority 20, not 10: FES_Login_Form::lost_password_link() is hooked onto the
   same filter and ignores the incoming $output, so anything appended at 10
   gets thrown away. Running last means we append to whatever it produced. */
add_filter( 'fes_render_login_after_fields', 'caw_turnstile_fes_login_field', 20, 1 );
function caw_turnstile_fes_login_field( $output ) {
	return $output . caw_turnstile_field( 'fes_login', array( 'appearance' => 'always' ) );
}

add_filter( 'fes_render_registration_after_fields', 'caw_turnstile_fes_registration_field', 20, 1 );
function caw_turnstile_fes_registration_field( $output ) {
	return $output . caw_turnstile_field( 'fes_registration', array( 'appearance' => 'always' ) );
}

add_filter( 'fes_render_vendor-contact_after_fields', 'caw_turnstile_fes_contact_field', 20, 1 );
function caw_turnstile_fes_contact_field( $output ) {
	return $output . caw_turnstile_field( 'fes_vendor_contact', array( 'appearance' => 'always' ) );
}

add_filter( 'fes_before_login_form_error_check_frontend', 'caw_turnstile_check_fes_login', 10, 1 );
function caw_turnstile_check_fes_login( $output ) {
	return caw_turnstile_fes_error( $output, 'fes_login' );
}

add_filter( 'fes_before_registration_form_error_check_frontend', 'caw_turnstile_check_fes_registration', 10, 1 );
function caw_turnstile_check_fes_registration( $output ) {
	return caw_turnstile_fes_error( $output, 'fes_registration' );
}

add_filter( 'fes_before_vendor-contact_form_error_check_frontend', 'caw_turnstile_check_fes_contact', 10, 1 );
function caw_turnstile_check_fes_contact( $output ) {
	return caw_turnstile_fes_error( $output, 'fes_vendor_contact' );
}

/**
 * FES reads `$output['message']` as "this submission failed".
 *
 * @param array  $output  FES error-check payload.
 * @param string $context Form context.
 */
function caw_turnstile_fes_error( $output, $context ) {
	if ( ! caw_turnstile_enabled( $context ) ) {
		return $output;
	}
	if ( caw_turnstile_request_passes() ) {
		return $output;
	}

	$output              = (array) $output;
	$output['message']   = caw_turnstile_error_message();
	$output['success']   = false;
	return $output;
}

/* =============================================================================
   SETTINGS SCREEN — Settings → Cloudflare Turnstile
   ============================================================================= */

add_action( 'admin_menu', 'caw_turnstile_settings_menu' );
function caw_turnstile_settings_menu() {
	add_options_page(
		__( 'Cloudflare Turnstile', 'mayosis-child' ),
		__( 'Cloudflare Turnstile', 'mayosis-child' ),
		'manage_options',
		'caw-turnstile',
		'caw_turnstile_settings_page'
	);
}

add_action( 'admin_init', 'caw_turnstile_settings_register' );
function caw_turnstile_settings_register() {
	register_setting( 'caw_turnstile', 'caw_turnstile_site_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	register_setting( 'caw_turnstile', 'caw_turnstile_secret_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
}

function caw_turnstile_settings_page() {
	$const_site   = defined( 'CAW_TURNSTILE_SITE_KEY' ) && CAW_TURNSTILE_SITE_KEY;
	$const_secret = defined( 'CAW_TURNSTILE_SECRET_KEY' ) && CAW_TURNSTILE_SECRET_KEY;
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Cloudflare Turnstile', 'mayosis-child' ); ?></h1>

		<p>
			<?php
			printf(
				/* translators: 1: opening anchor tag, 2: closing anchor tag */
				esc_html__( 'Create a widget in the %1$sCloudflare dashboard%2$s and paste its keys below. Turnstile then guards the site login/registration popup, wp-login.php and the vendor forms.', 'mayosis-child' ),
				'<a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener">',
				'</a>'
			);
			?>
		</p>

		<p>
			<strong><?php esc_html_e( 'Status:', 'mayosis-child' ); ?></strong>
			<?php if ( caw_turnstile_is_configured() ) : ?>
				<span style="color:#12805c;">&#10004; <?php esc_html_e( 'Active', 'mayosis-child' ); ?></span>
			<?php else : ?>
				<span style="color:#b32d2e;"><?php esc_html_e( 'Inactive — both keys are required. No verification is running.', 'mayosis-child' ); ?></span>
			<?php endif; ?>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'caw_turnstile' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="caw_turnstile_site_key"><?php esc_html_e( 'Site Key', 'mayosis-child' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="caw_turnstile_site_key" name="caw_turnstile_site_key"
						       value="<?php echo esc_attr( get_option( 'caw_turnstile_site_key', '' ) ); ?>" <?php disabled( $const_site ); ?>>
						<?php if ( $const_site ) : ?>
							<p class="description"><?php esc_html_e( 'Set by the CAW_TURNSTILE_SITE_KEY constant in wp-config.php.', 'mayosis-child' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="caw_turnstile_secret_key"><?php esc_html_e( 'Secret Key', 'mayosis-child' ); ?></label></th>
					<td>
						<input type="password" class="regular-text" id="caw_turnstile_secret_key" name="caw_turnstile_secret_key"
						       value="<?php echo esc_attr( get_option( 'caw_turnstile_secret_key', '' ) ); ?>" autocomplete="off" <?php disabled( $const_secret ); ?>>
						<?php if ( $const_secret ) : ?>
							<p class="description"><?php esc_html_e( 'Set by the CAW_TURNSTILE_SECRET_KEY constant in wp-config.php.', 'mayosis-child' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Handled elsewhere', 'mayosis-child' ); ?></h2>
		<ul style="list-style:disc;padding-left:20px;">
			<li><?php esc_html_e( 'EDD checkout and account blocks — Downloads → Settings → Misc → Captcha (select Cloudflare Turnstile).', 'mayosis-child' ); ?></li>
			<li><?php esc_html_e( 'Contact Form 7 — Contact → Integration → Turnstile.', 'mayosis-child' ); ?></li>
		</ul>
	</div>
	<?php
}
