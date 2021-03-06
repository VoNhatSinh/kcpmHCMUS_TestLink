<?php

/**
 * TestLink Open Source Project - http://testlink.sourceforge.net/
 * This script is distributed under the GNU General Public License 2 or later.
 *
 * @filesource	tlRequirementFilterControl.class.php
 * @package    	TestLink
 * @author     	Andreas Simon
 * @copyright  	2006-2010, TestLink community
 * @link       	http://www.teamst.org/index.php
 *
 * This class extends tlFilterPanel for the specific use with requirement tree.
 * It holds logic to be used at GUI level to manage a common set of settings and filters for requirements.
 * 
 * @internal revisions
 * @since 2.0
 * 20110903 - franciscom - 	added mode private property to avoid event viewer warnings.
 *							may be issue was created while refactoring to allow TABBED BROWSING
 *
 * 20110426 - franciscom - init_args() interface changes
 * 20110311 - asimon - Show count for total requirements in tree on root node
 */

/**
 * This class extends tlFilterPanel for the specific use with requirement tree.
 * It holds logic to be used at GUI level to manage a common set of settings and filters for requirements.
 * 
 * @author Andreas Simon
 * 
 * @package TestLink
 **/
class tlRequirementFilterControl extends tlFilterControl {
	
	public $req_mgr = null;
	
	// 20110903 - franciscom - added to remove warning from event viewer
	private $mode = 'req_edit';

	/**
	 * This array contains all possible filters.
	 * It is used as a helper to iterate over all the filters in some loops.
	 * It also sets options how and from where to load the parameters with
	 * input fetching functions in init_args()-method.
	 * Its keys are the names of the settings (class constants are used),
	 * its values are the arrays for the input parser.
	 * @var array
	 */
	private $all_filters = array('filter_doc_id' => array("POST", tlInputParameter::STRING_N),
	                             'filter_title' => array("POST", tlInputParameter::STRING_N),
	                             'filter_status' => array("POST", tlInputParameter::ARRAY_STRING_N),
	                             'filter_type' => array("POST", tlInputParameter::ARRAY_INT),
	                             'filter_spec_type' => array("POST", tlInputParameter::ARRAY_INT),
	                             'filter_coverage' => array("POST", tlInputParameter::INT_N),
	                             'filter_relation' => array("POST", tlInputParameter::ARRAY_STRING_N),
	                             'filter_tc_id' => array("POST", tlInputParameter::STRING_N),
	                             'filter_custom_fields' => null);
	
	/**
	 * This array contains all possible settings. It is used as a helper
	 * to later iterate over all possibilities in loops.
	 * Its keys are the names of the settings, its values the arrays for the input parser.
	 * @var array
	 */
	private $all_settings = array('setting_refresh_tree_on_action' => 
	                              array("POST", tlInputParameter::CB_BOOL));
	
	public function __destruct() {
		parent::__destruct(); //destroys testproject manager
		
		// destroy member objects
		unset($this->req_mgr);
	}

	protected function read_config() {
		// some configuration reading already done in parent class
		parent::read_config();

		// load configuration for requirement filters
		$this->configuration = config_get('tree_filter_cfg')->requirements;

		// load req and req spec config (for types, filters, status, ...)
		$this->configuration->req_cfg = config_get('req_cfg');
		$this->configuration->req_spec_cfg = config_get('req_spec_cfg');

		// is choice of advanced filter mode enabled?
		$this->filter_mode_choice_enabled = $this->configuration->advanced_filter_mode_choice;
		
		return tl::OK;
	}
	
	protected function init_args(&$dbHandler) 
	{
		// some common user input is already read in parent class
		parent::init_args($dbHandler);

		// add settings and filters to parameter info array for request parsers
		$params = array();
		foreach ($this->all_settings as $name => $info) {
			if (is_array($info)) {
				$params[$name] = $info;
			}
		}
		foreach ($this->all_filters as $name => $info) {
			if (is_array($info)) {
				$params[$name] = $info;
			}
		}
		I_PARAMS($params, $this->args);
	} // end of method

