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

define('REPORT_DRILLDOWNSQL_MAX_RECORDS', 5000);

class report_sqldrilldown extends report_base{

	function init(){
		$this->components = array('drilldownsql', 'filters', 'template', 'permissions', 'calcs', 'plot');
	}

    function get_allowed_cats() {
        global $CFG, $USER;

        if (has_capability('moodle/category:manage', get_context_instance(CONTEXT_SYSTEM))) {
            return ' != 0 '; // All categories allowed
        }

        // Find all roles with 'moodle/category:manage' capability
        $suitableroles = get_roles_with_capability('moodle/category:manage', CAP_ALLOW);
        if (!$suitableroles) {
            error("No roles with 'moodle/category:manage' capability");
        }
        $suitableroles = implode(',', array_keys($suitableroles));

        // Find all category contexts where the current user has been assigned such a role
        $sql =  'SELECT cx.instanceid ';
        $sql .= "  FROM {$CFG->prefix}context cx
                   JOIN {$CFG->prefix}role_assignments ra
                     ON ra.contextid = cx.id ";
        $sql .= " WHERE ra.roleid IN ($suitableroles) AND cx.contextlevel = ".CONTEXT_COURSECAT." AND ra.userid = {$USER->id} ";
        $assignedcats = get_records_sql($sql);

        if ($assignedcats) {
        // Find available categories (the child categories of those found above)
            $assignedcats = array_keys($assignedcats);

            $sql = 'SELECT DISTINCT(ca.id) ';
            $sql .= " FROM {$CFG->prefix}course_categories ca,
                           (SELECT path
                              FROM {$CFG->prefix}course_categories
                             WHERE id IN (".implode(',', $assignedcats).")) tca
                     WHERE ca.path LIKE ".sql_concat('tca.path', "'/%'");
            $subcats = get_records_sql($sql);
            if ($subcats) {
                $assignedcats = array_merge($assignedcats, array_keys($subcats));
            }

            return ' IN('.implode(',', $assignedcats).') ';
        }

        return ' = 0 '; // No categories allowed
    }

	function prepare_sql($sql) {
		global $USER;

		$sql = str_replace('%%USERID%%', $USER->id, $sql);
		// See http://en.wikipedia.org/wiki/Year_2038_problem
		$sql = str_replace(array('%%STARTTIME%%','%%ENDTIME%%'),array('0','2145938400'),$sql);
        $sql = str_replace('%%DRILLDOWN_PARENT_CAT%%', optional_param('category', 0, PARAM_INT), $sql);
        $sql = str_replace('%%DRILLDOWN_ALLOWED_CATS%%', $this->get_allowed_cats(), $sql);
		$sql = preg_replace('/%{2}[^%]+%{2}/i','',$sql);

        $sql = explode('###', $sql);

		return $sql;
	}

	function execute_query($sql, $limitnum = REPORT_DRILLDOWNSQL_MAX_RECORDS) {
		global $CFG;

		$sql = preg_replace('/\bprefix_(?=\w+)/i', $CFG->prefix, $sql);

		return get_recordset_sql($sql, 0, $limitnum);
	}

	function create_report(){
		global $CFG;

		$components = cr_unserialize($this->config->components);

		$filters = (isset($components['filters']['elements']))? $components['filters']['elements'] : array();
		$calcs = (isset($components['calcs']['elements']))? $components['calcs']['elements'] : array();

		$finalcalcs = array();
        $tabledata = array();

		$components = cr_unserialize($this->config->components);
		$config = (isset($components['customsql']['config']))? $components['customsql']['config'] : new stdclass;

        $size = array();
        $align = array();
		if(isset($config->querysql)){
			// FILTERS
			$sql = $config->querysql;
			if(!empty($filters)){
				foreach($filters as $f){
					require_once($CFG->dirroot.'/blocks/configurable_reports/components/filters/'.$f['pluginname'].'/plugin.class.php');
					$classname = 'plugin_'.$f['pluginname'];
					$class = new $classname($this->config);
					$sql = $class->execute($sql, $f['formdata']);
				}
			}

            $postfiltervars = $this->get_filter_params();
            $drilldownstr = get_string('drilldown', 'block_configurable_reports');
            $drilldownimg = $CFG->pixpath.'/t/preview.gif';
            $drilldownimg = "<img src=\"$drilldownimg\" alt=\"$drilldownstr\" title=\"$drilldownstr\" />";

            $sqls = $this->prepare_sql($sql);
            $reportcount = 0;
            foreach ($sqls as $sql) {
                $td = new stdClass;
                $td->id = 'reporttable'.$reportcount++;
                $td->class = 'generaltable reporttable';
                $td->head = array();
                $td->data = array();
                $td->size = array();
                $td->align = array();

                if($rs = $this->execute_query($sql)) {
                    while ($row = rs_fetch_next_record($rs)) {
                        if(empty($td->data)){
                            foreach($row as $colname=>$value){
                                if ($colname == 'drilldownid') {
                                    $td->head[] = '';
                                    $td->size[] = '5%';
                                    $td->align[] = 'center';
                                } else {
                                    $td->head[] = str_replace('_', ' ', $colname);
                                    $td->size[] = 0;
                                    $td->align[] = 0;
                                }
                            }
                        }
                        if (isset($row->drilldownid)) {
                            $link = $CFG->wwwroot.'/blocks/configurable_reports/viewreport.php?id='.$this->config->id.'&amp;category='.$row->drilldownid;
                            $link .= $postfiltervars;
                            $row->drilldownid = "<a href=\"$link\" >$drilldownimg</a>";
                        }
                        $td->data[] = array_values((array) $row);
                    }
                }
                $tabledata[] = $td;
            }
		}

		// Calcs

        $firsttable = reset($tabledata);
        $finalcalcs = $this->get_calcs($firsttable->data, $firsttable->head);

		$calcs = new stdclass;
		$calcs->data = array($finalcalcs);
		$calcs->head = $firsttable->head;

		$this->finalreport->table = $firsttable;
        $this->finalreport->alltables = $tabledata;
		$this->finalreport->calcs = $calcs;

		return true;
	}

