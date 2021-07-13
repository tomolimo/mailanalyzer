<?php

define ("PLUGIN_MAILANALYZER_VERSION", "2.0.2");

/**
 * Summary of plugin_init_mailanalyzer
 * Init the hooks of the plugins
 */
function plugin_init_mailanalyzer() {

   global $PLUGIN_HOOKS;

   Plugin::registerClass('PluginMailAnalyzer', ['classname' => 'PluginMailAnalyzer']);

   $PLUGIN_HOOKS['csrf_compliant']['mailanalyzer'] = true;

   $PLUGIN_HOOKS['pre_item_add']['mailanalyzer'] = [
      'Ticket' => ['PluginMailAnalyzer', 'plugin_pre_item_add_mailanalyzer'],
   ];

   $PLUGIN_HOOKS['item_add']['mailanalyzer'] = [
      'Ticket' => ['PluginMailAnalyzer', 'plugin_item_add_mailanalyzer']
   ];
}


/**
 * Summary of plugin_version_mailanalyzer
 * Get the name and the version of the plugin
 * @return array
 */
function plugin_version_mailanalyzer() {
   return [
      'name'         => __('Mail Analyzer'),
      'version'      => PLUGIN_MAILANALYZER_VERSION,
      'author'       => 'Olivier Moron',
      'license'      => 'GPLv2+',
      'homepage'     => 'https://github.com/tomolimo/mailanalyzer',
      'requirements' => [
         'glpi' => [
            'min' => '9.5.3',
            'max' => '9.6'
            ]
         ]
   ];
}


/**
 * Summary of plugin_mailanalyzer_check_prerequisites
 * check prerequisites before install : may print errors or add to message after redirect
 * @return bool
 */
function plugin_mailanalyzer_check_prerequisites() {
   if (version_compare(GLPI_VERSION, '9.5.3', 'lt')
       && version_compare(GLPI_VERSION, '9.6', 'ge')) {
      echo "This plugin requires GLPI >= 9.5.3 and < 9.6";
      return false;
   } else {
      if (!class_exists('mailanalyzer_check_prerequisites')) {
         class mailanalyzer_check_prerequisites { public $attr = 'value'; function __toString() {
               return 'empty';}};
      }
      $loc = new mailanalyzer_check_prerequisites;
      $loc2 = Toolbox::addslashes_deep($loc);
      if (is_object($loc2) && $loc->attr === $loc2->attr) {
         return true;
      } else {
         echo "This plugin requires upgraded versions of mailcollector.class.php and toolbox.class.php";
         return false;
      }
   }
}


/**
 * Summary of plugin_mailanalyzer_check_config
 * @return bool
 */
function plugin_mailanalyzer_check_config() {
   return true;
}