	/**
	 * Initializes the class member array for settings 
	 * according to the data loaded from database and user input.
	 * Only initializes active settings, for a better performance.
	 * If no settings are active, the complete panel will be disabled and not be displayed.
	 */
	protected function init_settings() {
		// $at_least_one_active = false;

		foreach ($this->all_settings as $name => $info) {
			$init_method = "init_$name";
			if (method_exists($this, $init_method)) {
				// is valid, configured, exists and therefore can be used, so initialize this setting
				$this->$init_method();
				// $at_least_one_active = true;
				$this->display_req_settings = true;
			} else {
				// is not needed, simply deactivate it by setting it to false in main array
				$this->settings[$name] = false;
			}
		}

		// add the important settings to active filter array
		foreach ($this->all_settings as $name => $info) {
			if ($this->settings[$name]) {
				$this->active_filters[$name] = $this->settings[$name]['selected'];
			} else {
				$this->active_filters[$name] = null;
			}
		}

		// // if at least one active setting is left to display, switch settings panel on
		// if ($at_least_one_active) {
		// 	$this->display_req_settings = true;
		// }
	} // end of method

	/**
	 * Initializes the class member array for filters 
	 * according to the data loaded from database and user input.
	 * Only initializes filters which are still enabled and active, for a better performance.
	 * If no filters are active at all, the filters panel will be disabled and not displayed.
	 */
	protected function init_filters() {
		// BUGID 3853
		if ($this->configuration->show_filters == ENABLED) {
			// iterate through all filters and activate the needed ones
			foreach ($this->all_filters as $name => $info) {
				$init_method = "init_$name";
				if (method_exists($this, $init_method) && $this->configuration->{$name} == ENABLED) {
					// valid
					$this->$init_method();
					// $at_least_one_active = true;
					$this->display_req_filters = true;
				} else {
					// is not needed, deactivate filter by setting it to false in main array
					// and of course also in active filters array
					$this->filters[$name] = false;
					$this->active_filters[$name] = null;
				}
			}
		} else {
			$this->display_req_filters = false;
		}
	} // end of method

	/**
	 * Returns the filter array with necessary data,
	 * ready to be processed/used by underlying filter functions in
	 * requirement tree generator function.
	 */
	protected function get_active_filters() {
		return $this->active_filters;
	} // end of method
	
	/**
	 * Build the tree menu for generation of JavaScript tree of requirements.
	 * Depending on user selections in graphical user interface, 
	 * either a completely filtered tree will be built and returned,
	 * or only the minimal necessary data to "lazy load" the objects in tree by later Ajax calls.
	 * @param object $gui Reference to GUI object (information will be written to it)
	 * @return object $tree_menu Tree object for display of JavaScript tree menu.
	 */
	public function build_tree_menu(&$gui) {
		$tree_menu = null;
		$filters = $this->get_active_filters();
		$additional_info = null;
		$options = null;
		$loader = '';
		$children = "[]";
		
		// enable drag and drop
		$drag_and_drop = new stdClass();
        // BUGID 3718 - enable drag&drop per default, later disable if filtering is done
		$drag_and_drop->enabled = true;
		$drag_and_drop->BackEndUrl = $gui->basehref . 'lib/ajax/dragdroprequirementnodes.php';
		$drag_and_drop->useBeforeMoveNode = TRUE;
				
		if (!$this->testproject_mgr) {
			$this->testproject_mgr = new testproject($this->db);
		}
		
		// when we use filtering, the tree will be statically built,
		// otherwise it will be lazy loaded
		if ($this->do_filtering) {
			$options = array('for_printing' => NOT_FOR_PRINTING,'exclude_branches' => null);
		    
			$tree_menu = generate_reqspec_tree($this->db, $this->testproject_mgr,
			                                   $this->args->testproject_id,
			                                   $this->args->testproject_name,
			                                   $filters, $options);
			
			$root_node = $tree_menu->rootnode;
			$root_node->name .= " ({$root_node->total_req_count})";
			$children = $tree_menu->menustring ? $tree_menu->menustring : "[]";

            // BUGID 3718: disable drag&drop if tree has been filtered
            $drag_and_drop->enabled = false;
		} else {
			$loader = $gui->basehref . 'lib/ajax/getrequirementnodes.php?' .
			                           "root_node={$this->args->testproject_id}" .
			                           "&tproject_id={$this->args->testproject_id}";
			
			$req_qty = count($this->testproject_mgr->get_all_requirement_ids($this->args->testproject_id));
		
			$root_node = new stdClass();
			$root_node->href = "javascript:TPROJECT_REQ_SPEC_MGMT({$this->args->testproject_id})";
			$root_node->id = $this->args->testproject_id;
			$root_node->name = $this->args->testproject_name . " ($req_qty)";
			$root_node->testlink_node_type = 'testproject';
		}	
	
		$gui->ajaxTree = new stdClass();
		$gui->ajaxTree->loader = $loader;
		$gui->ajaxTree->root_node = $root_node;
		$gui->ajaxTree->children = $children;
		$gui->ajaxTree->dragDrop = $drag_and_drop;
		// BUGID 4613 - improved cookiePrefix for requirement specification tree
		$gui->ajaxTree->cookiePrefix = 'req_specification_tproject_id_' . $root_node->id . "_" ;
	} // end of method
	
