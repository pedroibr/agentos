<?php
/**
 * Settings page template.
 *
 * @var string $option_key
 * @var array  $settings
 * @var string $message
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap">
  <h1><?php esc_html_e('AgentOS Settings', 'agentos'); ?></h1>
  <form method="post" action="options.php">
    <?php settings_fields($option_key); ?>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><?php esc_html_e('OpenAI API Key Source', 'agentos'); ?></th>
        <td>
          <label><input type="radio" name="<?php echo esc_attr($option_key); ?>[api_key_source]" value="env" <?php checked($settings['api_key_source'] ?? '', 'env'); ?>> <?php esc_html_e('ENV (OPENAI_API_KEY)', 'agentos'); ?></label><br>
          <label><input type="radio" name="<?php echo esc_attr($option_key); ?>[api_key_source]" value="constant" <?php checked($settings['api_key_source'] ?? 'constant', 'constant'); ?>> <?php esc_html_e('PHP constant (OPENAI_API_KEY)', 'agentos'); ?></label><br>
          <label><input type="radio" name="<?php echo esc_attr($option_key); ?>[api_key_source]" value="manual" <?php checked($settings['api_key_source'] ?? '', 'manual'); ?>> <?php esc_html_e('Manual', 'agentos'); ?></label>
          <p>
            <input type="text" style="width:420px" name="<?php echo esc_attr($option_key); ?>[api_key_manual]" value="<?php echo esc_attr($settings['api_key_manual'] ?? ''); ?>" placeholder="sk-..." />
          </p>
        </td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Enable console logging', 'agentos'); ?></th>
        <td>
          <label>
            <input type="checkbox" name="<?php echo esc_attr($option_key); ?>[enable_logging]" value="1" <?php checked(!empty($settings['enable_logging'])); ?>>
            <?php esc_html_e('Output AgentOS debug logs in browser console for troubleshooting.', 'agentos'); ?>
          </label>
        </td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Enable Integration API', 'agentos'); ?></th>
        <td>
          <label>
            <input type="checkbox" name="<?php echo esc_attr($option_key); ?>[integration_api_enabled]" value="1" <?php checked(!empty($settings['integration_api_enabled'])); ?>>
            <?php esc_html_e('Allow external automation tools to call AgentOS provisioning endpoints using a Bearer API key.', 'agentos'); ?>
          </label>
        </td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Integration API Key', 'agentos'); ?></th>
        <td>
          <?php if (!empty($settings['integration_api_key_plain'])) : ?>
            <p>
              <strong><?php esc_html_e('Current API key:', 'agentos'); ?></strong>
            </p>
            <p>
              <input type="text" readonly style="width:420px" value="<?php echo esc_attr($settings['integration_api_key_plain']); ?>" onclick="this.select();">
            </p>
          <?php else : ?>
            <p><?php esc_html_e('No integration API key generated yet.', 'agentos'); ?></p>
          <?php endif; ?>
          <?php if (!empty($settings['integration_api_key_generated_at'])) : ?>
            <p class="description">
              <?php
              printf(
                  esc_html__('Generated at: %s', 'agentos'),
                  esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $settings['integration_api_key_generated_at']))
              );
              ?>
            </p>
          <?php endif; ?>
          <p class="description"><?php esc_html_e('Send this key as Authorization: Bearer YOUR_KEY to the provisioning endpoints.', 'agentos'); ?></p>
        </td>
      </tr>
    </table>
    <p class="submit">
      <button type="submit" name="agentos_generate_integration_api_key" value="1" class="button button-secondary">
        <?php echo esc_html(!empty($settings['integration_api_key_hash']) ? __('Regenerate API Key', 'agentos') : __('Generate API Key', 'agentos')); ?>
      </button>
      <button type="submit" class="button button-primary">
        <?php esc_html_e('Save Changes', 'agentos'); ?>
      </button>
    </p>
  </form>
  <script>
    (function(){
      if (!<?php echo $settings['enable_logging'] ? 'true' : 'false'; ?>) {
        return;
      }
      const prefix = '[AgentOS][Settings]';
      const fieldName = '<?php echo esc_js($option_key); ?>[api_key_source]';
      const log = (...args) => { try { console.info(prefix, ...args); } catch (_) {} };
      document.addEventListener('DOMContentLoaded', function(){
        document.querySelectorAll('input[name="'+fieldName+'"]').forEach(function(radio){
          radio.addEventListener('change', function(){
            log('API key source changed', this.value);
          });
        });
      });
    })();
  </script>
</div>
