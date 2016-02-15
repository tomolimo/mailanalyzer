<?php


// Init the hooks of the plugins -Needed
function plugin_init_mailanalyzer() {

   global $PLUGIN_HOOKS;

   Plugin::registerClass('PluginMailAnalyzer', array('classname' => 'PluginMailAnalyzer'));
   
   $PLUGIN_HOOKS['csrf_compliant']['mailanalyzer']    = true;
  
    
   $PLUGIN_HOOKS['pre_item_add']['mailanalyzer'] = array(
      	'Ticket' => array('PluginMailAnalyzer', 'plugin_pre_item_add_mailanalyzer'),
      	'TicketFollowup' => array('PluginMailAnalyzer', 'plugin_pre_item_add_mailanalyzer_followup') 
      );
   
   $PLUGIN_HOOKS['item_add']['mailanalyzer'] = array(
      	'Ticket' => array('PluginMailAnalyzer', 'plugin_item_add_mailanalyzer') 
      );
   
}

// Get the name and the version of the plugin - Needed
function plugin_version_mailanalyzer(){
   global $LANG;

   return array ('name'           => 'Mail Analyzer',
                'version'        => '1.2.2',
                'author'         => 'Olivier Moron',
                'homepage'       => '',
                'minGlpiVersion' => '0.83.8');
}

// Optional : check prerequisites before install : may print errors or add to message after redirect
function plugin_mailanalyzer_check_prerequisites(){
   if (version_compare(GLPI_VERSION,'0.83.8','lt') || version_compare(GLPI_VERSION,'0.91','ge')) {
      echo "This plugin requires GLPI >= 0.83.8 and < 0.91";
      return false;
   } else {
      return true;
   }
}

function plugin_mailanalyzer_check_config(){
   return true;
}

?>