	/**
	 * called magically by init_settings()	
	 *
	 * @internal revisions
	 * 
	 */
	private function init_setting_refresh_tree_on_action() 
	{
		$key = 'setting_refresh_tree_on_action';
		$hidden_key = "hidden_{$key}";
		$setting = 'reqTreeRefreshOnAction';

		$this->settings[$key] = array();
		$this->settings[$key][$hidden_key] = 0;

		$this->settings[$key] = array();
		$this->settings[$key][$hidden_key] = false;
	
		// look where we can find the setting - POST, SESSION, config?
		$selection = isset($this->args->{$key}) ? 1 : 0;
		if( $selection == 0 && !isset($this->args->{$hidden_key}))
		{
		
			// look on $_SESSION using $mode and test project ID
			// this is only way to cope with TABBED BROWSING
			// we consider that test project set the enviroment
			// then if we open N TABS with same test project 
			// setting in ONE TAB => ALL TABS will be affected.
			// IMHO this is a good compromise
			// 
			if(isset($_SESSION['env_for_tproject'][$this->args->testproject_id][$setting][$this->mode]))
			{
				$selection = $_SESSION['env_for_tproject'][$this->args->testproject_id][$setting][$this->mode];
			} 
			else
			{
				$selection = ($this->configuration->automatic_tree_refresh > 0) ? 1 : 0;
			}
		}

		$this->settings[$key]['selected'] = $selection;
		$this->settings[$key][$hidden_key] = $selection;
		$_SESSION['env_for_tproject'][$this->args->testproject_id][$setting][$this->mode] = $selection;
	} // end of method
	
	
	
	private function init_filter_doc_id() {
		$key = 'filter_doc_id';
		$selection = $this->args->{$key};
		
		if (!$selection || $this->args->reset_filters) {
			$selection = null;
		} else {
			$this->do_filtering = true;
		}
		
		$this->filters[$key] = array('selected' => $selection);
		$this->active_filters[$key] = $selection;
	} // end of method
	
	private function init_filter_title() {
		$key = 'filter_title';
		$selection = $this->args->{$key};
		
		if (!$selection || $this->args->reset_filters) {
			$selection = null;
		} else {
			$this->do_filtering = true;
		}
		
		$this->filters[$key] = array('selected' => $selection);
		$this->active_filters[$key] = $selection;
	} // end of method
	
	private function init_filter_status() {
		$key = 'filter_status';
		$selection = $this->args->{$key};
		
		// get configured statuses and add "any" string to menu
		$items = array(self::ANY => $this->option_strings['any']) + 
		         (array) init_labels($this->configuration->req_cfg->status_labels);

		// BUGID 3852
		if (!$selection || $this->args->reset_filters
		|| (is_array($selection) && in_array('0', $selection, true))) {
			$selection = null;
		} else {
			$this->do_filtering = true;
		}
		
		$this->filters[$key] = array('selected' => $selection, 'items' => $items);
		$this->active_filters[$key] = $selection;
	} // end of method

	private function init_filter_type() {
		$key = 'filter_type';
		$selection = $this->args->{$key};
		
		// get configured types and add "any" string to menu
		$items = array(self::ANY => $this->option_strings['any']) + 
		         (array) init_labels($this->configuration->req_cfg->type_labels);
	
		if (!$selection || $this->args->reset_filters
		|| (is_array($selection) && in_array(self::ANY, $selection))) {
			$selection = null;
		} else {
			$this->do_filtering = true;
		}
		
		$this->filters[$key] = array('selected' => $selection, 'items' => $items);
		$this->active_filters[$key] = $selection;
	} // end of method
	
