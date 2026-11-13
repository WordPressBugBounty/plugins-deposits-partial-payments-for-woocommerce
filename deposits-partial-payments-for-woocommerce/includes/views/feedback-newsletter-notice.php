<?php
/**
 * Acowebs newsletter opt-in notice (Acowebs feedback UI kit v1.0.0).
 *
 * Variables: $plugin_name, $user_email.
 * list, source, consent, plugin, edition, site_url and site_name are added server-side
 * (AWCDP_Feedback::ajax_newsletter_optin()), so the kit's hidden inputs are not printed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="acofb acofb-notice" id="acofb-awcdp-newsletter" data-acofb="newsletter">
  <button type="button" class="acofb-close" data-acofb-action="dismiss" aria-label="<?php esc_attr_e( 'Dismiss', 'deposits-partial-payments-for-woocommerce' ); ?>">
    <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M4 4l8 8M12 4l-8 8" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/></svg>
  </button>

  <div class="acofb-notice-body">
    <div class="acofb-intro">
      <span class="acofb-badge acofb-badge--lg" aria-hidden="true">
        <svg viewBox="-8 -1.5 86 86" focusable="false"><path fill="#00DFB1" fill-rule="evenodd" d="M2.85642 22.0022C6.72119 18.0569 13.0525 17.9916 16.9978 21.8564L41.4978 45.8564C43.4052 47.7249 44.4861 50.2785 44.4999 52.9485C44.5136 55.6185 43.4591 58.1831 41.5711 60.0711L21.5711 80.0711C17.6658 83.9763 11.3342 83.9763 7.42894 80.0711C3.52369 76.1658 3.52369 69.8342 7.42894 65.9289L20.2846 53.0733L3.0022 36.1436C-0.943088 32.2788 -1.00836 25.9475 2.85642 22.0022Z"/><path fill="#00B2FF" fill-rule="evenodd" d="M60.1791 3.124C64.5289 7.36505 64.6171 14.3293 60.376 18.6791L48.4585 30.9022L65.7782 48.2218C70.0739 52.5176 70.0739 59.4824 65.7782 63.7782C61.4824 68.0739 54.5176 68.0739 50.2218 63.7782L25.2218 38.7782C20.9645 34.5208 20.9209 27.6318 25.124 23.3209L44.624 3.3209C48.8651 -1.02889 55.8293 -1.11705 60.1791 3.124Z"/><path fill="#0A84FF" fill-rule="evenodd" d="M43.5968 57.1543L25.2218 38.7786C22.1371 35.6937 21.2645 31.2271 22.6168 27.3607L41.4978 45.8572C43.4052 47.7257 44.4861 50.2794 44.4999 52.9495C44.5074 54.4143 44.1935 55.8473 43.5968 57.1543Z"/></svg>
      </span>
      <div class="acofb-intro-copy">
        <p class="acofb-eyebrow"><?php esc_html_e( 'Acowebs · WooCommerce plugins', 'deposits-partial-payments-for-woocommerce' ); ?></p>
        <h2 class="acofb-headline" id="acofb-awcdp-nl-title"><?php esc_html_e( 'Get new Acowebs plugins and features first', 'deposits-partial-payments-for-woocommerce' ); ?></h2>
        <p class="acofb-text">
          <?php
          /* translators: %s: plugin name */
          echo esc_html( sprintf( __( 'Thanks for using %s! Join our newsletter to hear about new plugins, feature updates and subscriber-only offers.', 'deposits-partial-payments-for-woocommerce' ), $plugin_name ) );
          ?>
        </p>
        <ul class="acofb-chips" aria-label="<?php esc_attr_e( 'What you get', 'deposits-partial-payments-for-woocommerce' ); ?>">
          <li class="acofb-chip acofb-chip--blue"><?php esc_html_e( 'New plugin launches', 'deposits-partial-payments-for-woocommerce' ); ?></li>
          <li class="acofb-chip acofb-chip--mint"><?php esc_html_e( 'Feature updates', 'deposits-partial-payments-for-woocommerce' ); ?></li>
          <li class="acofb-chip acofb-chip--cyan"><?php esc_html_e( 'WooCommerce tips', 'deposits-partial-payments-for-woocommerce' ); ?></li>
          <li class="acofb-chip acofb-chip--gold"><?php esc_html_e( 'Subscriber-only offers', 'deposits-partial-payments-for-woocommerce' ); ?></li>
        </ul>
      </div>
    </div>

    <form class="acofb-panel" aria-labelledby="acofb-awcdp-nl-title">
      <div class="acofb-field-row">
        <div class="acofb-field" data-api-field="name">
          <label class="acofb-label" for="acofb-awcdp-nl-name"><?php esc_html_e( 'First name', 'deposits-partial-payments-for-woocommerce' ); ?> <span class="acofb-optional"><?php esc_html_e( '(optional)', 'deposits-partial-payments-for-woocommerce' ); ?></span></label>
          <input class="acofb-input" type="text" id="acofb-awcdp-nl-name" name="name" maxlength="255" autocomplete="given-name">
        </div>
        <div class="acofb-field" data-api-field="phone">
          <label class="acofb-label" for="acofb-awcdp-nl-phone"><?php esc_html_e( 'Phone', 'deposits-partial-payments-for-woocommerce' ); ?> <span class="acofb-optional"><?php esc_html_e( '(optional)', 'deposits-partial-payments-for-woocommerce' ); ?></span></label>
          <input class="acofb-input" type="tel" id="acofb-awcdp-nl-phone" name="phone" maxlength="40" autocomplete="tel">
        </div>
      </div>
      <div class="acofb-field" data-api-field="email" data-api-required>
        <label class="acofb-label" for="acofb-awcdp-nl-email"><?php esc_html_e( 'Email', 'deposits-partial-payments-for-woocommerce' ); ?></label>
        <input class="acofb-input" type="email" id="acofb-awcdp-nl-email" name="email" value="<?php echo esc_attr( $user_email ); ?>" maxlength="320" autocomplete="email" required>
      </div>

      <div class="acofb-hp" aria-hidden="true" data-api-field="website_hp">
        <label for="acofb-awcdp-nl-hp">Website</label>
        <input type="text" id="acofb-awcdp-nl-hp" name="website_hp" tabindex="-1" autocomplete="off">
      </div>

      <div class="acofb-actions-row">
        <button type="submit" class="acofb-btn acofb-btn--primary" data-api-field="consent" data-api-value="true"><?php esc_html_e( 'Subscribe', 'deposits-partial-payments-for-woocommerce' ); ?> <span class="acofb-btn-arrow" aria-hidden="true">→</span></button>
        <button type="button" class="acofb-btn acofb-btn--text" data-acofb-action="dismiss"><?php esc_html_e( 'Not now', 'deposits-partial-payments-for-woocommerce' ); ?></button>
      </div>
      <p class="acofb-msg" role="status" aria-live="polite"></p>

      <p class="acofb-privacy">
        <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 1a3.5 3.5 0 0 0-3.5 3.5V6H4a1.5 1.5 0 0 0-1.5 1.5v6A1.5 1.5 0 0 0 4 15h8a1.5 1.5 0 0 0 1.5-1.5v-6A1.5 1.5 0 0 0 12 6h-.5V4.5A3.5 3.5 0 0 0 8 1Zm2 5V4.5a2 2 0 1 0-4 0V6h4Z"/></svg>
        <span><?php esc_html_e( 'About two emails a month from Acowebs: new plugins, updates and offers. We never sell or share your email, and you can unsubscribe anytime.', 'deposits-partial-payments-for-woocommerce' ); ?> <a class="acofb-link" href="<?php echo esc_url( 'https://acowebs.com/privacy-policy/' ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy policy', 'deposits-partial-payments-for-woocommerce' ); ?><span class="acofb-sr"> <?php esc_html_e( '(opens in a new tab)', 'deposits-partial-payments-for-woocommerce' ); ?></span></a></span>
      </p>
    </form>
  </div>

  <!-- Thank-you state: the JS shows this (and hides .acofb-notice-body) after a successful subscribe, then focuses it. -->
  <div class="acofb-thanks" role="status" tabindex="-1" hidden>
    <span class="acofb-thanks-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
    </span>
    <div>
      <p class="acofb-title"><?php esc_html_e( "You're in!", 'deposits-partial-payments-for-woocommerce' ); ?></p>
      <p class="acofb-sub"><?php esc_html_e( 'Watch your inbox for new plugins, updates and offers from Acowebs.', 'deposits-partial-payments-for-woocommerce' ); ?></p>
    </div>
  </div>
</div>
