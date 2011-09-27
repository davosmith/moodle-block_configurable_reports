<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/** Configurable Reports
  * A Moodle block for creating customizable reports
  * @package blocks
  * @author: Juan leyva <http://www.twitter.com/jleyvadelgado>
  * @date: 2009
  */

require_once($CFG->dirroot.'/blocks/configurable_reports/plugin.class.php');

class plugin_roles extends plugin_base{

	function init(){
		$this->form = false;
		$this->unique = true;
		$this->fullname = get_string('filterroles','block_configurable_reports');
		$this->reporttypes = array('sql', 'sqldrilldown');
	}

	function summary($data){
		return get_string('filterroles_summary', 'block_configurable_reports');
	}

	function execute($finalelements, $data){

		$filter_roles = optional_param('filter_roles', 0, PARAM_INT);
		if(!$filter_roles)
			return $finalelements;

		if ($this->report->type != 'sql' && $this->report->type != 'sqldrilldown') {
				return array($filter_roles);
		} else {
			if (preg_match("/%%FILTER_ROLES:([^%]+)%%/i",$finalelements, $output)) {
				$replace = ' AND '.$output[1].' = '.$filter_roles;
				return str_replace('%%FILTER_ROLES:'.$output[1].'%%', $replace, $finalelements);
			}
		}
		return $finalelements;
	}

	function print_filter(&$mform) {
		global $CFG;

		$filter_roles = optional_param('filter_roles', 0, PARAM_INT);

		$reportclassname = 'report_'.$this->report->type;
		$reportclass = new $reportclassname($this->report);

		if ($this->report->type != 'sql' && $this->report->type != 'sqldrilldown') {
			$components = cr_unserialize($this->report->components);
			$conditions = $components['conditions'];

			$rolelist = $reportclass->elements_by_conditions($conditions);
		} else{
			$rolelist = array_keys(get_records('role'));
		}

		$roleoptions = array();
		$roleoptions[0] = get_string('choose');

		if(!empty($rolelist)){
			$roles = get_records_select('role','id in ('.(implode(',',$rolelist)).')');

			foreach($roles as $r){
				$roleoptions[$r->id] = format_string($r->name);
			}
		}

		$mform->addElement('select', 'filter_roles', get_string('role'), $roleoptions);
		$mform->setType('filter_roles', PARAM_INT);

	}

}

?>