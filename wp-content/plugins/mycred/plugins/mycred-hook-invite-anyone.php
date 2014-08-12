<?php

/**
 * Invite Anyone Plugin
 * @since 0.1
 * @version 1.2
 */
if ( defined( 'myCRED_VERSION' ) ) {

	/**
	 * Register Hook
	 * @since 0.1
	 * @version 1.0
	 */
	add_filter( 'mycred_setup_hooks', 'invite_anyone_myCRED_Hook' );
	function invite_anyone_myCRED_Hook( $installed ) {
		$installed['invite_anyone'] = array(
			'title'       => __( 'Invite Anyone Plugin', 'mycred' ),
			'description' => __( 'Awards %_plural% for sending invitations and/or %_plural% if the invite is accepted.', 'mycred' ),
			'callback'    => array( 'myCRED_Invite_Anyone' )
		);
		return $installed;
	}

	/**
	 * Invite Anyone Hook
	 * @since 0.1
	 * @version 1.2
	 */
	if ( ! class_exists( 'myCRED_Invite_Anyone' ) && class_exists( 'myCRED_Hook' ) ) {
		class myCRED_Invite_Anyone extends myCRED_Hook {

			/**
			 * Construct
			 */
			function __construct( $hook_prefs, $type = 'mycred_default' ) {
				parent::__construct( array(
					'id'       => 'invite_anyone',
					'defaults' => array(
						'send_invite'   => array(
							'creds'        => 1,
							'log'          => '%plural% for sending an invitation',
							'limit'        => 0
						),
						'accept_invite' => array(
							'creds'        => 1,
							'log'          => '%plural% for accepted invitation',
							'limit'        => 0
						)
					)
				), $hook_prefs, $type );
			}

			/**
			 * Run
			 * @since 0.1
			 * @version 1.1
			 */
			public function run() {
				if ( $this->prefs['send_invite']['creds'] != 0 )
					add_action( 'sent_email_invite',     array( $this, 'send_invite' ), 10, 3 );

				if ( $this->prefs['accept_invite']['creds'] != 0 ) {

					// Hook into user activation
					if ( function_exists( 'buddypress' ) && apply_filters( 'bp_core_signup_send_activation_key', true ) )
						add_action( 'bp_core_activated_user', array( $this, 'verified_signup' ) );

					add_action( 'accepted_email_invite', array( $this, 'accept_invite' ), 10, 2 );

				}
			}

			/**
			 * Sending Invites
			 * @since 0.1
			 * @version 1.1
			 */
			public function send_invite( $user_id, $email, $group ) {
				// Limit Check
				if ( $this->prefs['send_invite']['limit'] != 0 ) {
					$key = 'mycred_invite_anyone';
					if ( ! $this->is_main_type )
						$key .= '_' . $this->mycred_type;

					$user_log = get_user_meta( $user_id, $key, true );
					if ( empty( $user_log['sent'] ) ) $user_log['sent'] = 0;
					// Return if limit is reached
					if ( $user_log['sent'] >= $this->prefs['send_invite']['limit'] ) return;
				}

				// Award Points
				$this->core->add_creds(
					'sending_an_invite',
					$user_id,
					$this->prefs['send_invite']['creds'],
					$this->prefs['send_invite']['log'],
					0,
					'',
					$this->mycred_type
				);

				// Update limit
				if ( $this->prefs['send_invite']['limit'] != 0 ) {
					$user_log['sent'] = $user_log['sent']+1;
					update_user_meta( $user_id, $key, $user_log );
				}
			}

			/**
			 * Verified Signup
			 * If signups needs to be verified, award points first when they are.
			 * @since 1.4.6
			 * @version 1.0
			 */
			public function verified_signup( $user_id ) {

				// Get Pending List
				$pending = get_transient( 'mycred-pending-bp-signups' );
				if ( $pending === false || ! isset( $pending[ $user_id ] ) ) return;

				// Award Points
				$this->core->add_creds(
					'accepting_an_invite',
					$pending[ $user_id ],
					$this->prefs['accept_invite']['creds'],
					$this->prefs['accept_invite']['log'],
					$user_id,
					array( 'ref_type' => 'user' ),
					$this->mycred_type
				);

				// Remove from list
				unset( $pending[ $user_id ] );

				// Update pending list
				delete_transient( 'mycred-pending-bp-signups' );
				set_transient( 'mycred-pending-bp-signups', $pending, 7 * DAY_IN_SECONDS );

			}

			/**
			 * Accepting Invites
			 * @since 0.1
			 * @version 1.2
			 */
			public function accept_invite( $invited_user_id, $inviters = array() ) {
				if ( empty( $inviters ) ) return;

				// Invite Anyone will pass on an array of user IDs of those who have invited this user which we need to loop though
				foreach ( (array) $inviters as $inviter_id ) {
					// Limit Check
					if ( $this->prefs['accept_invite']['limit'] != 0 ) {
						$key = 'mycred_invite_anyone';
						if ( ! $this->is_main_type )
							$key .= '_' . $this->mycred_type;

						$user_log = get_user_meta( $inviter_id, $key, true );
						if ( empty( $user_log['accepted'] ) ) $user_log['accepted'] = 0;
						// Continue to next inviter if limit is reached
						if ( $user_log['accepted'] >= $this->prefs['accept_invite']['limit'] ) continue;
					}

					// Award Points
					$run = true;
					
					if ( function_exists( 'buddypress' ) && apply_filters( 'bp_core_signup_send_activation_key', true ) ) {
						$run = false;

						// Get pending list
						$pending = get_transient( 'mycred-pending-bp-signups' );
						if ( $pending === false )
							$pending = array();

						// Add to pending list if not there already
						if ( ! isset( $pending[ $invited_user_id ] ) ) {
							$pending[ $invited_user_id ] = $inviter_id;

							delete_transient( 'mycred-pending-bp-signups' );
							set_transient( 'mycred-pending-bp-signups', $pending, 7 * DAY_IN_SECONDS );
						}
					}

					if ( $run )
						$this->core->add_creds(
							'accepting_an_invite',
							$inviter_id,
							$this->prefs['accept_invite']['creds'],
							$this->prefs['accept_invite']['log'],
							$invited_user_id,
							array( 'ref_type' => 'user' ),
							$this->mycred_type
						);

					// Update Limit
					if ( $this->prefs['accept_invite']['limit'] != 0 ) {
						$user_log['accepted'] = $user_log['accepted']+1;
						update_user_meta( $inviter_id, $key, $user_log );
					}
				}
			}

			/**
			 * Preferences
			 * @since 0.1
			 * @version 1.0.2
			 */
			public function preferences() {
				$prefs = $this->prefs; ?>

<!-- Creds for Sending Invites -->
<label for="<?php echo $this->field_id( array( 'send_invite', 'creds' ) ); ?>" class="subheader"><?php echo $this->core->template_tags_general( __( '%plural% for Sending An Invite', 'mycred' ) ); ?></label>
<ol>
	<li>
		<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'send_invite', 'creds' ) ); ?>" id="<?php echo $this->field_id( array( 'send_invite', 'creds' ) ); ?>" value="<?php echo $this->core->number( $prefs['send_invite']['creds'] ); ?>" size="8" /></div>
	</li>
	<li class="empty">&nbsp;</li>
	<li>
		<label for="<?php echo $this->field_id( array( 'send_invite', 'log' ) ); ?>"><?php _e( 'Log template', 'mycred' ); ?></label>
		<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'send_invite', 'log' ) ); ?>" id="<?php echo $this->field_id( array( 'send_invite', 'log' ) ); ?>" value="<?php echo esc_attr( $prefs['send_invite']['log'] ); ?>" class="long" /></div>
		<span class="description"><?php echo $this->available_template_tags( array( 'general' ) ); ?></span>
	</li>
