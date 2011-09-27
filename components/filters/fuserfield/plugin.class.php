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

class plugin_fuserfield extends plugin_base{
	
	function init(){
		$this->form = true;
		$this->unique = true;
		$this->fullname = get_string('fuserfield','block_configurable_reports');
		$this->reporttypes = array('users');
	}
	
	function summary($data){
		return $data->field;
	}
	
	function execute($finalelements,$data){
		
		$filter_fuserfield = optional_param('filter_fuserfield_'.$data->field,0,PARAM_RAW);		
		if($filter_fuserfield){
			// addslashes is done in clean param
			$filter = clean_param(base64_decode($filter_fuserfield),PARAM_CLEAN);
			
			if(strpos($data->field,'profile_') === 0){				
				if($fieldid = get_field('user_info_field','id','shortname',str_replace('profile_','',$data->field))){
				
					$sql = "fieldid = $fieldid AND data = '$filter' AND userid IN(".(implode(',',$finalelements)).")";
					
					if($infodata = get_records_select('user_info_data',$sql)){
						$finalusersid = array();
						foreach($infodata as $d){
							$finalusersid[] = $d->userid;
						}
						return $finalusersid;
					}
				}
			}			
			else{
				$sql = "$data->field = '$filter' AND id IN(".(implode(',',$finalelements)).")";
				if($elements = get_records_select('user',$sql)){				
					$finalelements = array_keys($elements);				
				}
			}
		}
		return $finalelements;
	}
	
	function print_filter(&$mform, $data){
		global $CFG, $db;
		
		$columns = $db->MetaColumns($CFG->prefix . 'user');
		$filteroptions = array();
		$filteroptions[''] = get_string('choose');
		
		$usercolumns = array();
		foreach($columns as $c)
			$usercolumns[$c->name] = $c->name;
			
		if($profile = get_records('user_info_field'))
			foreach($profile as $p)
				$usercolumns['profile_'.$p->shortname] = $p->name;		
			
		if(!isset($usercolumns[$data->field]))
			print_error('nosuchcolumn');
			
		$reportclassname = 'report_'.$this->report->type;	
		$reportclass = new $reportclassname($this->report);
				
		$components = cr_unserialize($this->report->components);		
		$conditions = $components['conditions'];
		$userlist = $reportclass->elements_by_conditions($conditions);
						
		if(!empty($userlist)){
			if(strpos($data->field,'profile_') === 0){	
				if($field = get_record('user_info_field','shortname',str_replace('profile_','',$data->field))){
					$selectname = $field->name;
					
					$sql = "SELECT DISTINCT(data) as data FROM {$CFG->prefix}user_info_data WHERE fieldid = {$field->id} AND userid IN(".(implode(',',$userlist)).")";
					
					if($infodata = get_records_sql($sql)){
						$finalusersid = array();
						foreach($infodata as $d){
							$filteroptions[base64_encode($d->data)] = $d->data;
						}						
					}
				}
			}
			else{
				$selectname = get_string($data->field);
				$sql = "SELECT DISTINCT(".$data->field.") as ufield FROM ".$CFG->prefix."user WHERE id IN (".implode(',',$userlist).") ORDER BY ufield ASC";
				if($rs = get_recordset_sql($sql)){
					while($u = rs_fetch_next_record($rs)){					
						$filteroptions[base64_encode($u->ufield)] = $u->ufield;
					}
				}
			}
		}
		
		$mform->addElement('select', 'filter_fuserfield_'.$data->field, $selectname, $filteroptions);
		$mform->setType('filter_courses', PARAM_INT);
		
	}	
}

?>