	private function init_filter_spec_type() {
		$key = 'filter_spec_type';
		$selection = $this->args->{$key};
		
		// get configured types and add "any" string to menu
		$items = array(self::ANY => $this->option_strings['any']) + 
		         (array) init_labels($this->configuration->req_spec_cfg->type_labels);
		
		if (!$selection || $this->args->reset_filters
		|| (is_array($selection) && in_array(self::ANY, $selection))) {
			$selection = null;
		} else {
			$this->do_filtering = true;
		}
		
		$this->filters[$key] = array('selected' => $selection, 'items' => $items);
		$this->active_filters[$key] = $selection;
	} // end of method
	
	private function init_filter_coverage() {
		
		$key = 'filter_coverage';
		$this->filters[$key] = false;
		$this->active_filters[$key] = null;
		
		// is coverage management enabled?
		if ($this->configuration->req_cfg->expected_coverage_management) {
			$selection = $this->args->{$key};
		
			if (!$selection || !is_numeric($selection) || $this->args->reset_filters) {
				$selection = null;
			} else {
				$this->do_filtering = true;
			}
			
			$this->filters[$key] = array('selected' => $selection);
			$this->active_filters[$key] = $selection;
		}
	} // end of method
	
	private function init_filter_relation() {
		
		$key = 'filter_relation';
	
		// are relations enabled?
		if ($this->configuration->req_cfg->relations->enable) {
			$selection = $this->args->{$key};
			
			if (!$this->req_mgr) {
				$this->req_mgr = new requirement_mgr($this->db);
			}
			
			$req_relations = $this->req_mgr->init_relation_type_select();
			
			// special case here:
			// for equal type relations (where it doesn't matter if we find source or destination)
			// we have to remove the source identficator from the array key
			foreach ($req_relations['equal_relations'] as $array_key => $old_key) {
				// set new key in array and delete old one
				$new_key = (int) str_replace("_source", "", $old_key);
				$req_relations['items'][$new_key] = $req_relations['items'][$old_key];
				unset($req_relations['items'][$old_key]);
			}
			
			$items = array(self::ANY => $this->option_strings['any']) + 
			         (array) $req_relations['items'];

			if (!$selection || $this->args->reset_filters
			|| (is_array($selection) && in_array(self::ANY, $selection))) {
				$selection = null;
			} else {
				$this->do_filtering = true;
			}
			
			$this->filters[$key] = array('selected' => $selection, 
			                             'items' => $items);
			$this->active_filters[$key] = $selection;
		} else {
			// not enabled, just nullify
			$this->filters[$key] = false;
			$this->active_filters[$key] = null;
		}		
	} // end of method
	
	private function init_filter_tc_id() {
		$key = 'filter_tc_id';
		$selection = $this->args->{$key};
		
		if (!$this->testproject_mgr) {
			$this->testproject_mgr = new testproject($this->db);
		}
		
		$tc_cfg = config_get('testcase_cfg');
		$tc_prefix = $this->testproject_mgr->getTestCasePrefix($this->args->testproject_id);
		$tc_prefix .= $tc_cfg->glue_character;
		
		if (!$selection || $selection == $tc_prefix || $this->args->reset_filters) {
			$selection = null;
		} else {
			$this->do_filtering = true;
		}
		
		$this->filters[$key] = array('selected' => $selection ? $selection : $tc_prefix);
		$this->active_filters[$key] = $selection;
	} // end of method
	
