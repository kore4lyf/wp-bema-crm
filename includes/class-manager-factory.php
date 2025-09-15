<?php
namespace Bema;

use Bema\Utils;
use Bema\Providers\MailerLite;
use Bema\Database\Campaign_Database_Manager;
use Bema\Database\Field_Database_Manager;
use Bema\Database\Group_Database_Manager;
use Bema\Database\Subscribers_Database_Manager;
use Bema\Database\Campaign_group_Subscribers_Database_Manager;
use Bema\Database\Sync_Database_Manager;
use Bema\Database\Transition_Database_Manager;
use Bema\Database\Transition_Subscribers_Database_Manager;

class Manager_Factory
{
    public static function get_sync_manager(): \Bema\Sync_Manager
    {
        $manager = new \Bema\Sync_Manager();
        
        $settings = get_option('bema_crm_settings', []);
        $api_key = $settings['api']['mailerlite_api_key'] ?? '';
        
        $logger = \Bema\Bema_CRM_Logger::create('sync-manager');
        $utils = new Utils();
        
        global $wpdb;
        $manager->mailerLiteInstance = new MailerLite($api_key);
        $manager->logger = $logger;
        $manager->utils = $utils;
        $manager->campaign_database = new Campaign_Database_Manager();
        $manager->field_database = new Field_Database_Manager();
        $manager->group_database = new Group_Database_Manager();
        $manager->subscribers_database = new Subscribers_Database_Manager();
        $manager->campaign_group_subscribers_database = new Campaign_group_Subscribers_Database_Manager();
        $manager->sync_database = new Sync_Database_Manager();
        $manager->dbManager = new \Bema\Database_Manager($wpdb, $logger);
        
        return $manager;
    }

    public static function get_transition_manager(): \Bema\Transition_Manager
    {
        $manager = new \Bema\Transition_Manager();
        
        $settings = get_option('bema_crm_settings', []);
        $api_key = $settings['api']['mailerlite_api_key'] ?? '';
        
        $logger = \Bema\Bema_CRM_Logger::create('transition-manager');
        
        $manager->mailerLiteInstance = new MailerLite($api_key);
        $manager->logger = $logger;
        $manager->campaign_database = new Campaign_Database_Manager();
        $manager->group_database = new Group_Database_Manager();
        $manager->transition_database = new Transition_Database_Manager();
        $manager->transition_subscribers_database = new Transition_Subscribers_Database_Manager();
        
        return $manager;
    }
}