    function get_filter_params() {
        global $_POST, $_GET;

        $postfiltervars = '';
        $request = array_merge($_POST, $_GET);
        if($request) {
            foreach ($request as $key=>$val) {
                if (strpos($key,'filter_') !== false) {
                    if (is_array($val)) {
                        foreach ($val as $k=>$v) {
                            $postfiltervars .= "&amp;{$key}[$k]=".$v;
                        }
                    } else {
                        $postfiltervars .= "&amp;$key=".$val;
                    }
                }
            }
        }

        return $postfiltervars;
    }

    function add_jsordering() {
        cr_add_jsordering('.reporttable');
    }

    function print_filters() {
		global $CFG, $FULLME;

		$components = cr_unserialize($this->config->components);
		$filters = (isset($components['filters']['elements']))? $components['filters']['elements']: array();

		if(!empty($filters)){

			$formdata = new stdclass;
			$request = array_merge($_POST, $_GET);
			if($request) {
				foreach($request as $key=>$val) {
					if(strpos($key,'filter_') !== false) {
						$formdata->{$key} = $val;
                    }
                }
            }

			require_once('filter_form.php');
            $url = $CFG->wwwroot.'/blocks/configurable_reports/viewreport.php?id='.$this->config->id;
            if ($categoryid = optional_param('category', 0, PARAM_INT)) {
                $url .= '&category='.$categoryid;
            }
			$filterform = new report_edit_form($url, $this);

			$filterform->set_data($formdata);

			if($filterform->is_cancelled()){
				redirect($url);
				die;
			}
			$filterform->display();
		}
    }

    // Add breadcrumb trail and allow for multiple tables
    function print_report_page() {
        global $CFG;

        $postfiltervars = $this->get_filter_params();

        $output = '<div class="configurable_reports_backlink">';
        $categoryid = optional_param('category', 0, PARAM_INT);
        if ($categoryid && $category = get_record('course_categories', 'id', $categoryid)) {
            $baselink = $CFG->wwwroot.'/blocks/configurable_reports/viewreport.php?id='.$this->config->id.$postfiltervars;
            $path = explode('/',$category->path);
            $path = array_slice($path, 1); // Ignore empty first item

            $output .= '<a href="'.$baselink.'">'.get_string('drilldown_allregions', 'block_configurable_reports').'</a>';

            if (count($path)) {
                $cats = get_records_list('course_categories', 'id', implode(',',$path));
                $separator = get_separator();
                foreach ($path as $catid) {
                    if ($catid != $category->id) {
                        $link = $baselink.'&amp;category='.$catid;
                        $output .= $separator.'<a href="'.$link.'">'.s($cats[$catid]->name).'</a>';
                    } else {
                        $output .= $separator.s($cats[$catid]->name);
                    }
                }
            }
        } else {
            $output .= get_string('drilldown_allregions', 'block_configurable_reports');
        }
        $output .= '</div><br/>';

        echo $output;

		cr_print_js_function();
		$components = cr_unserialize($this->config->components);

		$template = (isset($components['template']['config']) && $components['template']['config']->enabled && $components['template']['config']->record)? $components['template']['config']: false;

		if($template){
			$this->print_template($template);
			return true;
		}

		echo '<div class="centerpara">';
		echo format_text($this->config->summary);
		echo '</div>';

		$this->print_filters();
        $hasdata = false;
        if ($this->finalreport->alltables) {
            foreach ($this->finalreport->alltables as $table) {
                if (!empty($table->data[0])) {
                    $hasdata = true;
                    break;
                }
            }
        }

		if ($hasdata) {

			echo "<div id=\"printablediv\">\n";
			$this->print_graphs();

			if ($this->config->jsordering) {
				$this->add_jsordering();
			}

			if ($this->config->pagination) {
				$page = optional_param('page',0,PARAM_INT);
				$this->totalrecords = count($this->finalreport->table->data);
				$this->finalreport->table->data = array_slice($this->finalreport->table->data,$page * $this->config->pagination, $this->config->pagination);
			}

            foreach ($this->finalreport->alltables as $table) {
                if (!empty($table->data[0])) {
                    cr_print_table($table);
                }
            }

			if($this->config->pagination){
				print_paging_bar($this->totalrecords,$page,$this->config->pagination,"viewreport.php?id=".$this->config->id."$postfiltervars&amp;");
			}

			if(!empty($this->finalreport->calcs->data[0])){
				echo '<br /><br /><br /><div class="centerpara"><b>'.get_string("columncalculations","block_configurable_reports").'</b></div><br />';
				print_table($this->finalreport->calcs);
			}
			echo "</div>";

			$this->print_export_options();
		}
		else{
			echo '<div class="centerpara">'.get_string('norecordsfound','block_configurable_reports').'</div>';
		}

		echo '<div class="centerpara"><br />';
		echo "<img src=\"{$CFG->wwwroot}/blocks/configurable_reports/img/print.png\" alt=\".get_string('printreport','block_configurable_reports')\">&nbsp;<a href=\"javascript: printDiv('printablediv')\">".get_string('printreport','block_configurable_reports')."</a>";
		echo "</div>\n";
	}

}

?>