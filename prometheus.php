<?php
/*
 Plugin Name: Prometheus
 Plugin URI: https://github.com/dannykopping/wp_prometheus
 description: An experiment
 Version: 0.0.1
 Author: Danny Kopping, Malcolm Holmes
 Author URI: http://grafana.com
 License: GPL2
 */

require_once __DIR__ . "/vendor/autoload.php";

use Prometheus\CollectorRegistry;
use Prometheus\Counter;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\APC;

class Prometheus
{
    protected static Prometheus $instance;

    protected CollectorRegistry $registry;
    protected Counter $postViewCounter;
    protected Counter $postCommentCounter;

    public function __construct()
    {
        $this->init();
    }

    public function init()
    {
        $this->registry = $this->createRegistry();
        $this->addHooks();
    }

    public static function getInstance(): Prometheus
    {
        if (empty(static::$instance)) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    private function createRegistry(): CollectorRegistry
    {
        $registry = new CollectorRegistry(new APC());
        $this->postViewCounter = $registry->registerCounter('prometheus', 'post_view', 'when a post is viewed', ['post_title', 'post_id']);
        $this->postCommentCounter = $registry->registerCounter('prometheus', 'post_comment_submitted', 'when a comment is submitted', ['post_id', 'status']);

        return $registry;
    }

    private function addHooks()
    {
        add_action('the_content', [$this, 'processPost']);
        add_action('comment_post', [$this, 'processComment'], 10, 3);
        add_action('rest_api_init', [$this, 'api']);
    }

    public function processPost()
    {
        global $wp_query;
        $post = $wp_query->post;

        if (is_single()) {
            $this->postViewCounter->inc(['post_title' => $post->post_title, 'post_id' => $post->ID]);
        }
    }

    public function processComment(int $commentID, $commentApproved, array $commentData)
    {
        $postId = nil;
        if (array_key_exists('comment_post_ID', $commentData)) {
            $postId = $commentData['comment_post_ID'];
        }

        $this->postCommentCounter->inc(['post_id' => $postId, 'status' => $commentApproved]);
    }

    public function api()
    {
        register_rest_route('prometheus', '/metrics', array(
            'methods' => 'GET',
            'callback' => [$this, 'renderMetrics'],
            'permission_callback' => '__return_true',
        ));
    }

    public function renderMetrics()
    {
        $renderer = new RenderTextFormat();
        $result = $renderer->render(static::getInstance()->registry->getMetricFamilySamples());

        $response = new WP_REST_Response();
        $response->header('Content-Type', RenderTextFormat::MIME_TYPE);

        // couldn't figure out how to have WP print the _raw_ value, so just echo-ing
        echo $result;

        return $response;
    }
}

// init
Prometheus::getInstance();