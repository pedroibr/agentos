<?php
/**
 * Settings page template.
 *
 * @var string $option_key
 * @var array  $settings
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
        <th scope="row"><?php esc_html_e('Context parameters (query-string)', 'agentos'); ?></th>
        <td>
          <input type="text" style="width:420px" name="<?php echo esc_attr($option_key); ?>[context_params]" value="<?php echo esc_attr(implode(',', $settings['context_params'] ?? ['nome','produto','etapa'])); ?>">
          <p class="description"><?php esc_html_e('Comma separated list of URL parameters passed through to the agent.', 'agentos'); ?></p>
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
    </table>
    <?php submit_button(); ?>
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
