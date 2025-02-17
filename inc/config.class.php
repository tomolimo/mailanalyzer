<?php
/*
-------------------------------------------------------------------------
MailAnalyzer plugin for GLPI
Copyright (C) 2011-2025 by Raynet SAS a company of A.Raymond Network.

https://www.araymond.com/
-------------------------------------------------------------------------

LICENSE

This file is part of MailAnalyzer plugin for GLPI.

This file is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This plugin is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this plugin. If not, see <http://www.gnu.org/licenses/>.
--------------------------------------------------------------------------
 */

class PluginMailanalyzerConfig extends CommonDBTM {

   /**
    * Summary of getTypeName
    * @param mixed $nb plural
    * @return mixed
    */
   static function getTypeName($nb = 0) {
      return __('Mail Analyzer setup', 'mailanalyzer');
   }

   /**
    * Summary of getName
    * @param mixed $with_comment with comment
    * @return mixed
    */
   function getName($with_comment = 0) {
      return __('MailAnalyzer', 'mailanalyzer');
   }


   /**
    * Summary of showConfigForm
    * @param mixed $item is the config
    * @return boolean
    */
   static function showConfigForm($item) {
      $config = Config::getConfigurationValues('plugin:mailanalyzer');

      echo "<form name='form' action=\"".Toolbox::getItemTypeFormURL('Config')."\" method='post' data-track-changes='true'>";
            echo "<div class='center' id='tabsbody'>";
      echo "<table class='tab_cadre_fixe'>";

      echo "<tr><th colspan='4'>" . __('Mail Analyzer setup', 'mailanalyzer') . "</th></tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td >".__('Use of Thread index', 'mailanalyzer') ."</td><td >";
      if (!isset($config['use_threadindex'])) {
          $config['use_threadindex'] = 0;
      }
      Dropdown::showYesNo("use_threadindex", $config['use_threadindex']);
      echo "</td></tr>";
      
      echo "<tr><td colspan='2'></td></tr>";

      echo "<tr class='tab_bg_2'>";
      echo "<td colspan='4' class='center'>";
      echo "<input type='submit' name='update' class='submit' value=\""._sx('button', 'Save')."\">";
      echo "</td></tr>";
      echo "</table></div>";

      echo "<input type='hidden' name='id' value='1'>";
      echo "<input type='hidden' name='config_context' value='plugin:mailanalyzer'>";

      Html::closeForm();

      return false;
   }


   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      if ($item->getType()=='Config') {
         return __('Mail Analyzer', 'mailanalyzer');
      }
      return '';
   }


   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {

      if ($item->getType()=='Config') {
         self::showConfigForm($item);
      }
      return true;
   }

}
