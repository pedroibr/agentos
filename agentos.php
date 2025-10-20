<?php
/**
 * Plugin Name: AgentOS – Dynamic AI Agents for WordPress
 * Description: Load customizable AI agents (text/voice) per post type. Map ACF/meta fields to agent parameters. Provides shortcodes and REST endpoints.
 * Version: 0.3.3
 * Author: Pedro Raimundo
 * Text Domain: agentos
 */

if (!defined('ABSPATH')) exit;

class AgentOS_Plugin {
  const OPT_SETTINGS = 'agentos_settings';
  const OPT_AGENTS   = 'agentos_agents';
  const SLUG         = 'agentos';
  const VERSION      = '0.3.3';
  const FALLBACK_MODEL = 'gpt-realtime-mini-2025-10-06';
  const FALLBACK_VOICE = 'alloy';
  const FALLBACK_PROMPT = 'You are a helpful, concise AI agent. Speak naturally.';

  public function __construct() {
    // Admin
    add_action('admin_menu', [$this, 'admin_menu']);
    add_action('admin_init', [$this, 'register_settings']);
    add_action('admin_post_agentos_save_agent', [$this, 'handle_save_agent']);
    add_action('admin_post_agentos_delete_agent', [$this, 'handle_delete_agent']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

    // Front
    add_action('init', [$this, 'register_shortcodes']);
    add_action('wp_enqueue_scripts', [$this, 'register_assets']);

    // REST
    add_action('rest_api_init', [$this, 'register_routes']);

    // DB setup
    register_activation_hook(__FILE__, [$this, 'maybe_create_transcript_table']);
  }

  /* -----------------------------
   * Settings & Admin UI
   * --------------------------- */
  public function admin_menu() {
    $cap = 'manage_options';
    $hook = add_menu_page(
      __('AgentOS', 'agentos'),
      __('AgentOS', 'agentos'),
      $cap,
      self::SLUG,
      [$this, 'render_agents_page'],
      'dashicons-format-chat',
      58
    );

    add_submenu_page(
      self::SLUG,
      __('Agents', 'agentos'),
      __('Agents', 'agentos'),
      $cap,
      self::SLUG,
      [$this, 'render_agents_page']
    );

    add_submenu_page(
      self::SLUG,
      __('Settings', 'agentos'),
      __('Settings', 'agentos'),
      $cap,
      self::SLUG . '-settings',
      [$this, 'render_settings_page']
    );
  }

  public function register_settings() {
    register_setting(self::OPT_SETTINGS, self::OPT_SETTINGS, [
      'type' => 'array',
      'sanitize_callback' => [$this, 'sanitize_settings'],
      'default' => [
        'api_key_source' => 'constant', // env | constant | manual
        'api_key_manual' => '',
        'context_params' => ['nome','produto','etapa'],
        'enable_logging' => false,
      ]
    ]);
  }

  public function sanitize_settings($input) {
    $out = is_array($input) ? $input : [];

    $source = $out['api_key_source'] ?? 'constant';
    $out['api_key_source'] = in_array($source, ['env','constant','manual'], true) ? $source : 'constant';
    $out['api_key_manual'] = sanitize_text_field($out['api_key_manual'] ?? '');
    $out['enable_logging'] = !empty($out['enable_logging']) ? 1 : 0;

    $ctx = $out['context_params'] ?? ['nome','produto','etapa'];
    if (is_string($ctx)) {
      $ctx = array_map('trim', explode(',', $ctx));
    }
    $ctx = (array)$ctx;
    $out['context_params'] = array_values(array_filter(array_map(function($k){
      $san = preg_replace('/[^a-z0-9_\-]/i','', $k);
      return $san;
    }, $ctx)));

    return $out;
  }

  private function get_settings() {
    $raw = get_option(self::OPT_SETTINGS, []);
    if (!is_array($raw)) $raw = [];
    return wp_parse_args($raw, [
      'api_key_source' => 'constant',
      'api_key_manual' => '',
      'context_params' => ['nome','produto','etapa'],
      'enable_logging' => false,
    ]);
  }

  private function resolve_api_key() {
    $s = $this->get_settings();
    $src = $s['api_key_source'] ?? 'constant';
    if ($src === 'env') {
      return getenv('OPENAI_API_KEY') ?: '';
    }
    if ($src === 'manual') {
      return $s['api_key_manual'] ?? '';
    }
    return defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
  }

  public function render_settings_page() {
    if (!current_user_can('manage_options')) return;
    $s = $this->get_settings();
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('AgentOS Settings', 'agentos'); ?></h1>
      <form method="post" action="options.php">
        <?php settings_fields(self::OPT_SETTINGS); ?>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><?php esc_html_e('OpenAI API Key Source', 'agentos'); ?></th>
            <td>
              <label><input type="radio" name="<?php echo esc_attr(self::OPT_SETTINGS); ?>[api_key_source]" value="env" <?php checked($s['api_key_source'] ?? '', 'env'); ?>> <?php esc_html_e('ENV (OPENAI_API_KEY)', 'agentos'); ?></label><br>
              <label><input type="radio" name="<?php echo esc_attr(self::OPT_SETTINGS); ?>[api_key_source]" value="constant" <?php checked($s['api_key_source'] ?? 'constant', 'constant'); ?>> <?php esc_html_e('PHP constant (OPENAI_API_KEY)', 'agentos'); ?></label><br>
              <label><input type="radio" name="<?php echo esc_attr(self::OPT_SETTINGS); ?>[api_key_source]" value="manual" <?php checked($s['api_key_source'] ?? '', 'manual'); ?>> <?php esc_html_e('Manual', 'agentos'); ?></label>
              <p>
                <input type="text" style="width:420px" name="<?php echo esc_attr(self::OPT_SETTINGS); ?>[api_key_manual]" value="<?php echo esc_attr($s['api_key_manual'] ?? ''); ?>" placeholder="sk-..." />
              </p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('Context parameters (query-string)', 'agentos'); ?></th>
            <td>
              <input type="text" style="width:420px" name="<?php echo esc_attr(self::OPT_SETTINGS); ?>[context_params]" value="<?php echo esc_attr(implode(',', $s['context_params'] ?? ['nome','produto','etapa'])); ?>">
              <p class="description"><?php esc_html_e('Comma separated list of URL parameters passed through to the agent.', 'agentos'); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><?php esc_html_e('Enable console logging', 'agentos'); ?></th>
            <td>
              <label>
                <input type="checkbox" name="<?php echo esc_attr(self::OPT_SETTINGS); ?>[enable_logging]" value="1" <?php checked(!empty($s['enable_logging'])); ?>>
                <?php esc_html_e('Output AgentOS debug logs in browser console for troubleshooting.', 'agentos'); ?>
              </label>
            </td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>
      <script>
        (function(){
          if (!<?php echo $s['enable_logging'] ? 'true' : 'false'; ?>) {
            return;
          }
          const prefix = '[AgentOS][Settings]';
          const fieldName = '<?php echo esc_js(self::OPT_SETTINGS); ?>[api_key_source]';
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
    <?php
  }

  public function render_agents_page() {
    if (!current_user_can('manage_options')) return;
    $action = isset($_GET['action']) ? sanitize_key($_GET['action']) : '';
    if ($action === 'edit' || $action === 'new') {
      $this->render_agent_form($action);
    } else {
      $this->render_agent_list();
    }
  }

  private function render_agent_list() {
    $agents = $this->get_agents();
    $message = isset($_GET['message']) ? sanitize_key($_GET['message']) : '';
    ?>
    <div class="wrap">
      <h1 class="wp-heading-inline"><?php esc_html_e('Agents', 'agentos'); ?></h1>
      <a href="<?php echo esc_url(add_query_arg(['page' => self::SLUG, 'action' => 'new'], admin_url('admin.php'))); ?>" class="page-title-action"><?php esc_html_e('Add New', 'agentos'); ?></a>
      <hr class="wp-header-end">
      <?php if ($message === 'saved'): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Agent saved.', 'agentos'); ?></p></div>
      <?php elseif ($message === 'deleted'): ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Agent deleted.', 'agentos'); ?></p></div>
      <?php endif; ?>
      <?php if (empty($agents)): ?>
        <p><?php esc_html_e('No agents yet. Create your first agent below.', 'agentos'); ?></p>
      <?php else: ?>
        <table class="widefat striped">
          <thead>
            <tr>
              <th><?php esc_html_e('Name', 'agentos'); ?></th>
              <th><?php esc_html_e('Slug', 'agentos'); ?></th>
              <th><?php esc_html_e('Mode', 'agentos'); ?></th>
              <th><?php esc_html_e('Post Types', 'agentos'); ?></th>
              <th><?php esc_html_e('Shortcode', 'agentos'); ?></th>
              <th><?php esc_html_e('Actions', 'agentos'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($agents as $agent): ?>
              <tr>
                <td><?php echo esc_html($agent['label'] ?: $agent['slug']); ?></td>
                <td><code><?php echo esc_html($agent['slug']); ?></code></td>
                <td><?php echo esc_html(ucfirst($agent['default_mode'] ?? 'voice')); ?></td>
                <td><?php echo esc_html(implode(', ', $agent['post_types'] ?? [])); ?></td>
                <td><code>[agentos id="<?php echo esc_attr($agent['slug']); ?>"]</code></td>
                <td>
                  <?php
                  $edit_url = add_query_arg(['page' => self::SLUG, 'action' => 'edit', 'agent' => $agent['slug']], admin_url('admin.php'));
                  $delete_url = wp_nonce_url(admin_url('admin-post.php?action=agentos_delete_agent&agent='.$agent['slug']), 'agentos_delete_agent');
                  ?>
                  <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'agentos'); ?></a> |
                  <a href="<?php echo esc_url($delete_url); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this agent?', 'agentos')); ?>');"><?php esc_html_e('Delete', 'agentos'); ?></a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <?php
  }

  private function render_agent_form($action) {
    $is_edit = ($action === 'edit');
    $agents = $this->get_agents();
    $agent_slug = '';
    $agent = $this->blank_agent();

    if ($is_edit) {
      $agent_slug = isset($_GET['agent']) ? sanitize_key($_GET['agent']) : '';
      if (!$agent_slug || !isset($agents[$agent_slug])) {
        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html__('Agent not found.', 'agentos'));
        return;
      }
      $agent = wp_parse_args($agents[$agent_slug], $this->blank_agent());
    }

    $title = $is_edit ? __('Edit Agent', 'agentos') : __('Add New Agent', 'agentos');
    $public_post_types = get_post_types(['public' => true], 'objects');
    $field_options = $this->collect_available_fields($public_post_types);
    $settings = $this->get_settings();
    $post_type_labels = [];
    foreach ($public_post_types as $slug => $obj) {
      $label = $obj->labels->singular_name ?? $obj->label ?? $slug;
      $post_type_labels[$slug] = $label ?: $slug;
    }

    if (!wp_script_is('agentos-admin', 'registered')) {
      wp_register_script('agentos-admin', plugins_url('assets/agentos-admin.js', __FILE__), ['jquery'], self::VERSION, true);
    }
    wp_enqueue_script('agentos-admin');
    wp_localize_script('agentos-admin', 'AgentOSAdminData', [
      'postTypes' => $post_type_labels,
      'fieldOptions' => $field_options,
      'existingMaps' => $agent['field_maps'] ?? [],
      'selectedPostTypes' => $agent['post_types'] ?? [],
      'hasAcf' => function_exists('acf_get_field_groups'),
      'loggingEnabled' => !empty($settings['enable_logging']),
      'strings' => [
        'noFields' => __('No ACF fields detected for this post type.', 'agentos'),
        'customLabel' => __('Custom meta key…', 'agentos'),
        'placeholders' => [
          'model' => __('Select model field', 'agentos'),
          'voice' => __('Select voice field', 'agentos'),
          'system_prompt' => __('Select system prompt field', 'agentos'),
          'user_prompt' => __('Select user prompt field', 'agentos'),
        ],
        'fieldHeadings' => [
          'model' => __('Model Field', 'agentos'),
          'voice' => __('Voice Field', 'agentos'),
          'system_prompt' => __('System Prompt Field', 'agentos'),
          'user_prompt' => __('User Prompt Field', 'agentos'),
        ],
        'customPlaceholder' => __('Meta key (e.g. field_name)', 'agentos'),
        'postTypePlaceholder' => __('Select post types…', 'agentos'),
        'emptyState' => __('Select at least one post type to configure field mappings.', 'agentos'),
      ],
    ]);
    ?>
    <div class="wrap">
      <h1><?php echo esc_html($title); ?></h1>
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('agentos_save_agent'); ?>
        <input type="hidden" name="action" value="agentos_save_agent">
        <input type="hidden" name="original_slug" value="<?php echo esc_attr($agent_slug); ?>">

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="agentos-label"><?php esc_html_e('Name', 'agentos'); ?></label></th>
            <td><input type="text" id="agentos-label" name="agent[label]" value="<?php echo esc_attr($agent['label']); ?>" class="regular-text" required></td>
          </tr>
          <tr>
            <th scope="row"><label for="agentos-slug"><?php esc_html_e('Slug', 'agentos'); ?></label></th>
            <td>
              <input type="text" id="agentos-slug" name="agent[slug]" value="<?php echo esc_attr($agent['slug']); ?>" class="regular-text" <?php echo $is_edit ? '' : 'required'; ?>>
              <p class="description"><?php esc_html_e('Used in the shortcode id attribute. Letters, numbers, dashes only.', 'agentos'); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="agentos-mode"><?php esc_html_e('Default Mode', 'agentos'); ?></label></th>
            <td>
              <select id="agentos-mode" name="agent[default_mode]">
                <?php foreach (['voice' => __('Voice only', 'agentos'), 'text' => __('Text only', 'agentos'), 'both' => __('Voice + Text', 'agentos')] as $val => $label): ?>
                  <option value="<?php echo esc_attr($val); ?>" <?php selected($agent['default_mode'], $val); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="agentos-default-model"><?php esc_html_e('Default Model', 'agentos'); ?></label></th>
            <td><input type="text" id="agentos-default-model" name="agent[default_model]" value="<?php echo esc_attr($agent['default_model']); ?>" class="regular-text"></td>
          </tr>
          <tr>
            <th scope="row"><label for="agentos-default-voice"><?php esc_html_e('Default Voice', 'agentos'); ?></label></th>
            <td><input type="text" id="agentos-default-voice" name="agent[default_voice]" value="<?php echo esc_attr($agent['default_voice']); ?>" class="regular-text"></td>
          </tr>
          <tr>
            <th scope="row"><label for="agentos-base-prompt"><?php esc_html_e('Fallback Instructions', 'agentos'); ?></label></th>
            <td>
              <textarea id="agentos-base-prompt" name="agent[base_prompt]" rows="5" class="large-text"><?php echo esc_textarea($agent['base_prompt']); ?></textarea>
              <p class="description"><?php esc_html_e('Used when no system prompt is provided by the mapped fields.', 'agentos'); ?></p>
            </td>
          </tr>
          <tr>
            <th scope="row"><label for="agentos-post-types"><?php esc_html_e('Allowed Post Types', 'agentos'); ?></label></th>
            <td>
              <select id="agentos-post-types" name="agent[post_types][]" multiple size="5" style="min-width:260px;">
                <?php foreach ($public_post_types as $pt => $obj): 
                  $label = $obj->labels->singular_name ?: $pt;
                  ?>
                  <option value="<?php echo esc_attr($pt); ?>" <?php selected(in_array($pt, $agent['post_types'], true)); ?>>
                    <?php echo esc_html($label); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <p class="description"><?php esc_html_e('Choose the post types this agent supports. Use Ctrl/Cmd + click for multiple.', 'agentos'); ?></p>
            </td>
          </tr>
        </table>

        <h2><?php esc_html_e('Field Mappings', 'agentos'); ?></h2>
        <p><?php esc_html_e('Choose an ACF field or enter a custom meta key for each mapping. Leave empty to fall back to the defaults above.', 'agentos'); ?></p>
        <div id="agentos-field-mappings" data-field-map-target></div>
        <noscript>
          <p><em><?php esc_html_e('Enable JavaScript in your browser to configure field mappings.', 'agentos'); ?></em></p>
        </noscript>

        <?php submit_button($is_edit ? __('Update Agent', 'agentos') : __('Create Agent', 'agentos')); ?>
      </form>
    </div>
    <?php
  }

  private function collect_available_fields($post_types) {
    $fields = [];
    foreach ($post_types as $slug => $obj) {
      $fields[$slug] = [];
    }

    if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
      return $fields;
    }

    foreach ($post_types as $slug => $obj) {
      $groups = acf_get_field_groups(['post_type' => $slug]);
      if (empty($groups)) {
        continue;
      }
      $list = [];
      $seen = [];
      foreach ($groups as $group) {
        $group_fields = acf_get_fields($group);
        $this->flatten_acf_fields($group_fields, $list, $seen);
      }
      $fields[$slug] = array_values($list);
    }

    return $fields;
  }

  private function flatten_acf_fields($fields, &$list, &$seen) {
    if (empty($fields) || !is_array($fields)) {
      return;
    }
    foreach ($fields as $field) {
      if (!is_array($field)) {
        continue;
      }
      $name = $field['name'] ?? '';
      $label = $field['label'] ?? $name;
      if ($name && !isset($seen[$name])) {
        $list[] = [
          'key' => $name,
          'label' => $label ?: $name,
        ];
        $seen[$name] = true;
      }
      if (!empty($field['sub_fields'])) {
        $this->flatten_acf_fields($field['sub_fields'], $list, $seen);
      }
      if (!empty($field['layouts']) && is_array($field['layouts'])) {
        foreach ($field['layouts'] as $layout) {
          if (!empty($layout['sub_fields'])) {
            $this->flatten_acf_fields($layout['sub_fields'], $list, $seen);
          }
        }
      }
    }
  }

  private function blank_agent() {
    return [
      'label' => '',
      'slug' => '',
      'default_mode' => 'voice',
      'default_model' => self::FALLBACK_MODEL,
      'default_voice' => self::FALLBACK_VOICE,
      'base_prompt' => '',
      'post_types' => [],
      'field_maps' => [],
    ];
  }

  private function get_agents() {
    $agents = get_option(self::OPT_AGENTS, []);
    if (!is_array($agents)) return [];
    return array_map(function($agent){
      return wp_parse_args($agent, $this->blank_agent());
    }, $agents);
  }

  private function save_agents($agents) {
    update_option(self::OPT_AGENTS, $agents);
  }

  private function ensure_unique_slug($slug, $original, $agents) {
    $base = $slug;
    $i = 1;
    while (isset($agents[$slug]) && $slug !== $original) {
      $slug = $base . '-' . $i;
      $i++;
    }
    return $slug;
  }

  private function sanitize_agent_input($input, $slug) {
    $agent = $this->blank_agent();
    $agent['label'] = sanitize_text_field($input['label'] ?? '');
    $agent['slug']  = $slug;

    $agent['default_model'] = sanitize_text_field($input['default_model'] ?? self::FALLBACK_MODEL);
    if (!$agent['default_model']) $agent['default_model'] = self::FALLBACK_MODEL;

    $agent['default_voice'] = sanitize_text_field($input['default_voice'] ?? self::FALLBACK_VOICE);
    if (!$agent['default_voice']) $agent['default_voice'] = self::FALLBACK_VOICE;

    $mode = $input['default_mode'] ?? 'voice';
    $agent['default_mode'] = in_array($mode, ['voice','text','both'], true) ? $mode : 'voice';

    $agent['base_prompt'] = sanitize_textarea_field($input['base_prompt'] ?? '');

    $public_post_types = get_post_types(['public' => true], 'names');
    $requested_pts = array_map('sanitize_text_field', (array)($input['post_types'] ?? []));
    $agent['post_types'] = array_values(array_intersect($requested_pts, $public_post_types));

    $maps = [];
    if (!empty($input['field_maps']) && is_array($input['field_maps'])) {
      foreach ($input['field_maps'] as $pt => $map) {
        $pt_key = sanitize_text_field($pt);
        if (!in_array($pt_key, $public_post_types, true)) continue;
        $maps[$pt_key] = [
          'model' => sanitize_text_field($map['model'] ?? ''),
          'voice' => sanitize_text_field($map['voice'] ?? ''),
          'system_prompt' => sanitize_text_field($map['system_prompt'] ?? ''),
          'user_prompt' => sanitize_text_field($map['user_prompt'] ?? ''),
        ];
      }
    }
    $agent['field_maps'] = $maps;

    return $agent;
  }

  public function handle_save_agent() {
    if (!current_user_can('manage_options')) {
      wp_die(__('Insufficient permissions.', 'agentos'));
    }
    check_admin_referer('agentos_save_agent');

    $input = isset($_POST['agent']) ? (array)$_POST['agent'] : [];
    $original = isset($_POST['original_slug']) ? sanitize_key($_POST['original_slug']) : '';

    $agents = $this->get_agents();

    $requested_slug = '';
    if (!empty($input['slug'])) {
      $requested_slug = sanitize_key($input['slug']);
    }
    if (!$requested_slug && !empty($input['label'])) {
      $requested_slug = sanitize_key(sanitize_title($input['label']));
    }
    if (!$requested_slug) {
      $requested_slug = 'agent-' . wp_generate_password(6, false, false);
    }

    $slug = $this->ensure_unique_slug($requested_slug, $original, $agents);
    $agent = $this->sanitize_agent_input($input, $slug);

    if ($original && $original !== $slug) {
      unset($agents[$original]);
    }

    $agents[$slug] = $agent;
    $this->save_agents($agents);

    wp_redirect(add_query_arg(['page' => self::SLUG, 'message' => 'saved'], admin_url('admin.php')));
    exit;
  }

  public function handle_delete_agent() {
    if (!current_user_can('manage_options')) {
      wp_die(__('Insufficient permissions.', 'agentos'));
    }
    check_admin_referer('agentos_delete_agent');

    $slug = isset($_GET['agent']) ? sanitize_key($_GET['agent']) : '';
    if ($slug) {
      $agents = $this->get_agents();
      if (isset($agents[$slug])) {
        unset($agents[$slug]);
        $this->save_agents($agents);
      }
    }
    wp_redirect(add_query_arg(['page' => self::SLUG, 'message' => 'deleted'], admin_url('admin.php')));
    exit;
  }

  /* -----------------------------
   * Assets & Shortcode
   * --------------------------- */
  public function enqueue_admin_assets($hook) {
    if (strpos($hook, self::SLUG) === false) {
      return;
    }
    wp_register_script('agentos-admin', plugins_url('assets/agentos-admin.js', __FILE__), ['jquery'], self::VERSION, true);
  }

  public function register_assets() {
    wp_register_script('agentos-embed', plugins_url('assets/agentos-embed.js', __FILE__), [], self::VERSION, true);
  }

  public function register_shortcodes() {
    add_shortcode('agentos', [$this, 'shortcode_agentos']);
  }

  public function shortcode_agentos($atts) {
    $atts = shortcode_atts([
      'id' => '',
      'mode' => '', // optional override
      'height' => '70vh',
    ], $atts, 'agentos');

    $agent_id = sanitize_key($atts['id']);
    if (!$agent_id) {
      return '<!-- agentos: missing id -->';
    }

    $agents = $this->get_agents();
    if (!isset($agents[$agent_id])) {
      return '<!-- agentos: unknown agent -->';
    }
    $agent = $agents[$agent_id];

    $post_id = get_the_ID();
    if (!$post_id) {
      return '<!-- agentos: invalid context -->';
    }
    $post_type = get_post_type($post_id);
    if ($agent['post_types'] && !in_array($post_type, $agent['post_types'], true)) {
      return '<!-- agentos: agent not enabled for this post type -->';
    }

    wp_enqueue_script('agentos-embed');

    $settings = $this->get_settings();
    $mode = $atts['mode'] ? sanitize_key($atts['mode']) : $agent['default_mode'];
    if (!in_array($mode, ['voice','text','both'], true)) {
      $mode = $agent['default_mode'];
    }

    $config = [
      'agent_id' => $agent['slug'],
      'mode' => $mode,
      'rest' => esc_url_raw(rest_url('agentos/v1')),
      'nonce' => wp_create_nonce('wp_rest'),
      'post_id' => $post_id,
      'context_params' => $settings['context_params'] ?? [],
      'logging' => !empty($settings['enable_logging']),
    ];

    $config_attr = esc_attr(wp_json_encode($config));

    ob_start(); ?>
    <div class="agentos-wrap" data-agent="<?php echo esc_attr($agent['slug']); ?>" data-mode="<?php echo esc_attr($mode); ?>" data-height="<?php echo esc_attr($atts['height']); ?>" data-config="<?php echo $config_attr; ?>">
      <div class="agentos-left">
        <div class="agentos-bar">
          <button class="agentos-start">🎙️ <?php esc_html_e('Start', 'agentos'); ?></button>
          <button class="agentos-stop" disabled>⏹️ <?php esc_html_e('Stop', 'agentos'); ?></button>
          <button class="agentos-save" disabled>💾 <?php esc_html_e('Save transcript', 'agentos'); ?></button>
          <small class="agentos-status" style="margin-left:auto"></small>
        </div>
        <audio class="agentos-audio" autoplay playsinline></audio>
        <div class="agentos-text-ui" style="margin-top:10px;display:none">
          <textarea class="agentos-text-input" rows="2" style="width:100%" placeholder="<?php esc_attr_e('Type a message and press Send', 'agentos'); ?>"></textarea>
          <button class="agentos-text-send" style="margin-top:6px"><?php esc_html_e('Send', 'agentos'); ?></button>
        </div>
      </div>

      <div class="agentos-right" style="width:360px;max-width:40vw">
        <div class="agentos-transcript" style="border:1px solid #e9e9e9;border-radius:12px;padding:12px;height:<?php echo esc_attr($atts['height']); ?>;overflow:auto;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.03);font:14px/1.4 system-ui,-apple-system,Segoe UI,Roboto,Arial">
          <h4 style="margin:0 0 8px;font-size:12px;opacity:.7;font-weight:600"><?php esc_html_e('Transcript (live)', 'agentos'); ?></h4>
          <div class="agentos-transcript-log"></div>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }

  /* -----------------------------
   * REST API
   * --------------------------- */
  public function register_routes() {
    register_rest_route('agentos/v1', '/realtime-token', [
      'methods' => 'POST',
      'permission_callback' => [$this, 'permission_nonce'],
      'callback' => [$this, 'route_realtime_token']
    ]);

    register_rest_route('agentos/v1', '/transcript-db', [
      'methods' => WP_REST_Server::CREATABLE,
      'permission_callback' => [$this, 'permission_nonce'],
      'callback' => [$this, 'route_transcript_save']
    ]);

    register_rest_route('agentos/v1', '/transcript-db', [
      'methods' => WP_REST_Server::READABLE,
      'permission_callback' => [$this, 'permission_transcript_list'],
      'callback' => [$this, 'route_transcript_list']
    ]);
  }

  private function get_agent($slug) {
    $agents = $this->get_agents();
    return $agents[$slug] ?? null;
  }

  private function get_field_val($post_id, $key) {
    if (!$key) return '';
    if (function_exists('get_field')) {
      $v = get_field($key, $post_id);
      if ($v !== null && $v !== '') return $v;
    }
    $v = get_post_meta($post_id, $key, true);
    return $v ?: '';
  }

  private function collect_post_config($agent, $post_id) {
    $post_type = get_post_type($post_id);
    $map = $agent['field_maps'][$post_type] ?? [];

    $model = $this->get_field_val($post_id, $map['model'] ?? '') ?: ($agent['default_model'] ?: self::FALLBACK_MODEL);
    $voice = $this->get_field_val($post_id, $map['voice'] ?? '') ?: ($agent['default_voice'] ?: self::FALLBACK_VOICE);

    $instructions = $this->get_field_val($post_id, $map['system_prompt'] ?? '');
    if (!$instructions) {
      $instructions = $agent['base_prompt'] ?: self::FALLBACK_PROMPT;
    }

    $user_prompt = $this->get_field_val($post_id, $map['user_prompt'] ?? '');

    return [
      'model' => sanitize_text_field($model),
      'voice' => sanitize_text_field($voice),
      'instructions' => sanitize_textarea_field($instructions),
      'user_prompt' => sanitize_textarea_field($user_prompt),
      'mode' => $agent['default_mode'] ?? 'voice',
    ];
  }

  private function check_rest_nonce(WP_REST_Request $request) {
    $nonce = $request->get_header('X-WP-Nonce');
    if (!$nonce) {
      $nonce = $request->get_param('_wpnonce');
    }
    if (!$nonce) {
      return false;
    }
    $nonce = sanitize_text_field($nonce);
    return (bool) wp_verify_nonce($nonce, 'wp_rest');
  }

  public function permission_nonce(WP_REST_Request $request) {
    if ($this->check_rest_nonce($request)) {
      return true;
    }
    return new WP_Error('rest_forbidden', __('Invalid or missing security nonce.', 'agentos'), [
      'status' => rest_authorization_required_code(),
    ]);
  }

  public function permission_transcript_list(WP_REST_Request $request) {
    if (!$this->check_rest_nonce($request)) {
      return new WP_Error('rest_forbidden', __('Invalid or missing security nonce.', 'agentos'), [
        'status' => rest_authorization_required_code(),
      ]);
    }
    if (!current_user_can('manage_options')) {
      return new WP_Error('rest_forbidden', __('You do not have permission to view transcripts.', 'agentos'), [
        'status' => 403,
      ]);
    }
    return true;
  }

  public function route_realtime_token(WP_REST_Request $req) {
    $post_id = intval($req->get_param('post_id') ?: 0);
    $agent_id = sanitize_key($req->get_param('agent_id'));

    if (!$post_id) {
      return new WP_Error('no_post', __('post_id required', 'agentos'), ['status' => 400]);
    }
    if (!$agent_id) {
      return new WP_Error('no_agent', __('agent_id required', 'agentos'), ['status' => 400]);
    }

    $agent = $this->get_agent($agent_id);
    if (!$agent) {
      return new WP_Error('invalid_agent', __('Agent not found', 'agentos'), ['status' => 404]);
    }

    $post_type = get_post_type($post_id);
    if ($agent['post_types'] && !in_array($post_type, $agent['post_types'], true)) {
      return new WP_Error('agent_unavailable', __('Agent not available for this post type.', 'agentos'), ['status' => 403]);
    }

    $cfg = $this->collect_post_config($agent, $post_id);
    $api_key = $this->resolve_api_key();
    if (!$api_key) {
      return new WP_Error('no_key', __('OpenAI key not configured', 'agentos'), ['status'=>500]);
    }

    $settings = $this->get_settings();
    $ctx_in = $req->get_param('ctx');
    $ctxPairs = [];
    if (is_array($ctx_in)) {
      $allowed = $settings['context_params'] ?? [];
      foreach ($ctx_in as $k=>$v) {
        if (!in_array($k, $allowed, true)) continue;
        $kk = preg_replace('/[^a-z0-9_\-]/i','', $k);
        $vv = sanitize_text_field($v);
        if ($kk && $vv !== '') {
          $ctxPairs[] = "$kk=$vv";
        }
      }
    }

    $instructions = $cfg['instructions'] ?: self::FALLBACK_PROMPT;
    if ($ctxPairs) {
      $instructions .= "\n\nContext: " . implode(', ', $ctxPairs) . '.';
    }

    $payload = [
      'model' => $cfg['model'],
      'voice' => $cfg['voice'],
      'modalities' => ['audio','text'],
      'instructions' => $instructions
    ];

    $resp = wp_remote_post('https://api.openai.com/v1/realtime/sessions', [
      'headers' => [
        'Authorization' => 'Bearer ' . $api_key,
        'Content-Type'  => 'application/json'
      ],
      'body' => wp_json_encode($payload),
      'timeout' => 25
    ]);
    if (is_wp_error($resp)) {
      return new WP_Error('openai_transport', $resp->get_error_message(), ['status'=>500]);
    }

    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    $json = json_decode($body, true);
    if ($code >= 400 || !is_array($json)) {
      return new WP_Error('openai_http', $body ?: __('Unknown error', 'agentos'), ['status' => $code ?: 500]);
    }

    $client_secret = $json['client_secret']['value'] ?? null;
    if (!$client_secret) {
      return new WP_Error('openai_no_secret', __('Ephemeral token missing', 'agentos'), ['status'=>500]);
    }

    return [
      'client_secret' => $client_secret,
      'id' => $json['id'] ?? null,
      'model' => $cfg['model'],
      'voice' => $cfg['voice'],
      'mode' => $cfg['mode'],
      'user_prompt' => $cfg['user_prompt'],
    ];
  }

  /* -----------------------------
   * Transcript DB (optional)
   * --------------------------- */
  public function maybe_create_transcript_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'agentos_transcripts';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      post_id BIGINT UNSIGNED NOT NULL,
      agent_id VARCHAR(64) NOT NULL DEFAULT '',
      session_id VARCHAR(64) NOT NULL,
      anon_id VARCHAR(64) DEFAULT '',
      model VARCHAR(128) DEFAULT '',
      voice VARCHAR(64) DEFAULT '',
      user_email VARCHAR(190) DEFAULT '',
      user_agent TEXT,
      transcript LONGTEXT,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY post_id (post_id),
      KEY agent_id (agent_id)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
  }

