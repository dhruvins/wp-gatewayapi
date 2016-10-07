<?php

/**
 * Class for integration with the free Contact Form 7-plugin
 * =========================================================
 *
 * This class adds a few extra field types to Contact Form 7 and, when present in form submission, creates/updates/
 * unsubscribes recipients from the GatewayAPI recipients list.
 */
class GwapiContactForm7 {
	private static $instance;

	private $types = ['gw_phone' => '📱 phone', 'gw_country' => '📱 countrycode', 'gw_groups' => '📱 groups', 'gw_action' => '📱 action*'];

	public static function getInstance() {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}

		return static::$instance;
	}

	private function __construct() {

	}

	public function initAdmin()
	{
		$this->addTagGenerator();
	}

	public function handleShortcodes()
	{
		foreach($this->types as $key=>$name) {
			$func = [$this, 'handle'.substr($key, 3)];
			wpcf7_add_shortcode( array($key), $func, true );

			// validation of fields
			add_filter('wpcf7_validate_'.$key, [$this, 'validate'.substr($key, 3)], 10, 2);
		}

		// handle send verification code
		add_action('wp_ajax_nopriv_gwapi_send_verify_sms', [$this, 'sendVerifySms']);
		add_action('wp_ajax_gwapi_send_verify_sms', [$this, 'sendVerifySms']);

		add_action('wp_ajax_nopriv_gwapi_verify_sms', [$this, 'verifySms']);
		add_action('wp_ajax_gwapi_verify_sms', [$this, 'verifySms']);
	}

	public function addTagGenerator()
	{
		$tag_generator = WPCF7_TagGenerator::get_instance();
		foreach($this->types as $key => $item) {
			$func = [$this, 'tagGenerate'.substr($key, 3)];
			$tag_generator->add( $key, $item, $func);
		}
	}

	public function handleSubmit($form)
	{
		$wpcf7        = WPCF7_ContactForm::get_current(); /** @var $wpcf7 WPCF7_ContactForm */
		$submission   = WPCF7_Submission::get_instance();

		if (!$submission ) return;
		if ( $submission ) {
			$country_code_field = $wpcf7->form_scan_shortcode(['type' => 'gw_country']);
			$groups_field = $wpcf7->form_scan_shortcode(['type' => 'gw_groups']);
			$actions_field = $wpcf7->form_scan_shortcode(['type' => 'gw_action']);
			$phone_field = $wpcf7->form_scan_shortcode(['type' => 'gw_phone']);

			$all_fields = $wpcf7->form_scan_shortcode();

			if (!$country_code_field || !$phone_field || !$actions_field ) return; // nothing to do

			$data = $submission->get_posted_data();

			$curID = null;
			if (in_array($data['gwapi_action'], ['unsubscribe', 'update'])) {
				$q = new WP_Query(["post_type" => "gwapi-recipient", "meta_query" => [ [ 'key' => 'cc', 'value' => $_POST['gwapi_country'] ], ['key' => 'number', 'value' => $_POST['gwapi_phone']] ]]);
				$curID = $q->post->ID;
				if (!$curID) return; // should never happen, validation would have caught this...
			}


			$insert_data = null;
			if (in_array($data['gwapi_action'], ['update', 'signup'])) {
				// title/name for recipient
				$title = '';
				$name_fields = ['name', 'full_name'];
				foreach($name_fields as $nf) {
					if (isset($_POST[$nf])) $title = $_POST[$nf];
				}
				if (!$title) {
					if (isset($_POST['first_name'])) $title = $_POST['first_name'];
					if (isset($_POST['last_name'])) $title .= " ".$_POST['last_name'];
				}
				if (!$title) $title = '+'.$data['gwapi_country']." ".$data['gwapi_phone'];

				// data
				$insert_data = [
					"post_type" => "gwapi-recipient",
					"post_status" => "publish",
					"meta_input" => [
						"cc" => $data['gwapi_country'],
						"number" => $data['gwapi_phone']
					],
					"post_title" => $title
				];

				// other fields posted?
				foreach($all_fields as $af) {
					if (substr($af['basetype'],0,3) === 'gw_') continue;
					if (!$af['name']) continue;
					$insert_data['meta_input'][$af['name']] = $data[$af['name']];
				}
			}

			switch($data['gwapi_action']) {
				case 'unsubscribe':
					wp_trash_post($curID);
					break;

				case 'update':
					$insert_data['ID'] = $curID;
				case 'signup':
					$curID = wp_insert_post($insert_data);

					// update groups? update groups
					if ($groups_field) {
						// remove all current groups
						$groupIDs = $data['gwapi_groups'];
						foreach($groupIDs as &$gid) { $gid = (int)$gid; }
						wp_set_object_terms($curID, $groupIDs, 'gwapi-recipient-groups');
					}

					break;
			}
		}
	}

	public function tagGeneratePhone($contact_form, $args = '')
	{
		$args = wp_parse_args( $args, array() );
		$type = 'gw_phone';
		$description = "Generate the GatewayAPI field for phone number input.";
		?>
		<div class="control-box">
			<fieldset>
				<legend><?=esc_html( $description )?></legend>

				<table class="form-table">
					<tbody>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-id' ); ?>">Id attribute</label></th>
						<td><input type="text" name="id" class="idvalue oneline option" id="<?php echo esc_attr( $args['content'] . '-id' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-class' ); ?>">Class attribute</label></th>
						<td><input type="text" name="class" class="classvalue oneline option" id="<?php echo esc_attr( $args['content'] . '-class' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-default' ); ?>">Default value</label></th>
						<td><input type="text" name="default" class="defaultvalue oneline option" id="<?php echo esc_attr( $args['default'] . '-default' ); ?>"></td>
					</tr>
					</tbody>
				</table>
			</fieldset>
		</div>

		<div class="insert-box">
			<input type="text" name="<?php echo $type; ?>" class="tag code" readonly="readonly" onfocus="this.select()" />

			<div class="submitbox">
				<input type="button" class="button button-primary insert-tag" value="<?php echo esc_attr( __( 'Insert Tag', 'contact-form-7' ) ); ?>" />
			</div>

			<br class="clear" />
		</div>
		<?php
	}

	public function tagGenerateCountry($contact_form, $args = '')
	{
		$args = wp_parse_args( $args, array() );
		$type = 'gw_country';
		$description = "Generate the GatewayAPI country code selector.";
		?>
		<div class="control-box">
			<fieldset>
				<legend><?=esc_html( $description )?></legend>

				<table class="form-table">
					<tbody>

					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-groups' ); ?>">Limit countries</label></th>
						<td>
							<input type="text" name="values" class="oneline" id="<?php echo esc_attr( $args['content'] . '-onlycc' ); ?>">
							<p class="description">Enter all calling codes allowed, separated by comma. Leave empty to allow all. See the <a href="https://countrycode.org/" target="_blank">list of calling codes</a>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-id' ); ?>">Id attribute</label></th>
						<td><input type="text" name="id" class="idvalue oneline option" id="<?php echo esc_attr( $args['content'] . '-id' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-class' ); ?>">Class attribute</label></th>
						<td><input type="text" name="class" class="classvalue oneline option" id="<?php echo esc_attr( $args['content'] . '-class' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-default' ); ?>">Default country code</label></th>
						<td><input type="text" name="default" class="defaultvalue oneline option" id="<?php echo esc_attr( $args['default'] . '-default' ); ?>"></td>
					</tr>
					</tbody>
				</table>
			</fieldset>
		</div>

		<div class="insert-box">
			<input type="text" name="<?php echo $type; ?>" class="tag code" readonly="readonly" onfocus="this.select()" />

			<div class="submitbox">
				<input type="button" class="button button-primary insert-tag" value="<?php echo esc_attr( __( 'Insert Tag', 'contact-form-7' ) ); ?>" />
			</div>

			<br class="clear" />
		</div>
		<?php
	}

	/**
	 * TAG EDITOR FOR "GROUPS"
	 * ========================
	 * Ie. what groups to signup/update.
	 */
	public function tagGenerateGroups($contact_form, $args = '')
	{
		$args = wp_parse_args( $args, array() );
		$type = 'gw_groups';
		$description = "Generate the GatewayAPI Groups selection. Select which groups to sign the recipient up to or make it possible for the recipients to select themselves.";
		?>
		<div class="control-box">
			<fieldset>
				<legend><?=esc_html( $description )?></legend>

				<table class="form-table">
					<tbody>

					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-groups' ); ?>">Pick groups</label></th>
						<td>
							<div style="width: 100%; max-height: 100px; overflow: auto;">
								<?php $terms = get_terms('gwapi-recipient-groups', ['hide_empty' => false]); ?>
								<?php foreach($terms as $t): ?>
									<label style="display: block; margin-top: 3px; margin-bottom: 3px;"><input type="checkbox" name="<?= $t->term_id; ?>" class="option"> <?= $t->name; ?></label>
								<?php endforeach; ?>
							</div>
						</td>
					</tr>

					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-allow-select' ); ?>">Hidden field</label></th>
						<td>
							<label>
								<input type="checkbox" name="hidden" class="option" id="<?php echo esc_attr( $args['content'] . '-class' ); ?>" />
								Hide this field. Recipients will be subscribed to all of the selected groups.
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-id' ); ?>">Id attribute</label></th>
						<td><input type="text" name="id" class="idvalue oneline option" id="<?php echo esc_attr( $args['content'] . '-id' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-class' ); ?>">Class attribute</label></th>
						<td><input type="text" name="class" class="classvalue oneline option" id="<?php echo esc_attr( $args['content'] . '-class' ); ?>"></td>
					</tr>
					</tbody>
				</table>
			</fieldset>
		</div>

		<div class="insert-box">
			<input type="text" name="<?php echo $type; ?>" class="tag code" readonly="readonly" onfocus="this.select()" />

			<div class="submitbox">
				<input type="button" class="button button-primary insert-tag" value="<?php echo esc_attr( __( 'Insert Tag', 'contact-form-7' ) ); ?>" />
			</div>

			<br class="clear" />
		</div>
		<?php
	}

	/**
	 * TAG EDITOR FOR "ACTIONS"
	 * ========================
	 * Ie. configure what action to perform on form submit.
	 */
	public function tagGenerateAction($contact_form, $args = '')
	{
		$args = wp_parse_args( $args, array() );
		$type = 'gw_action';
		$description = "Generate the GatewayAPI Action field. This field instructs GatewayAPI on what to do with the submission.";
		?>
		<div class="control-box">
			<fieldset>
				<legend><?=esc_html( $description )?></legend>

				<table class="form-table">
					<tbody>

					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $args['content'] . '-action' ); ?>">Triggered action</label></th>
						<td>
							<label><input type="radio" checked name="action" class="option" value="signup" id="<?php echo esc_attr( $args['content'] . '-action-signup' ); ?>"> Signup</label>
							<p class="description"style="margin-top: 0; margin-bottom: 10px">Sign up a new subscriber. If a recipient with same phone number exists, the signup will fail.</p>

							<label><input type="radio" name="action" class="option" value="unsubscribe"> Unsubscribe</label>
							<p class="description"style="margin-top: 0; margin-bottom: 10px">Unsubscribe a subscriber, ie. move the recipient to the trash.</p>

							<label><input type="radio" name="action" class="option" value="update"> Update</label>
							<p class="description" style="margin-top: 0;">Update an existing subscriber, ie. a recipient with the given phone number must already exist.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="<?php echo esc_attr( $args['content'] . '-verify' ); ?>">Require verification</label>
						</th>
						<td>
							<label>
								<input type="radio" checked name="verify" class="option" id="<?php echo esc_attr( $args['content'] . '-verify' ); ?>" value="yes" />
								Yes (recommended)
							</label>
							<label>
								<input type="radio" name="verify" class="option" id="<?php echo esc_attr( $args['content'] . '-verify' ); ?>" value="no" />
								No
							</label>
							<p class="description">
								An SMS will be sent to confirm the ownership of the number with a one-time verification code.<br />
								When updating, this triggers another flow: The user will have to enter mobile and country code, verify the number and
								only then will the rest of the form be presented.
							</p>
						</td>
					</tr>
					</tbody>
				</table>
			</fieldset>
		</div>

		<div class="insert-box">
			<input type="text" name="<?php echo $type; ?>" class="tag code" readonly="readonly" onfocus="this.select()" />

			<div class="submitbox">
				<input type="button" class="button button-primary insert-tag" value="<?php echo esc_attr( __( 'Insert Tag', 'contact-form-7' ) ); ?>" />
			</div>

			<br class="clear" />
		</div>
		<?php
	}

	/**
	 * Get the default value for a field.
	 *
	 * @param $opt
	 * @param $name
	 * @return mixed|null|string
	 */
	public function getFieldDefaultValue($opt, $name)
	{
		if ( 'user_' == substr( $opt, 0, 5 ) && is_user_logged_in() ) {
			$primary_props = array( 'user_login', 'user_email', 'user_url' );
			$opt = in_array( $opt, $primary_props ) ? $opt : substr( $opt, 5 );

			$user = wp_get_current_user();
			$user_prop = $user->get( $opt );

			if ( ! empty( $user_prop ) ) {
				return $user_prop;
			}

		} elseif ( 'post_meta' == $opt && in_the_loop() ) {
			$val = (string) get_post_meta( get_the_ID(), $name, true );

			if ( strlen( $val ) ) {
				return $val;
			}
		} elseif ( 'get' == $opt && isset( $_GET[$name] ) ) {
			$val = isset($_GET[$name]) ? $_GET[$name] : null;

			if ( strlen( $val ) ) {
				return $val;
			}
		} elseif ( 'post' == $opt && isset( $_POST[$name] ) ) {
			$val = isset($_POST[$name]) ? $_POST[$name] : null;

			if ( strlen( $val ) ) {
				return $val;
			}
		} else {
			return $opt;
		}
	}

	public function handlePhone($contact_form)
	{
		$classes = ['gwapi-phone','wpcf7-form-control-wrap', str_replace(':', '', $contact_form['name'])];
		$group_ids = [];
		$field_id = $contact_form['name'] ? substr($contact_form['name'], 3) : null;
		$default_field = '';

		foreach($contact_form['options'] as $opt) {
			if (strpos($opt, 'class:')===0) $classes[] = substr($opt, strpos($opt, ':')+1);
			if (ctype_digit($opt)) $group_ids[] = (int)$opt;
			if (strpos($opt, 'default:')===0) $default_field = substr($opt, strpos($opt, ':')+1);
		}

		ob_start();

		$default = $this->getFieldDefaultValue($default_field,'gwapi_country');
		?>
		<span class="<?= implode($classes,' '); ?>">
			<input type="tel" name="gwapi_phone" id="<?= $field_id ? 'id="'.$field_id.'"' : ''; ?>" onkeyup="this.value = this.value.replace(/\D+/,'')" onchange="this.value = this.value.replace(/\D+/,'')" value="<?= esc_attr($default); ?>">
		</span>
		<?php

		return ob_get_clean();
	}

	public function handleCountry($contact_form)
	{
		$classes = ['gwapi-country','wpcf7-form-control-wrap', str_replace(':', '', $contact_form['name'])];
		$country_codes = [];
		$field_id = $contact_form['name'] ? substr($contact_form['name'], 3) : null;
		$default_field = '';
		foreach($contact_form['options'] as $opt) {
			if (strpos($opt, 'class:')===0) $classes[] = substr($opt, strpos($opt, ':')+1);
			if (strpos($opt, 'default:')===0) $default_field = substr($opt, strpos($opt, ':')+1);
		}
		foreach($contact_form['values'] as $opt) {
			if (preg_match('/^[\d,]+$/', $opt)) $country_codes = explode(',', $opt);
		}

		// list of country codes
		$all_country_codes = json_decode(file_get_contents(_gwapi_dir().'/lib/countries/countries.min.json'));
		$out_country_codes = [];

		foreach($all_country_codes->countries as $c) {
			if ($c->phone && (!$country_codes || in_array($c->phone, $country_codes))) $out_country_codes[] = $c;
		}

		// sort alfabetically
		usort($out_country_codes, function($a, $b) {
			return $a->name === $b->name ? 0 : ( $a->name > $b->name ? 1 : -1 );
		});

		$default = $this->getFieldDefaultValue($default_field,'gwapi_country');

		// just a single country code? then hide the field/pre-select this one valid option
		ob_start();
		if (count($country_codes) === 1):
			?>
			<span class="<?= implode($classes,' '); ?>">
				<input type="hidden" name="gwapi_country" value="<?= current($country_codes); ?>" <?= $field_id ? 'id="'.$field_id.'"' : ''; ?>>
			</span>
			<?php
		else:
			?>
				<span class="<?= implode($classes,' '); ?>">
					<select name="gwapi_country" <?= $field_id ? 'id="'.$field_id.'"' : ''; ?> <?= ($default?'value="'.esc_attr($default).'"':''); ?>>
						<?php foreach($out_country_codes as $c): ?>
							<option value="<?= $c->phone; ?>" <?= $default==$c->phone?'selected':'' ?>><?= $c->name ?> (+<?= $c->phone; ?>)</option>
						<?php endforeach; ?>
					</select>
				</span>
			<?php
		endif;

		return ob_get_clean();
	}

	public function handleGroups($contact_form)
	{
		$classes = ['gwapi-groups','wpcf7-form-control-wrap', str_replace(':', '', $contact_form['name'])];
		$group_ids = [];
		$is_hidden = false;
		$field_id = $contact_form['name'] ? substr($contact_form['name'], 3) : null;
		foreach($contact_form['options'] as $opt) {
			if (strpos($opt, 'class:')===0) $classes[] = substr($opt, strpos($opt, ':')+1);
			if (ctype_digit($opt)) $group_ids[] = (int)$opt;
			if ($opt === 'hidden') $is_hidden = true;
		}
		if (!$group_ids) return ''; // nothing to do

		// fetch the groups
		$groups = get_terms('gwapi-recipient-groups', ['hide_empty' => false, 'include' => $group_ids]);

		ob_start();
		if (!$is_hidden):
			?>
			<div class="<?= implode($classes,' '); ?>">
				<?php foreach($groups as $g): ?>
					<label style="margin-bottom: 5px; display: block;"><input type="checkbox" name="gwapi_groups[]" value="<?= $g->term_id; ?>"> <?= esc_html($g->name); ?></label>
				<?php endforeach; ?>
			</div>
		<?php
		else:
			foreach($groups as $g): ?>
			<span class="<?= implode($classes,' '); ?>">
				<input type="hidden" name="gwapi_groups[]" value="<?= $g->term_id; ?>">
			</span>
			<?php endforeach;
		endif;

		return ob_get_clean();
	}


	/**
	 * Validate groups entry.
	 *
	 * @param WPCF7_Validation $res
	 * @param $tag
	 *
	 * @return WPCF7_Validation
	 */
	public function validateGroups(WPCF7_Validation $res, $tag)
	{
		$groupsPossible = [];
		foreach($tag['options'] as $o) {
			if (ctype_digit($o)) $groupsPossible[] = $o;
		}
		$groupsPossible = array_unique($groupsPossible);
		$groupsSelected = isset($_POST['gwapi_groups']) ? array_unique($_POST['gwapi_groups']) : [];

		// if NOT hidden, then check:
		// are the groups selected within the list of possible groups?
		if (!in_array('hidden', $tag['options'])) {
			// iterate the posted groups - the groups posted must all be in the list of valid groups
			foreach($groupsSelected as $groupID) {
				if (!in_array($groupID, $groupsPossible)) {
					$res->invalidate($tag, 'One of the selected groups is invalid/should not be selectable. This should not happen, but may occur if the editor of this site has changed the settings for this form since you opened this page.');
					return $res;
				}
			}
		} else { // if IS hidden: ALL groups should have been submitted
			if (count($groupsPossible) != count($groupsSelected)) {
				$res->invalidate($tag, 'One of the selected groups is invalid/should not be selectable. This should not happen, but may occur if the editor of this site has changed the settings for this form since you opened this page.');
				return $res;
			}
		}

		return $res;
	}

	/**
	 * Validate phone number.
	 *
	 * @param WPCF7_Validation $res
	 * @param $tag
	 *
	 * @return WPCF7_Validation
	 */
	public function validatePhone(WPCF7_Validation $res, $tag)
	{
		$phone = isset($_POST['gwapi_phone']) ? $_POST['gwapi_phone'] : null;
		if (!$phone || !ctype_digit($phone)) {
			$res->invalidate($tag, 'The phone number must consist of digits only.');
			return $res;
		}

		$action = isset($_POST['gwapi_action']) ? $_POST['gwapi_action'] : null;
		if (!$action) return $res; // invalid, but this post will simply be ignored because of that, which is good

		$phone_exists = null;
		if (isset($_POST['gwapi_country']) && $_POST['gwapi_country'] && isset($_POST['gwapi_phone']) && $_POST['gwapi_phone']) {
			$q = new WP_Query(["post_type" => "gwapi-recipient", "meta_query" => [ [ 'key' => 'cc', 'value' => $_POST['gwapi_country'] ], ['key' => 'number', 'value' => $_POST['gwapi_phone']] ]]);
			$phone_exists = $q->have_posts();
		}

		// signup: does the phone number already exist?
		if (isset($_POST['gwapi_action']) && $_POST['gwapi_action'] == 'signup' && $phone_exists === true) {
			$res->invalidate($tag, 'You are already subscribed with this phone number.');
		}

		// unsubscribe or update: does the phone number already exist?
		if (isset($_POST['gwapi_action']) && in_array($_POST['gwapi_action'], ['unsubscribe', 'update']) && $phone_exists === false) {
			$res->invalidate($tag, 'You are not subscribed with this phone number.');
		}

		return $res;
	}

	/**
	 * Validate country code.
	 *
	 * @param WPCF7_Validation $res
	 * @param $tag
	 *
	 * @return WPCF7_Validation
	 */
	public function validateCountry(WPCF7_Validation $res, $tag)
	{
		$cc = isset($_POST['gwapi_country']) ? $_POST['gwapi_country'] : null;

		// do we have a list of country codes to limit from?
		$valid_country_codes = [];
		foreach($tag['values'] as $opt) {
			if (preg_match('/^[\d,]+$/', $opt)) $valid_country_codes = explode(',', $opt);
		}

		// no valid country codes? then ALL country codes are valid - load list of valid country codes
		if (!$valid_country_codes) {
			$all_country_codes = json_decode(file_get_contents(_gwapi_dir().'/lib/countries/countries.min.json'));

			foreach($all_country_codes->countries as $c) {
				if ($c->phone) $valid_country_codes[] = $c->phone;
			}
		}

		// is the country code entered, within the list of valid country codes?
		if (!in_array($cc, $valid_country_codes)) {
			$res->invalidate($tag, 'The phone country code selected, is not within the list of valid country codes.');
			return $res;
		}

		return $res;
	}

	/**
	 * Validate GatewayAPI Action.
	 *
	 * @param WPCF7_Validation $res
	 * @param $tag
	 *
	 * @return WPCF7_Validation
	 */
	public function validateAction(WPCF7_Validation $res, $tag)
	{
		// the action selected must be within the list of valid actions
		$action = isset($_POST['gwapi_action']) ? $_POST['gwapi_action'] : '';
		$shouldBe = substr($tag['name'], 7);

		if ($shouldBe != $action) {
			$res->invalidate($tag, 'The action for the form is not consistent with the per-form configured action. This should not happen, but may occur if the editor of this site has changed the settings for this form since you opened this page.');
			return $res;
		}

		// update action + verification
		if ($action === 'update' && in_array('verify:yes', $tag['options'])) {
			$code = get_transient('gwapi_verify_'.$_POST['gwapi_country'].$_POST['gwapi_phone']);
			if ($code != $_POST['_gwapi_token']) {
				$res->invalidate($tag, 'It doesn\'t seem that you have verified your number by SMS, or the verification has expired. Note that you must submit the form within 30 minutes after validating.');
				return $res;
			}
		}

		return $res;
	}

	public function handleAction($contact_form)
	{
		$classes = ['gwapi-action','wpcf7-form-control-wrap', str_replace(':', '', $contact_form['name'])];
		$with_verify = in_array('verify:yes', $contact_form['options']);
		ob_start();
		?>
		<span class="<?= implode($classes,' '); ?>">
			<input type="hidden" <?= $with_verify ? 'data-verify="true"' : ''; ?> name="gwapi_action" value="<?= substr($contact_form['name'], strpos($contact_form['name'], ':')+1); ?>">
		</span>

		<?php
		if (in_array('verify:yes', $contact_form['options'])) {
			wp_enqueue_script('gwapi_integration_contact_form_7', _gwapi_url().'js/integration_contact_form_7.js', ['jquery']);
			?>
			<script>
				var gwapi_admin_ajax = <?= json_encode(admin_url('admin-ajax.php')); ?>;
			</script>
			<?php
		}

		return ob_get_clean();
	}

	/**
	 * Send a verification SMS for the update/signup/unsubscribe form.
	 */
	public function sendVerifySms()
	{
		header("Content-type: application/json");

		// valid?
		if (!isset($_POST['cc']) || !isset($_POST['number'])) {
			die(json_encode(['success' => false, 'message' => 'You must supply both country code and phone number.']));
		}

		// prevent abuse
		$very_close = get_transient('gwapi_notify1_'.$_POST['cc'].$_POST['number']);
		$same_day = get_transient('gwapi_notify2_'.$_POST['cc'].$_POST['number']) ? : 0;
		//if ($very_close) die(json_encode(['success' => false, 'message' => 'You have very recently requested a verification SMS. To prevent abuse, your request has been blocked. Try again in a couple of minutes.']));
		//if ($same_day > 2) die(json_encode(['success' => false, 'message' => 'You have requested verification SMS\'es too many times during the last 24 hours. To prevent abuse, your request has been blocked.']));
		set_transient('gwapi_notify1_'.$_POST['cc'].$_POST['number'], 1, 60);
		set_transient('gwapi_notify2_'.$_POST['cc'].$_POST['number'], $same_day+1, 60*60*24);

		// save + send verification SMS
		$code = rand(100000,999999);
		set_transient('gwapi_verify_'.$_POST['cc'].$_POST['number'], $code, 60*30);
		gwapi_send_sms("Your verification code: ".$code, $_POST['cc'].$_POST['number']);

		die(json_encode(['success' => true]));
	}

	/**
	 * Verify an SMS code.
	 */
	public function verifySms()
	{
		header("Content-type: application/json");

		// valid?
		if (!isset($_POST['cc']) || !isset($_POST['number']) || !isset($_POST['code'])) {
			die(json_encode(['success' => false, 'message' => 'You must supply both country code and phone number.']));
		}

		// prevent abuse
		$this_phone = get_transient('gwapi_verify1_'.$_POST['cc'].$_POST['number']) ? : 0;
		if ($this_phone > 10) die(json_encode(['success' => false, 'message' => 'Due to too many attempts at verifying SMS-codes within a short period of time, your request has been blocked. Try again later.']));
		set_transient('gwapi_verify1_'.$_POST['cc'].$_POST['number'], $this_phone+1, 60*60*4);

		// check if the code is valid
		$code = get_transient('gwapi_verify_'.$_POST['cc'].$_POST['number']);
		if (!$code) die(json_encode(['success' => false, 'message' => 'There is no verification going on for this phone number. Perhaps you waited too long? These codes expire after 30 minutes.']));
		if ($code != $_POST['code']) die(json_encode(['success' => false, 'message' => 'The code is invalid. Please try again.']));

		// find the recipient, if there is any, and return all information
		$q = new WP_Query(["post_type" => "gwapi-recipient", "meta_query" => [ [ 'key' => 'cc', 'value' => $_POST['cc'] ], ['key' => 'number', 'value' => $_POST['number']] ]]);
		$recipient = null;
		if ($q->have_posts()) {
			$recipient = [
				'gwapi_phone' => $_POST['number'],
				'gwapi_country' => $_POST['cc'],
				'gwapi_groups' => []
			];
			$terms = wp_get_object_terms($q->post->ID, 'gwapi-recipient-groups');

			foreach($terms as $t) {
				$recipient['gwapi_groups'][] = $t->term_id;
			}
			foreach(get_post_meta($q->post->ID) as $key=>$val) {
				$recipient[$key] = current($val);
			}
		}
		die(json_encode(['success' => true, 'recipient' => $recipient]));
	}
}