	private function init_filter_custom_fields() {
		$key = 'filter_custom_fields';
		$no_warning = true;
		
		// BUGID 3930
		global $g_locales_date_format;
		$locale = (isset($_SESSION['locale'])) ? $_SESSION['locale'] : 'en_GB';
		$date_format = str_replace('%', '', $g_locales_date_format[$locale]);
		
		// BUGID 3566: show/hide CF
		$collapsed = isset($_SESSION['cf_filter_collapsed']) ? $_SESSION['cf_filter_collapsed'] : 0;
		$collapsed = isset($_REQUEST['btn_toggle_cf']) ? !$collapsed : $collapsed;
		$_SESSION['cf_filter_collapsed'] = $collapsed;	
		$btn_label = $collapsed ? lang_get('btn_show_cf') : lang_get('btn_hide_cf');
		
		if (!$this->req_mgr) {
			$this->req_mgr = new requirement_mgr($this->db);
		}
		
		// BUGID 2877 -  Custom Fields linked to Req version
		// $cfields = $this->req_mgr->get_linked_cfields(null, $this->args->testproject_id);
		$cfields = $this->req_mgr->get_linked_cfields(null, null, $this->args->testproject_id);
		$cf_prefix = $this->req_mgr->cfield_mgr->name_prefix;
		$cf_html_code = "";
		$selection = array();
		
		$this->filters[$key] = false;
		$this->active_filters[$key] = null;

		if (!is_null($cfields)) {
			// display and compute only when custom fields are in use
			foreach ($cfields as $cf_id => $cf) {
				// has a value been selected?
				$id = $cf['id'];
				$type = $cf['type'];
				$verbose_type = trim($this->req_mgr->cfield_mgr->custom_field_types[$type]);
				$cf_input_name = "{$cf_prefix}{$type}_{$id}";

				// BUGID 3716
				// custom fields did not retain value after apply
				$value = isset($_REQUEST[$cf_input_name]) ? $_REQUEST[$cf_input_name] : null;

				// BUGID 3884: added filtering for datetime custom fields
				if ($verbose_type == 'datetime') {
					// if cf is a date field, convert the three given values to unixtime format
					if (isset($_REQUEST[$cf_input_name . '_input']) && $_REQUEST[$cf_input_name . '_input'] != ''
					&& isset($_REQUEST[$cf_input_name . '_hour']) && $_REQUEST[$cf_input_name . '_hour'] != ''
					&& isset($_REQUEST[$cf_input_name . '_minute']) && $_REQUEST[$cf_input_name . '_minute'] != ''
					&& isset($_REQUEST[$cf_input_name . '_second']) && $_REQUEST[$cf_input_name . '_second'] != '') {
						$date = $_REQUEST[$cf_input_name . '_input'];
						
						$hour = $_REQUEST[$cf_input_name . '_hour'];
						$minute = $_REQUEST[$cf_input_name . '_minute'];
						$second = $_REQUEST[$cf_input_name . '_second'];
						
						$date_array = split_localized_date($date, $date_format);
						$value = mktime($hour, $minute, $second, $date_array['month'], $date_array['day'], $date_array['year']);
					}
				}

				if ($verbose_type == 'date') {
					// if cf is a date field, convert the three given values to unixtime format
					// BUGID 3883: only set values if different from 0
					if (isset($_REQUEST[$cf_input_name . '_input']) && $_REQUEST[$cf_input_name . '_input'] != '') {
						$date = $_REQUEST[$cf_input_name . '_input'];						
						$date_array = split_localized_date($date, $date_format);
						$value = mktime(0, 0, 0, $date_array['month'], $date_array['day'], $date_array['year']);
					}
				}

				if ($this->args->reset_filters) {
					$value = null;
				}

				$value2display = $value;
				if (!is_null($value2display) && is_array($value2display)){
					$value2display = implode("|", $value2display);
				}
				$cf['value'] = $value2display;

				if ($value) {
					$this->do_filtering = true;
					$selection[$id] = $value;
				}

				$label = str_replace(TL_LOCALIZE_TAG, '', lang_get($cf['label'],
				                                                   null, $no_warning));

				$cf_size = self::CF_INPUT_SIZE;
				// set special size for list inputs
				if ($verbose_type == 'list' || $verbose_type == 'multiselection list') {
					$cf_size = 3;
				}
				
				// don't show textarea inputs here, they are too large for filterpanel
				if ($verbose_type != 'text area') {
					$cf_html_code .= '<tr class="cfRow"><td>' . htmlspecialchars($label) . '</td><td>' .
					                 $this->req_mgr->cfield_mgr->string_custom_field_input($cf, '', $cf_size, true) .
					                 '</td></tr>';
				}
			}

			// BUGID 3566: show/hide CF
			$this->filters[$key] = array('items' => $cf_html_code, 
			                             'btn_label' => $btn_label, 
			                             'collapsed' => $collapsed);
			$this->active_filters[$key] = count($selection) ? $selection : null;
		}
	}
} // end of class
?>