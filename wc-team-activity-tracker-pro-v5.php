<?php
/*
Plugin Name: WooCommerce Team Activity Tracker Pro
Description: v5 — Compatible with WooCommerce Orders (new HPOS screens) and classic editor. Adds top admin bar Assign menu, edit-page assignment panel, list-page quick assign, logging & performance report.
Version: 5.0.1
Author: Service Key
Author URI: https://servicekey.com.bd/
Text Domain: wc-team-activity-tracker-pro
*/

if ( ! defined('ABSPATH') ) exit;

class WCTAT_Pro_V5 {
    private static $instance;
    private $table;
    private $nonce = 'wctat_v5_nonce';
    private $opt = 'wctat_pro_v5';
    private $tracked_statuses = array();

    public static function instance(){
        if( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct(){
        global $wpdb;
        $this->table = $wpdb->prefix . 'wctat_pro_logs';

/* Tracked statuses (dynamic columns in report) */
$this->tracked_statuses = array(
    'completed'      => __('Completed','wc-team-activity-tracker-pro'),
    'cancelled'      => __('Cancelled','wc-team-activity-tracker-pro'),
    'confirm'        => __('Confirm','wc-team-activity-tracker-pro'),
    'scheduled'      => __('Scheduled','wc-team-activity-tracker-pro'),
    'couldnt-reach'  => __('Couldn\'t Reach','wc-team-activity-tracker-pro'),
);
/* Register custom order statuses so they show up in WC and can be set on orders */
add_action('init', array($this,'register_custom_statuses'));
add_filter('wc_order_statuses', array($this,'add_custom_statuses'));


        register_activation_hook(__FILE__, array($this,'activate'));
        register_deactivation_hook(__FILE__, array($this,'deactivate'));

        add_action('admin_enqueue_scripts', array($this,'enqueue'));

        // Orders list (classic)
        add_filter('manage_edit-shop_order_columns', array($this,'add_col'), 30);
        add_action('manage_shop_order_posts_custom_column', array($this,'render_col'), 10, 2);

        // AJAX for assign
        add_action('wp_ajax_wctat_assign_me', array($this,'ajax_assign_me'));
        add_action('wp_ajax_wctat_assign_user', array($this,'ajax_assign_user'));

        // Edit screen panel (works for classic & HPOS)
        add_action('admin_notices', array($this,'assignment_panel')); // fallback banner panel
        add_action('admin_post_wctat_assign_save', array($this,'handle_assign_save'));

        // Admin bar quick assign (always visible on WC order edit screens)
        add_action('admin_bar_menu', array($this,'admin_bar_menu'), 100);

        // Logging
        add_action('woocommerce_order_status_changed', array($this,'log_status'), 10, 4);
        add_action('comment_post', array($this,'log_note'), 10, 2);
        
        // Auto-assign when order is updated (both classic & HPOS)
        add_action('save_post_shop_order', array($this,'auto_assign_on_update'), 10, 3);
        add_action('woocommerce_update_order', array($this,'auto_assign_on_hpos_update'), 10, 1);

        // Report
        add_action('admin_menu', array($this,'menu'));

        // Weekly
        add_filter('cron_schedules', array($this,'weekly_schedule'));
        add_action('wctat_pro_weekly', array($this,'weekly_email'));
    }

    public function activate(){
        $this->create_table();
        if ( ! wp_next_scheduled('wctat_pro_weekly') ) {
            wp_schedule_event(time(),'weekly','wctat_pro_weekly');
        }
        if ( get_option($this->opt) === false ){
            update_option($this->opt, array('summary_email'=>get_option('admin_email')) );
        }
    }
    public function deactivate(){
        wp_clear_scheduled_hook('wctat_pro_weekly');
    }
    private function create_table(){
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            action VARCHAR(60) NOT NULL,
            from_status VARCHAR(60) NULL,
            to_status VARCHAR(60) NULL,
            note TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /* ---------- Helpers ---------- */
    private function current_order_id(){
        if ( isset($_GET['post']) && get_post_type(intval($_GET['post']))==='shop_order' ){
            return intval($_GET['post']);
        }
        // HPOS editor: admin.php?page=wc-orders&action=edit&id=XXXX
        if ( isset($_GET['page']) && $_GET['page']==='wc-orders' && isset($_GET['id']) ){
            return intval($_GET['id']);
        }
        return 0;
    }

    /* ---------- Assets ---------- */
    public function enqueue($hook){
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id = $screen ? $screen->id : '';
        $is_orders_list_classic = ($hook==='edit.php' && isset($_GET['post_type']) && $_GET['post_type']==='shop_order');
        $is_orders_list_hpos = ($id==='woocommerce_page_wc-orders');
        $is_order_edit_classic = ($hook==='post.php' && $this->current_order_id());
        $is_order_edit_hpos = ($id==='woocommerce_page_wc-orders' && isset($_GET['action']) && $_GET['action']==='edit');

        if ( $is_orders_list_classic || $is_orders_list_hpos || $is_order_edit_classic || $is_order_edit_hpos ){
            wp_enqueue_script('wctat-v5', plugin_dir_url(__FILE__).'wctat-v5.js', array('jquery'), '5.0.0', true);
            wp_localize_script('wctat-v5', 'WCTATv5', array(
                'ajax'=>admin_url('admin-ajax.php'),
                'nonce'=>wp_create_nonce($this->nonce),
            ));
            wp_enqueue_style('wctat-v5', plugin_dir_url(__FILE__).'wctat-v5.css', array(), '5.0.0');
        }
    }

    /* ---------- Orders list (classic only column) ---------- */
    public function add_col($cols){
        $new = array();
        foreach($cols as $k=>$v){
            $new[$k]=$v;
            if ($k==='order_status'){ $new['wctat_assigned']=__('Assigned To','wc-team-activity-tracker-pro'); }
        }
        if (!isset($new['wctat_assigned'])) $new['wctat_assigned']=__('Assigned To','wc-team-activity-tracker-pro');
        return $new;
    }
    public function render_col($col, $post_id){
        if ($col!=='wctat_assigned') return;
        $uid = get_post_meta($post_id,'_wctat_assigned_to',true);
        $name = $uid ? ( get_userdata($uid) ? get_userdata($uid)->display_name : 'User#'.$uid ) : '— Unassigned —';
        echo '<strong>'.esc_html($name).'</strong><br>';
        if ( current_user_can('edit_shop_orders') ){
            echo '<button class="button wctat-assign-me" data-order="'.esc_attr($post_id).'" data-nonce="'.esc_attr(wp_create_nonce($this->nonce)).'">'.esc_html__('Assign to Me','wc-team-activity-tracker-pro').'</button>';
        }
    }

    /* ---------- Assignment panel (fallback banner) ---------- */
    public function assignment_panel(){
        $order_id = $this->current_order_id();
        if ( ! $order_id ) return;
        if ( ! current_user_can('edit_shop_orders') ) return;

        $uid = get_post_meta($order_id,'_wctat_assigned_to',true);
        $users = get_users(array('role__in'=>array('administrator','shop_manager'),'fields'=>array('ID','display_name','user_login')));

        echo '<div class="notice notice-info wctat-panel"><p><strong>Team Assignment</strong> — Assign a responsible team member for this order.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="wctat-form">';
        echo '<input type="hidden" name="action" value="wctat_assign_save"/>';
        echo '<input type="hidden" name="order_id" value="'.esc_attr($order_id).'"/>';
        wp_nonce_field('wctat_assign_save');
        echo '<select name="user_id">';
        echo '<option value="">— Not assigned —</option>';
        foreach($users as $u){
            printf('<option value="%d" %s>%s (%s)</option>', $u->ID, selected($uid,$u->ID,false), esc_html($u->display_name), esc_html($u->user_login));
        }
        echo '</select>';
        echo '<button class="button button-primary">'.esc_html__('Save Assignment','wc-team-activity-tracker-pro').'</button>';
        echo '<button class="button wctat-assign-me-now" data-order="'.esc_attr($order_id).'" data-nonce="'.esc_attr(wp_create_nonce($this->nonce)).'">'.esc_html__('Assign to Me','wc-team-activity-tracker-pro').'</button>';
        echo '<span class="wctat-ok">✅ Plugin Loaded</span>';
        echo '</form></div>';
    }
    public function handle_assign_save(){
        if ( ! current_user_can('edit_shop_orders') ) wp_die('No permission');
        check_admin_referer('wctat_assign_save');
        $order_id = intval($_POST['order_id']);
        $user_id = intval($_POST['user_id']);
        if ($user_id){
            update_post_meta($order_id,'_wctat_assigned_to',$user_id);
            update_post_meta($order_id,'_wctat_assigned_at', current_time('mysql'));
            $this->insert_log($order_id, get_current_user_id(), 'assignment_changed', null, null, 'Saved via panel');
        } else {
            delete_post_meta($order_id,'_wctat_assigned_to');
            delete_post_meta($order_id,'_wctat_assigned_at');
            $this->insert_log($order_id, get_current_user_id(), 'assignment_changed', null, null, 'Cleared assignment');
        }
        wp_safe_redirect( wp_get_referer() ?: admin_url('admin.php?page=wc-orders') );
        exit;
    }

    /**
     * Auto-assign the current user when they update an order (Classic post editor).
     * Works for both Administrator and Shop Manager roles.
     * Only auto-assigns if the order is currently unassigned.
     */
    public function auto_assign_on_update($post_id, $post = null, $update = null){
        // Skip autosaves and revisions
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( wp_is_post_revision($post_id) ) return;
        if ( ! is_admin() ) return;
        
        // Check if user has permission
        if ( ! current_user_can('edit_shop_orders') ) return;

        $user_id = get_current_user_id();
        if ( ! $user_id ) return;
        
        $user = get_userdata($user_id);
        if ( ! $user ) return;
        
        // Only for administrators and shop managers
        $user_roles = (array) $user->roles;
        if ( ! in_array('shop_manager', $user_roles, true) && ! in_array('administrator', $user_roles, true) ) return;

        // If already assigned to someone, do not override
        $current = get_post_meta($post_id, '_wctat_assigned_to', true);
        if ( $current && intval($current) ) return;

        // Auto-assign to current user
        update_post_meta($post_id, '_wctat_assigned_to', $user_id);
        update_post_meta($post_id, '_wctat_assigned_at', current_time('mysql'));
        $this->insert_log($post_id, $user_id, 'assignment_changed', null, null, 'Auto-assigned on order update');
    }

    /**
     * Auto-assign when order is updated via HPOS (WooCommerce's new order system).
     */
    public function auto_assign_on_hpos_update($order_id){
        if ( ! is_admin() ) return;
        if ( ! current_user_can('edit_shop_orders') ) return;

        $user_id = get_current_user_id();
        if ( ! $user_id ) return;
        
        $user = get_userdata($user_id);
        if ( ! $user ) return;
        
        // Only for administrators and shop managers
        $user_roles = (array) $user->roles;
        if ( ! in_array('shop_manager', $user_roles, true) && ! in_array('administrator', $user_roles, true) ) return;

        // If already assigned to someone, do not override
        $current = get_post_meta($order_id, '_wctat_assigned_to', true);
        if ( $current && intval($current) ) return;

        // Auto-assign to current user
        update_post_meta($order_id, '_wctat_assigned_to', $user_id);
        update_post_meta($order_id, '_wctat_assigned_at', current_time('mysql'));
        $this->insert_log($order_id, $user_id, 'assignment_changed', null, null, 'Auto-assigned on HPOS order update');
    }

    /* ---------- Admin bar menu ---------- */
    public function admin_bar_menu($wp_admin_bar){
        if ( ! is_admin() || ! current_user_can('edit_shop_orders') ) return;
        $order_id = $this->current_order_id();
        if ( ! $order_id ) return;

        $wp_admin_bar->add_menu( array(
            'id'=>'wctat_assign_root',
            'title'=>'Assign Order',
            'href'=>false,
            'meta'=>array('class'=>'wctat-assign-root'),
        ));
        $wp_admin_bar->add_menu( array(
            'id'=>'wctat_assign_me',
            'parent'=>'wctat_assign_root',
            'title'=>'Assign to Me',
            'href'=>'#',
            'meta'=>array('class'=>'wctat-assign-me','html'=>'<a href="#" class="ab-item" id="wctat-adminbar-assign" data-order="'.$order_id.'" data-nonce="'.wp_create_nonce($this->nonce).'">Assign to Me</a>')
        ));

        $users = get_users(array('role__in'=>array('administrator','shop_manager'),'fields'=>array('ID','display_name')));
        foreach($users as $u){
            $wp_admin_bar->add_menu( array(
                'id'=>'wctat_assign_user_'.$u->ID,
                'parent'=>'wctat_assign_root',
                'title'=>'Assign to '.$u->display_name,
                'href'=>wp_nonce_url(admin_url('admin-ajax.php?action=wctat_assign_user&order_id='.$order_id.'&user_id='.$u->ID), $this->nonce),
                'meta'=>array('target'=>'_self')
            ));
        }
    }

    /* ---------- AJAX ---------- */
    public function ajax_assign_me(){
        check_ajax_referer($this->nonce);
        if ( ! current_user_can('edit_shop_orders') ) wp_send_json_error('no-permission');
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : ( isset($_GET['order_id']) ? intval($_GET['order_id']) : 0 );
        if ( ! $order_id ) wp_send_json_error('no-order');
        $uid = get_current_user_id();
        update_post_meta($order_id,'_wctat_assigned_to',$uid);
        update_post_meta($order_id,'_wctat_assigned_at', current_time('mysql'));
        $this->insert_log($order_id, $uid, 'assignment_changed', null, null, 'Assign to self');
        wp_send_json_success(array('user_id'=>$uid));
    }

    public function ajax_assign_user(){
        check_ajax_referer($this->nonce);
        if ( ! current_user_can('edit_shop_orders') ) wp_send_json_error('no-permission');
        $order_id = intval($_REQUEST['order_id']);
        $user_id  = intval($_REQUEST['user_id']);
        if ( ! $order_id || ! $user_id ) wp_send_json_error('bad-data');
        update_post_meta($order_id,'_wctat_assigned_to',$user_id);
        update_post_meta($order_id,'_wctat_assigned_at', current_time('mysql'));
        $this->insert_log($order_id, get_current_user_id(), 'assignment_changed', null, null, 'Assigned via admin bar');
        wp_send_json_success(array('user_id'=>$user_id));
    }

    /* ---------- Logging ---------- */
    public function log_status($order_id, $old, $new, $order){
        $uid = get_current_user_id();
        if ($new==='completed') update_post_meta($order_id,'_wctat_completed_at', current_time('mysql'));
        $this->insert_log($order_id, $uid?:null, 'status_changed', $old, $new, 'Status changed');
    }
    public function log_note($comment_id, $approved){
        $c = get_comment($comment_id);
        if (!$c) return;
        if ($c->comment_type!=='order_note' && $c->comment_type!=='') return;
        $oid = $c->comment_post_ID;
        if (get_post_type($oid)!=='shop_order') return;
        $uid = get_current_user_id() ?: $c->user_id;
        $this->insert_log($oid, $uid?:null, 'order_note_added', null, null, $c->comment_content);
    }
    private function insert_log($order_id,$user_id,$action,$from=null,$to=null,$note=null){
        global $wpdb;
        $wpdb->insert($this->table, array(
            'order_id'=>$order_id,'user_id'=>$user_id,'action'=>$action,
            'from_status'=>$from,'to_status'=>$to,'note'=>$note,'created_at'=>current_time('mysql')
        ), array('%d','%d','%s','%s','%s','%s','%s'));
    }

    
    /* ---------- Custom Statuses ---------- */
    public function register_custom_statuses(){
        // Register custom order statuses so WooCommerce knows them.
        register_post_status( 'wc-confirm', array(
            'label'                     => _x( 'Confirm', 'Order status', 'wc-team-activity-tracker-pro' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Confirm <span class="count">(%s)</span>', 'Confirm <span class="count">(%s)</span>', 'wc-team-activity-tracker-pro' ),
        ) );
        register_post_status( 'wc-scheduled', array(
            'label'                     => _x( 'Scheduled', 'Order status', 'wc-team-activity-tracker-pro' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( 'Scheduled <span class="count">(%s)</span>', 'Scheduled <span class="count">(%s)</span>', 'wc-team-activity-tracker-pro' ),
        ) );
        register_post_status( 'wc-couldnt-reach', array(
            'label'                     => _x( "Couldn't Reach", 'Order status', 'wc-team-activity-tracker-pro' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop( "Couldn't Reach <span class=\"count\">(%s)</span>", "Couldn't Reach <span class=\"count\">(%s)</span>", 'wc-team-activity-tracker-pro' ),
        ) );
    }
    public function add_custom_statuses( $statuses ){
        // Insert after Processing if present; otherwise append.
        $new = array(
            'wc-confirm'        => _x( 'Confirm', 'Order status', 'wc-team-activity-tracker-pro' ),
            'wc-scheduled'      => _x( 'Scheduled', 'Order status', 'wc-team-activity-tracker-pro' ),
            'wc-couldnt-reach'  => _x( "Couldn't Reach", 'Order status', 'wc-team-activity-tracker-pro' ),
        );
        $out = array();
        $inserted = false;
        foreach ($statuses as $k=>$v){
            $out[$k] = $v;
            if (!$inserted && $k==='wc-processing'){
                $out = array_merge($out, $new);
                $inserted = true;
            }
        }
        if (!$inserted) $out = array_merge($out, $new);
        return $out;
    }
/* ---------- Report ---------- */
    public function menu(){
        add_submenu_page('woocommerce','Team Performance (Pro v5)','Team Performance (Pro v5)','manage_woocommerce','wctat-pro-v5',array($this,'report'));
    }
    
    public function report(){
        if ( ! current_user_can('manage_woocommerce') ) wp_die('No permission');
        global $wpdb;
        $start = isset($_GET['start']) ? sanitize_text_field($_GET['start']) : date('Y-m-d', strtotime('-30 days'));
        $end   = isset($_GET['end']) ? sanitize_text_field($_GET['end'])   : date('Y-m-d');

        // Build dynamic SELECT aggregations for tracked statuses
        $selects = array(
            "SUM(CASE WHEN action='assignment_changed' THEN 1 ELSE 0 END) assignments"
        );
        $prepare_vals = array();
        foreach ($this->tracked_statuses as $slug => $label){
            $alias = str_replace('-', '_', $slug);
            $selects[] = "SUM(CASE WHEN action='status_changed' AND to_status=%s THEN 1 ELSE 0 END) {$alias}";
            $prepare_vals[] = $slug; // note: Woo passes status without wc- prefix
        }
        $order_by = array_key_exists('completed', $this->tracked_statuses) ? 'completed' : 'actions';
        $sql = "SELECT user_id,
                " . implode(",\n                ", $selects) . ",
                COUNT(*) actions
             FROM {$this->table}
             WHERE DATE(created_at) BETWEEN %s AND %s
             GROUP BY user_id
             ORDER BY {$order_by} DESC, actions DESC";
        $prepare_vals[] = $start;
        $prepare_vals[] = $end;
        $rows = $wpdb->get_results( $wpdb->prepare($sql, $prepare_vals) );

        echo '<div class="wrap"><h1>Team Performance (Pro v5)</h1>';
        echo '<form method="GET" style="margin-bottom:12px;"><input type="hidden" name="page" value="wctat-pro-v5"/>';
        echo 'From: <input type="date" name="start" value="'.esc_attr($start).'"/> ';
        echo 'To: <input type="date" name="end" value="'.esc_attr($end).'"/> ';
        submit_button('Filter','secondary','',false);
        echo '</form>';

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>User</th><th>Actions</th><th>Assignments</th>';
        foreach ($this->tracked_statuses as $slug=>$label){
            echo '<th>'.esc_html($label).'</th>';
        }
        echo '</tr></thead><tbody>';

        if ($rows){
            foreach($rows as $r){
                $name = $r->user_id ? ( get_userdata($r->user_id) ? get_userdata($r->user_id)->display_name : 'User#'.$r->user_id ) : 'System/Unknown';
                echo '<tr>';
                printf('<td>%s</td><td>%d</td><td>%d</td>',
                    esc_html($name), intval($r->actions), intval($r->assignments)
                );
                foreach ($this->tracked_statuses as $slug=>$label){
                    $alias = str_replace('-', '_', $slug);
                    $val = isset($r->$alias) ? intval($r->$alias) : 0;
                    echo '<td>'. $val .'</td>';
                }
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="20">No data</td></tr>';
        }
        echo '</tbody></table></div>';
    }


    /* ---------- Weekly ---------- */
    public function weekly_schedule($s){ if(!isset($s['weekly'])) $s['weekly']=array('interval'=>7*24*60*60,'display'=>__('Once Weekly')); return $s; }
    
    public function weekly_email(){
        global $wpdb;
        $start = date('Y-m-d',strtotime('-7 days'));
        $end   = date('Y-m-d');
        // Dynamic select same as report
        $selects = array(
            "SUM(CASE WHEN action='assignment_changed' THEN 1 ELSE 0 END) assignments"
        );
        $prepare_vals = array();
        foreach ($this->tracked_statuses as $slug=>$label){
            $alias = str_replace('-', '_', $slug);
            $selects[] = "SUM(CASE WHEN action='status_changed' AND to_status=%s THEN 1 ELSE 0 END) {$alias}";
            $prepare_vals[] = $slug;
        }
        $sql = "SELECT user_id,
                " . implode(",\n                ", $selects) . ",
                COUNT(*) actions
             FROM {$this->table}
             WHERE DATE(created_at) BETWEEN %s AND %s
             GROUP BY user_id";
        $prepare_vals[] = $start;
        $prepare_vals[] = $end;
        $rows = $wpdb->get_results( $wpdb->prepare($sql, $prepare_vals) );

        $body = "Weekly Team Activity ($start to $end)\n\n";
        if ($rows){
            foreach($rows as $r){
                $name = $r->user_id ? ( get_userdata($r->user_id) ? get_userdata($r->user_id)->display_name : 'User#'.$r->user_id ) : 'System/Unknown';
                $parts = array(
                    'Actions:'.intval($r->actions),
                    'Assignments:'.intval($r->assignments)
                );
                foreach ($this->tracked_statuses as $slug=>$label){
                    $alias = str_replace('-', '_', $slug);
                    $val = isset($r->$alias) ? intval($r->$alias) : 0;
                    $parts[] = $label.':'.$val;
                }
                $body .= $name . ' — ' . implode(', ', $parts) . "\n";
            }
        } else {
            $body .= "No activity.\n";
        }
        $opts = get_option($this->opt, array());
        $to = isset($opts['summary_email']) ? $opts['summary_email'] : get_option('admin_email');
        wp_mail($to,'Weekly Team Activity (Pro v5)', $body);
    }

}
WCTAT_Pro_V5::instance();
