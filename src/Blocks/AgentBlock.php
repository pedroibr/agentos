<?php

namespace AgentOS\Blocks;

use AgentOS\Core\AgentRepository;
use AgentOS\Core\Config;
use AgentOS\Frontend\Shortcode;

class AgentBlock
{
    private Shortcode $shortcode;
    private AgentRepository $agents;

    public function __construct(Shortcode $shortcode, AgentRepository $agents)
    {
        $this->shortcode = $shortcode;
        $this->agents = $agents;
    }

    public function register(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        add_action('init', [$this, 'registerBlock']);
    }

    public function registerBlock(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }

        $scriptHandle = 'agentos-block-editor';
        $styleHandle = 'agentos-block-editor-style';

        wp_register_script(
            $scriptHandle,
            plugins_url('blocks/agent/editor.js', Config::pluginFile()),
            ['wp-blocks', 'wp-element', 'wp-i18n', 'wp-components', 'wp-block-editor', 'wp-data'],
            Config::VERSION,
            true
        );

        $agents = array_values(array_map(static function (array $agent): array {
            return [
                'value' => $agent['slug'],
                'label' => $agent['label'] ?: $agent['slug'],
                'defaultMode' => $agent['default_mode'] ?? 'voice',
            ];
        }, $this->agents->all()));

        wp_localize_script(
            $scriptHandle,
            'AgentOSBlockData',
            [
                'agents' => $agents,
                'defaults' => [
                    'height' => '70vh',
                ],
                'strings' => [
                    'panelTitle' => __('Agent settings', 'agentos'),
                    'fieldAgent' => __('Agent', 'agentos'),
                    'fieldMode' => __('Mode (optional)', 'agentos'),
                    'fieldHeight' => __('Transcript height', 'agentos'),
                    'optionDefault' => __('Use agent default', 'agentos'),
                    'optionVoice' => __('Voice only', 'agentos'),
                    'optionText' => __('Text only', 'agentos'),
                    'optionBoth' => __('Voice + Text', 'agentos'),
                    'placeholderSelect' => __('Choose an AgentOS agent to embed.', 'agentos'),
                    'placeholderNoAgents' => __('No agents found. Create one under AgentOS → Agents.', 'agentos'),
                    'previewHeading' => __('AgentOS Block', 'agentos'),
                ],
            ]
        );

        wp_set_script_translations($scriptHandle, 'agentos');

        wp_register_style(
            $styleHandle,
            plugins_url('blocks/agent/editor.css', Config::pluginFile()),
            ['wp-edit-blocks'],
            Config::VERSION
        );

        register_block_type(
            Config::pluginDir() . 'blocks/agent/block.json',
            [
                'editor_script' => $scriptHandle,
                'editor_style' => $styleHandle,
                'render_callback' => [$this, 'render'],
            ]
        );
    }

    public function render(array $attributes, string $content): string
    {
        $atts = [
            'id' => $attributes['agentId'] ?? '',
            'mode' => $attributes['mode'] ?? '',
            'height' => $attributes['height'] ?? '',
        ];

        return $this->shortcode->render($atts);
    }
}
