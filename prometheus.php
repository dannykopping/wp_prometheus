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
use Prometheus\Gauge;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\APC;

class Prometheus
{
    protected static Prometheus $instance;

    protected CollectorRegistry $registry;
    protected Counter $postViewCounter;
    protected Counter $postCommentCounter;
    protected Gauge $postCounter;

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
        $this->postViewCounter = $registry->getOrRegisterCounter('wordpress', 'post_view_count', 'when a post is viewed', ['post_title', 'post_id']);
        $this->postCommentCounter = $registry->getOrRegisterCounter('wordpress', 'post_comment_submitted', 'when a comment is submitted', ['post_id', 'status']);
        $this->postCounter = $registry->getOrRegisterGauge('wordpress', 'post_count', 'count of posts in various statuses', ['status']);

        return $registry;
    }

    private function addHooks()
    {
        add_action('the_content', [$this, 'processPost']);
        add_action('comment_post', [$this, 'processComment'], 10, 3);

        // cron
        add_filter('cron_schedules', [$this, 'addShortCronInterval']);
        if (!wp_next_scheduled('metrics_hook')) {
            wp_schedule_event(time(), 'five_seconds', 'metrics_hook');
	}
	add_action('init', [$this, 'renderMetrics']);
	add_action('metrics_hook', [$this, 'getPostStatusesMetric']);
    }

    function addShortCronInterval($schedules)
    {
        $schedules['five_seconds'] = array(
            'interval' => 5,
            'display' => esc_html__('Every five seconds'),);
        return $schedules;
    }

    function getPostStatusesMetric()
    {
        $query = new WP_Query(['post_type' => 'any', 'post_status' => 'any']);
        $statuses = [];

        if ($query->have_posts()) {
            foreach ($query->posts as $post) {
                if(!array_key_exists($post->post_status, $statuses)) {
                    $statuses[$post->post_status] = 0;
                }

                $statuses[$post->post_status]++;
            }
        }

        foreach($statuses as $status => $count) {
            $this->postCounter->set($count, ['status' => $status]);
        }
    }

    public function processPost($content)
    {
        global $wp_query;
        $post = $wp_query->post;

	if (is_single() || is_page()) {
            $this->postViewCounter->inc(['post_title' => $post->post_title, 'post_id' => $post->ID]);
	}
	return $content;
    }

    public function processComment(int $commentID, $commentApproved, array $commentData)
    {
        $postId = nil;
        if (array_key_exists('comment_post_ID', $commentData)) {
            $postId = $commentData['comment_post_ID'];
        }

        $this->postCommentCounter->inc(['post_id' => $postId, 'status' => $commentApproved]);
    }

    public function renderMetrics()
    {
	$url_path = trim(parse_url(add_query_arg(array()), PHP_URL_PATH), '/');
	if ($url_path == "metrics") {
	    header("Content-type: text/plain");
            $renderer = new RenderTextFormat();
	    echo $renderer->render(static::getInstance()->registry->getMetricFamilySamples());
	    exit();
        }
    }
}
// init
Prometheus::getInstance();
