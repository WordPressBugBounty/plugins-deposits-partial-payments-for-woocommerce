<?php
/**
 * Acowebs deactivation feedback modal (Acowebs feedback UI kit v1.0.0).
 *
 * Variables: $plugin_name, $user_name, $user_email.
 * The radio values are the untranslated reasons, so the dashboard groups them across languages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$awcdp_reasons = array(
	array(
		'value'       => "I couldn't get it to work",
		'label'       => __( "I couldn't get it to work", 'deposits-partial-payments-for-woocommerce' ),
		'placeholder' => __( 'What went wrong? Any error message helps.', 'deposits-partial-payments-for-woocommerce' ),
	),
	array(
		'value'       => 'I found a better plugin',
		'label'       => __( 'I found a better plugin', 'deposits-partial-payments-for-woocommerce' ),
		'placeholder' => __( 'Which plugin did you switch to? What does it do better?', 'deposits-partial-payments-for-woocommerce' ),
	),
	array(
		'value'       => "It's missing a feature I need",
		'label'       => __( "It's missing a feature I need", 'deposits-partial-payments-for-woocommerce' ),
		'placeholder' => __( 'Which feature were you looking for?', 'deposits-partial-payments-for-woocommerce' ),
	),
	array(
		'value'       => 'It broke my site or conflicts with another plugin',
		'label'       => __( 'It broke my site or conflicts with another plugin', 'deposits-partial-payments-for-woocommerce' ),
		'placeholder' => __( 'What broke, or which plugin does it conflict with?', 'deposits-partial-payments-for-woocommerce' ),
	),
	array(
		'value'       => 'I only needed it for a short time',
		'label'       => __( 'I only needed it for a short time', 'deposits-partial-payments-for-woocommerce' ),
		'placeholder' => __( 'Thanks! Anything we could improve?', 'deposits-partial-payments-for-woocommerce' ),
	),
	array(
		'value'       => 'Upgrading to PRO version',
		'label'       => __( 'Upgrading to PRO version', 'deposits-partial-payments-for-woocommerce' ),
		// No placeholder: this reason has no details box.
	),
	array(
		'value'       => 'Other',
		'label'       => __( 'Other', 'deposits-partial-payments-for-woocommerce' ),
		'placeholder' => __( 'Please tell us a little more.', 'deposits-partial-payments-for-woocommerce' ),
	),
);
?>
<div class="acofb acofb-overlay" id="acofb-awcdp-deactivation" data-acofb="deactivation" hidden>
  <div class="acofb-modal" role="dialog" aria-modal="true" aria-labelledby="acofb-awcdp-dx-title" aria-describedby="acofb-awcdp-dx-desc">
    <form class="acofb-modal-form" novalidate>
      <div class="acofb-modal-head">
        <span class="acofb-badge" aria-hidden="true">
          <svg viewBox="-8 -1.5 86 86" focusable="false"><path fill="#00DFB1" fill-rule="evenodd" d="M2.85642 22.0022C6.72119 18.0569 13.0525 17.9916 16.9978 21.8564L41.4978 45.8564C43.4052 47.7249 44.4861 50.2785 44.4999 52.9485C44.5136 55.6185 43.4591 58.1831 41.5711 60.0711L21.5711 80.0711C17.6658 83.9763 11.3342 83.9763 7.42894 80.0711C3.52369 76.1658 3.52369 69.8342 7.42894 65.9289L20.2846 53.0733L3.0022 36.1436C-0.943088 32.2788 -1.00836 25.9475 2.85642 22.0022Z"/><path fill="#00B2FF" fill-rule="evenodd" d="M60.1791 3.124C64.5289 7.36505 64.6171 14.3293 60.376 18.6791L48.4585 30.9022L65.7782 48.2218C70.0739 52.5176 70.0739 59.4824 65.7782 63.7782C61.4824 68.0739 54.5176 68.0739 50.2218 63.7782L25.2218 38.7782C20.9645 34.5208 20.9209 27.6318 25.124 23.3209L44.624 3.3209C48.8651 -1.02889 55.8293 -1.11705 60.1791 3.124Z"/><path fill="#0A84FF" fill-rule="evenodd" d="M43.5968 57.1543L25.2218 38.7786C22.1371 35.6937 21.2645 31.2271 22.6168 27.3607L41.4978 45.8572C43.4052 47.7257 44.4861 50.2794 44.4999 52.9495C44.5074 54.4143 44.1935 55.8473 43.5968 57.1543Z"/></svg>
        </span>
        <div>
          <h2 class="acofb-title" id="acofb-awcdp-dx-title">
            <?php
            /* translators: %s: plugin name */
            echo esc_html( sprintf( __( 'Quick question before you deactivate %s', 'deposits-partial-payments-for-woocommerce' ), $plugin_name ) );
            ?>
          </h2>
          <p class="acofb-sub" id="acofb-awcdp-dx-desc"><?php esc_html_e( 'If you have a moment, tell us why. The Acowebs team reads every answer and uses it to improve the plugin.', 'deposits-partial-payments-for-woocommerce' ); ?></p>
        </div>
      </div>

      <div class="acofb-modal-body">
        <fieldset class="acofb-reasons" data-api-field="reason">
          <legend class="acofb-sr">
            <?php
            /* translators: %s: plugin name */
            echo esc_html( sprintf( __( 'Why are you deactivating %s?', 'deposits-partial-payments-for-woocommerce' ), $plugin_name ) );
            ?>
          </legend>

          <?php foreach ( $awcdp_reasons as $awcdp_i => $awcdp_reason ) : ?>
            <?php $awcdp_n = $awcdp_i + 1; ?>
          <div class="acofb-reason">
            <input class="acofb-radio" type="radio" name="reason" id="acofb-awcdp-r<?php echo esc_attr( $awcdp_n ); ?>" value="<?php echo esc_attr( $awcdp_reason['value'] ); ?>" required>
            <label class="acofb-reason-card" for="acofb-awcdp-r<?php echo esc_attr( $awcdp_n ); ?>"><?php echo esc_html( $awcdp_reason['label'] ); ?></label>
            <?php if ( ! empty( $awcdp_reason['placeholder'] ) ) : ?>
            <div class="acofb-details" data-api-field="reason_details">
              <label class="acofb-sr" for="acofb-awcdp-d<?php echo esc_attr( $awcdp_n ); ?>"><?php esc_html_e( 'Tell us more (optional)', 'deposits-partial-payments-for-woocommerce' ); ?></label>
              <textarea class="acofb-textarea" id="acofb-awcdp-d<?php echo esc_attr( $awcdp_n ); ?>" name="reason_details" rows="3" maxlength="5000" placeholder="<?php echo esc_attr( $awcdp_reason['placeholder'] ); ?>"></textarea>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </fieldset>

        <div class="acofb-contact">
          <input class="acofb-check" type="checkbox" name="consent" id="acofb-awcdp-contact" value="1" checked>
          <label class="acofb-check-label" for="acofb-awcdp-contact" data-api-field="consent"><?php esc_html_e( 'Acowebs can contact me about this feedback', 'deposits-partial-payments-for-woocommerce' ); ?></label>
          <div class="acofb-contact-fields">
            <div class="acofb-field" data-api-field="name">
              <label class="acofb-label" for="acofb-awcdp-name"><?php esc_html_e( 'Name', 'deposits-partial-payments-for-woocommerce' ); ?></label>
              <input class="acofb-input" type="text" id="acofb-awcdp-name" name="name" value="<?php echo esc_attr( $user_name ); ?>" maxlength="255" autocomplete="name">
            </div>
            <div class="acofb-field" data-api-field="email">
              <label class="acofb-label" for="acofb-awcdp-email"><?php esc_html_e( 'Email', 'deposits-partial-payments-for-woocommerce' ); ?></label>
              <input class="acofb-input" type="email" id="acofb-awcdp-email" name="email" value="<?php echo esc_attr( $user_email ); ?>" maxlength="320" autocomplete="email">
            </div>
          </div>
        </div>

        <p class="acofb-privacy">
          <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 1a3.5 3.5 0 0 0-3.5 3.5V6H4a1.5 1.5 0 0 0-1.5 1.5v6A1.5 1.5 0 0 0 4 15h8a1.5 1.5 0 0 0 1.5-1.5v-6A1.5 1.5 0 0 0 12 6h-.5V4.5A3.5 3.5 0 0 0 8 1Zm2 5V4.5a2 2 0 1 0-4 0V6h4Z"/></svg>
          <span><?php esc_html_e( 'We only send this if you click Send: your answer, your site address, name and language, the plugin version and your WordPress, WooCommerce and PHP versions. Your name and email are included only while the box above is ticked.', 'deposits-partial-payments-for-woocommerce' ); ?> <a class="acofb-link" href="<?php echo esc_url( 'https://acowebs.com/privacy-policy/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy policy', 'deposits-partial-payments-for-woocommerce' ); ?><span class="acofb-sr"> <?php esc_html_e( '(opens in a new tab)', 'deposits-partial-payments-for-woocommerce' ); ?></span></a></span>
        </p>
      </div>

      <div class="acofb-modal-foot">
        <button type="submit" class="acofb-btn acofb-btn--primary acofb-btn--send" data-acofb-action="send"><?php esc_html_e( 'Send & deactivate', 'deposits-partial-payments-for-woocommerce' ); ?></button>
        <button type="button" class="acofb-btn acofb-btn--text" data-acofb-action="skip"><?php esc_html_e( 'Skip & deactivate', 'deposits-partial-payments-for-woocommerce' ); ?></button>
        <button type="button" class="acofb-btn acofb-btn--text acofb-btn--cancel" data-acofb-action="cancel"><?php esc_html_e( 'Cancel', 'deposits-partial-payments-for-woocommerce' ); ?></button>
      </div>
    </form>
  </div>
</div>