</ol>
<label for="<?php echo $this->field_id( array( 'send_invite', 'limit' ) ); ?>" class="subheader"><?php _e( 'Limit', 'mycred' ); ?></label>
<ol>
	<li>
		<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'send_invite', 'limit' ) ); ?>" id="<?php echo $this->field_id( array( 'send_invite', 'limit' ) ); ?>" value="<?php echo $prefs['send_invite']['limit']; ?>" size="8" /></div>
		<span class="description"><?php echo $this->core->template_tags_general( __( 'Maximum number of invites that grants %_plural%. Use zero for unlimited.', 'mycred' ) ); ?></span>
	</li>
</ol>
<!-- Creds for Accepting Invites -->
<label for="<?php echo $this->field_id( array( 'accept_invite', 'creds' ) ); ?>" class="subheader"><?php echo $this->core->template_tags_general( __( '%plural% for Accepting An Invite', 'mycred' ) ); ?></label>
<ol>
	<li>
		<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'accept_invite', 'creds' ) ); ?>" id="<?php echo $this->field_id( array( 'accept_invite', 'creds' ) ); ?>" value="<?php echo $this->core->number( $prefs['accept_invite']['creds'] ); ?>" size="8" /></div>
		<span class="description"><?php echo $this->core->template_tags_general( __( '%plural% for each invited user that accepts an invitation.', 'mycred' ) ); ?></span>
	</li>
	<li class="empty">&nbsp;</li>
	<li>
		<label for="<?php echo $this->field_id( array( 'accept_invite', 'log' ) ); ?>"><?php _e( 'Log template', 'mycred' ); ?></label>
		<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'accept_invite', 'log' ) ); ?>" id="<?php echo $this->field_id( array( 'accept_invite', 'log' ) ); ?>" value="<?php echo esc_attr( $prefs['accept_invite']['log'] ); ?>" class="long" /></div>
		<span class="description"><?php echo $this->available_template_tags( array( 'general', 'user' ) ); ?></span>
	</li>
</ol>
<label for="<?php echo $this->field_id( array( 'accept_invite', 'limit' ) ); ?>" class="subheader"><?php _e( 'Limit', 'mycred' ); ?></label>
<ol>
	<li>
		<div class="h2"><input type="text" name="<?php echo $this->field_name( array( 'accept_invite', 'limit' ) ); ?>" id="<?php echo $this->field_id( array( 'accept_invite', 'limit' ) ); ?>" value="<?php echo $prefs['accept_invite']['limit']; ?>" size="8" /></div>
		<span class="description"><?php echo $this->core->template_tags_general( __( 'Maximum number of accepted invitations that grants %_plural%. Use zero for unlimited.', 'mycred' ) ); ?></span>
	</li>
</ol>
<?php
			}
		}
	}
}
?>