  public function route_transcript_save(WP_REST_Request $req) {
    global $wpdb;
    $table = $wpdb->prefix . 'agentos_transcripts';
    $this->maybe_create_transcript_table();

    $post_id    = intval($req->get_param('post_id') ?: 0);
    $agent_id   = sanitize_key($req->get_param('agent_id') ?: '');
    $session_id = sanitize_text_field($req->get_param('session_id') ?: '');
    $anon_id    = sanitize_text_field($req->get_param('anon_id') ?: '');
    $model      = sanitize_text_field($req->get_param('model') ?: '');
    $voice      = sanitize_text_field($req->get_param('voice') ?: '');
    $ua         = $req->get_param('user_agent') ?: '';
    $transcript = $req->get_param('transcript');
    $current_user = wp_get_current_user();
    $user_email = '';
    if ($current_user && $current_user instanceof WP_User && $current_user->exists()) {
      $user_email = sanitize_email($current_user->user_email);
    }

    if (!$post_id || !$session_id || !$agent_id || !is_array($transcript)) {
      return new WP_Error('bad_payload', __('Missing fields', 'agentos'), ['status'=>400]);
    }
    $ok = $wpdb->insert($table, [
      'post_id' => $post_id,
      'agent_id' => $agent_id,
      'session_id' => $session_id,
      'anon_id' => $anon_id,
      'model' => $model,
      'voice' => $voice,
      'user_email' => $user_email,
      'user_agent' => maybe_serialize($ua),
      'transcript' => wp_json_encode($transcript),
      'created_at' => current_time('mysql'),
    ]);
    if (!$ok) {
      $reason = $wpdb->last_error ? sanitize_text_field($wpdb->last_error) : __('Database insert failed', 'agentos');
      return new WP_Error('db_insert', $reason, ['status'=>500]);
    }

    return ['id' => intval($wpdb->insert_id)];
  }

  public function route_transcript_list(WP_REST_Request $req) {
    global $wpdb;
    $table = $wpdb->prefix . 'agentos_transcripts';
    $post_id = intval($req->get_param('post_id') ?: 0);
    $agent_id = sanitize_key($req->get_param('agent_id') ?: '');
    $limit   = min(100, max(1, intval($req->get_param('limit') ?: 10)));
    if (!$post_id) {
      return new WP_Error('no_post', __('post_id required', 'agentos'), ['status'=>400]);
    }
    if ($agent_id) {
      $query = $wpdb->prepare("SELECT * FROM $table WHERE post_id=%d AND agent_id=%s ORDER BY id DESC LIMIT %d", $post_id, $agent_id, $limit);
    } else {
      $query = $wpdb->prepare("SELECT * FROM $table WHERE post_id=%d ORDER BY id DESC LIMIT %d", $post_id, $limit);
    }
    $rows = $wpdb->get_results($query, ARRAY_A);
    return array_map(function($r){
      $r['transcript'] = json_decode($r['transcript'], true);
      return $r;
    }, $rows ?: []);
  }
}

new AgentOS_Plugin();
