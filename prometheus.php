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
    protected Gauge $onlineUsersCounter;

    public function __construct()
    {
        $this->init();
    }

    public function init()
    {
      if (!defined('PROMETHEUS_REGISTRY_DISABLE')) {
        $this->registry = $this->createRegistry();
        $this->addHooks();
        register_activation_hook( __FILE__, [$this, 'activatePlugin']);
        register_deactivation_hook( __FILE__, [$this, 'deactivatePlugin']);
      }
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
        $this->postViewCounter = $registry->getOrRegisterCounter('wordpress', 'post_view_count', 'when a post is viewed', ['post_title', 'post_id', 'site']);
        $this->postCommentCounter = $registry->getOrRegisterCounter('wordpress', 'post_comment_submitted', 'when a comment is submitted', ['post_id', 'status', 'site']);
        $this->postCounter = $registry->getOrRegisterGauge('wordpress', 'post_count', 'count of posts in various statuses', ['status', 'site']);
        $this->onlineUsersCounter = $registry->getOrRegisterGauge('wordpress', 'online_user_count', 'count of users online', ['location']);

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

    function getSite()
    {
        return $_SERVER['HTTP_HOST'];
    }

    function getPostStatusesMetric()
    {
        if (function_exists('wp_statistics_useronline')) {
            $onlineUsers = wp_statistics_useronline(['return' => 'all']);
            $locations = array_count_values(array_column($onlineUsers, 'location'));
            foreach ($locations as $location => $count) {
                $this->onlineUsersCounter->set($count, ['location' => $location]);
            }
        }

        $query = new WP_Query(['post_type' => 'any', 'post_status' => 'any']);
        $statuses = [];
        $site = $this->getSite();

        if ($query->have_posts()) {
            foreach ($query->posts as $post) {
                if (!array_key_exists($post->post_status, $statuses)) {
                    $statuses[$post->post_status] = 0;
                }

                $statuses[$post->post_status]++;
            }
        }

        foreach ($statuses as $status => $count) {
            $this->postCounter->set($count, ['status' => $status, 'site' => $site]);
        }
    }

    public function processPost($content)
    {
        global $wp_query;
        $post = $wp_query->post;
        $site = $this->getSite();

        if (is_single() || is_page()) {
            $this->postViewCounter->inc(['post_title' => $post->post_title, 'post_id' => $post->ID, 'site' => $site]);
        }
        return $content;
    }

    public function processComment(int $commentID, $commentApproved, array $commentData)
    {
        $postId = nil;
        if (array_key_exists('comment_post_ID', $commentData)) {
            $postId = $commentData['comment_post_ID'];
        }
        $site = $this->getSite();

        $this->postCommentCounter->inc(['post_id' => $postId, 'status' => $commentApproved, 'site' => $site]);
    }

    public function renderMetrics()
    {
        $url_path = trim(parse_url(add_query_arg(array()), PHP_URL_PATH), '/');
        if ($url_path == "metrics") {
	        if (current_user_can("view_metrics")) {
                header("Content-type: text/plain");
                $renderer = new RenderTextFormat();
                echo $renderer->render(static::getInstance()->registry->getMetricFamilySamples());
                exit();
            }
            global $wp_query;
	        $wp_query->set_404();
            status_header(404);
        }
    }

    public function activatePlugin() {
	    $role = add_role("view_metrics", _("Metrics Viewer"), array());
	    $role->add_cap("metricsViewer", true);
	    $admin = get_role("administrator");
	    $admin->add_cap("view_metrics");
    }

    public function deactivatePlugin() {
	    remove_role("view_metrics");
	    $admin = get_role("administrator");
	    $admin->remove_cap("view_metrics");
    }
}

// init
Prometheus::getInstance();
