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

/**
 * File containing the category class.
 *
 * @package    tool_uploadcoursecategory
 * @copyright  2015 Alexandru Elisei
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/admin/tool/uploadcoursecategory/locallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->libdir . '/coursecatlib.php');

/**
 * Category class.
 *
 * @package    tool_uploadcoursecategory
 * @copyright  2015 Alexandru Elisei
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_uploadcoursecategory_category {

    /** Outcome of the process: creating the course category */
    const DO_CREATE = 1;

    /** Outcome of the process: updating the course category */
    const DO_UPDATE = 2;

    /** Outcome of the process: deleting the course category */
    const DO_DELETE = 3;

    /** @var array final import data. */
    protected $finaldata = array();

    /** @var array course category import data. */
    protected $rawdata = array();

    /** @var array errors. */
    protected $errors = array();

    /** @var array default values. */
    protected $defaults = array();

    /** @var int the ID of the course category that had been processed. */
    protected $id;

    /** @var int the ID of the course category parent, default 0 ("Top"). */
    protected $parentid = 0;

    /** @var array containing options passed from the processor. */
    protected $importoptions = array();

    /** @var int import mode. Matches tool_uploadcoursecategory_processor::MODE_* */
    protected $mode;

    /** @var int update mode. Matches tool_uploadcategory_processor::UPDATE_* */
    protected $updatemode;

    /** @var array course category import options. */
    protected $options = array();
   
    /** @var array operations executed. */
    protected $status = array();

    /** @var int constant value of self::DO_*, what to do with that course category */
    protected $do;

    /** @var array database record of an existing category */
    protected $existing = null;

    /** @var bool set to true once we have prepared the course category */
    protected $prepared = false;

    /** @var bool set to true once we have started the process of the course category */
    protected $processstarted = false;

    /** @var string category name. */
    protected $name;

    /** @var array fields allowed as course category data. */
    static protected $validfields = array('name', 'description', 'idnumber',
        'visible', 'deleted', 'theme', 'oldname');

    /** @var array fields required on course category creation. */
    static protected $mandatoryfields = array('name');

    /** @var array fields which are considered as options. */
    static protected $optionfields = array('deleted' => false, 'visible' => true,
        'oldname' => null);

    /**
     * Constructor
     *
     * @param int $mode import mode, constant matching tool_uploadcoursecategory_processor::MODE_*
     * @param int $updatemode update mode, constant matching tool_uploadcoursecategory_processor::UPDATE_*
     * @param array $rawdata raw course category data.
     * @param array $importoptions import options.
     */
    public function __construct($mode, $updatemode, $rawdata, $importoptions = array()) {
        if ($mode !== tool_uploadcoursecategory_processor::MODE_CREATE_NEW &&
                $mode !== tool_uploadcoursecategory_processor::MODE_CREATE_ALL &&
                $mode !== tool_uploadcoursecategory_processor::MODE_CREATE_OR_UPDATE &&
                $mode !== tool_uploadcoursecategory_processor::MODE_UPDATE_ONLY) {
            throw new coding_exception('Incorrect mode.');
        } else if ($updatemode !== tool_uploadcoursecategory_processor::UPDATE_NOTHING &&
                $updatemode !== tool_uploadcoursecategory_processor::UPDATE_ALL_WITH_DATA_ONLY &&
                $updatemode !== tool_uploadcoursecategory_processor::UPDATE_ALL_WITH_DATA_OR_DEFAULTS &&
                $updatemode !== tool_uploadcoursecategory_processor::UPDATE_MISSING_WITH_DATA_OR_DEFAULTS) {
            throw new coding_exception('Incorrect update mode.');
        }

        $this->mode = $mode;
        $this->updatemode = $updatemode;

        if (isset($rawdata['name'])) {
            $categories = explode('/', $rawdata['name']);
            $this->name = array_pop($categories);
            // Stripping whitespaces.
            $this->name = trim($this->name);
        }
        $this->rawdata = $rawdata;

        // Extract course category options.
        foreach (self::$optionfields as $option => $default) {
            $this->options[$option] = $rawdata[$option] ? $rawdata[$option] : null;
        }

        // Copy import options.
        $this->importoptions = $importoptions;
    }

    /**
     * Log an error
     *
     * @param string $code error code.
     * @param lang_string $message error message.
     * @return void
     */
    protected function error($code, lang_string $message) {
        $this->errors[$code] = $message;
    }

    /**
     * Return final course category data.
     *
     * @return void
     */
    public function get_finaldata() {
        return $this->finaldata;
    }

    /**
     * Return the ID of the processed course category.
     *
     * @return int|null
     */
    public function get_id() {
        if (!$this->processstarted) {
            throw new coding_exception('The course has not been processed yet!');
        }
        return $this->id;
    }

    /**
     * Return the errors found during preparation.
     *
     * @return array
     */
    public function get_errors() {
        return $this->errors;
    }

    /**
     * Return the errors found during preparation.
     *
     * @return array
     */
    public function get_statuses() {
        return $this->statuses;
    }

    /**
     * Log a status
     *
     * @param string $code status code.
     * @param lang_string $message status message.
     * @return void
     */
    protected function set_status($code, lang_string $message) {
        if (array_key_exists($code, $this->statuses)) {
            throw new coding_exception('Status code already defined');
        }
        $this->statuses[$code] = $message;
    }

    /**
     * Return the course category database entry, or null.
     *
     * @param string $name the name to use to check if the category exists.
     * @param int $parentid the id of the parent.
     * @return bool
     */
    protected function exists($name = null, $parentid = null) {
        global $DB;

        if (is_null($name)) {
            $name = $this->name;
        }
        if (is_null($parentid)) {
            $parentid = $this->parentid;
        }

        /*
        print "\nEXISTS()::name:\n";
        var_dump($name);
        print "parentid:\n";
        var_dump($parentid);
         */

        return $DB->get_record('course_categories', array('name' => $name, 'parent' => $parentid));
    }

    /**
     * Extracts the parentid and validates the category hierarchy.
     *
     * @param string $categories the name hierarchy of the category.
     * @param int $parentid the id of the parent.
     * @param bool $createmissing create missing categories in the hierarchy.
     * @return int id of the parent, -1 if one of the parent doesn't exist and createmissing is set to false.
     */
    protected function prepare_parent($categories = null, $parentid = null) {
        global $DB;

        if (is_null($categories)) {
            $categories = explode('/', $this->rawdata['name']);
            // Removing from hierarchy the category we wish to create/modify
            array_pop($categories);
        }
        if (is_null($parentid)) {
            $parentid = $this->parentid;
        }

        // Removing "Top" parent category
        if (count($categories) > 0) {
            if ($categories[0] == get_string('top')) {
                array_shift($categories);
            }
        }

        // Walking the hierarchy to check if parents exist
        $depth = 1;
        if (count($categories) > 0) {
            foreach ($categories as $cat) {
                $cat = trim($cat);
                $category = $DB->get_record('course_categories', 
                        array('name' => $cat, 'parent' => $parentid));
                if (empty($category)) {
                    if (!$this->importoptions['createmissing']) {
                        return -1;
                    } else {
                        $newname = array_slice($categories, 0, $depth);
                        $newname = implode('/', $newname);
                        $newdata = array('name' => $newname);
                        $newcat = new tool_uploadcoursecategory_category(
                            tool_uploadcoursecategory_processor::MODE_CREATE_NEW,
                            tool_uploadcoursecategory_processor::UPDATE_NOTHING,
                            $newdata, array('createmissing' => true)
                        );
                        if ($newcat->prepare()) {
                            $newcat->proceed();
                            $errors = $newcat->get_errors();
                            if (empty($errors)) {
                                $parentid = $newcat->id;
                            } else {
                                return -1;
                            }
                        } else {
                            return -1;
                        }
                    }
                } else {
                    $parentid = $category->id;
                }
                $depth++;
            }
        }

        return $parentid;
    }

    /**
     * Delete the current category.
     *
     * @return bool
     */
    protected function delete() {
        global $DB;

        try {
            $deletecat = coursecat::get($this->existing->id, IGNORE_MISSING, true);
            $deletecat->delete_full(false);
            $this->id = $this->existing->id;
        }
        catch (moodle_exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Does the mode allow for category creation?
     *
     * @return bool
     */
    protected function can_create() {
        return in_array($this->mode, array(tool_uploadcoursecategory_processor::MODE_CREATE_ALL,
            tool_uploadcoursecategory_processor::MODE_CREATE_NEW,
            tool_uploadcoursecategory_processor::MODE_CREATE_OR_UPDATE));
    }

    /**
     * Does the mode allow for category update?
     *
     * @return bool
     */
    public function can_update() {
        return in_array($this->mode,
                array(
                    tool_uploadcoursecategory_processor::MODE_UPDATE_ONLY,
                    tool_uploadcoursecategory_processor::MODE_CREATE_OR_UPDATE)
                ) && $this->updatemode !== tool_uploadcoursecategory_processor::UPDATE_NOTHING;
    }

    /**
     * Does the mode allow for category deletion?
     *
     * @return bool
     */
    protected function can_delete() {
        return $this->importoptions['allowdeletes'];
    }

    /**
     * Return whether there were errors with this category.
     *
     * @return bool
     */
    public function has_errors() {
        return !empty($this->errors);
    }

    /**
     * Does the mode allow for category renaming?
     *
     * @return bool
     */
    public function can_rename() {
        return $this->importoptions['allowrenames'];
    }

    /**
     * Can we modify the field of an existing category?
     *
     * @return bool
     */
    protected function can_modify() {
        return $this->mode === tool_uploadcoursecategory_processor::MODE_CREATE_OR_UPDATE &&
            in_array($this->updatemode, array(tool_uploadcoursecategory_processor::UPDATE_ALL_WITH_DATA_ONLY,
                tool_uploadcoursecategory_processor::UPDATE_ALL_WITH_DATA_OR_DEFAULTS));
    }

    /**
     * Validates and prepares the data.
     *
     * @return bool false is any error occured.
     */
    public function prepare() {
        global $DB;

        $this->prepared = true;
        
        // Checking mandatory fields.
        foreach (self::$mandatoryfields as $key => $field) {
            if (!isset($this->rawdata[$field])) {
                $this->error('missingmandatoryfields', new lang_string('missingmandatoryfields',
                    'tool_uploadcoursecategory'));
                return false;
            }
        }

        // Validate idnumber field.
        if (isset($this->rawdata['idnumber']) && !is_numeric($this->rawdata['idnumber'])) {
            $this->error('idnumbernotanumber', new lang_string('idnumbernotanumber',
                'tool_uploadcoursecategory'));
            return false;
        }

        // Standardise name
        if ($this->importoptions['standardise']) {
            $this->name = clean_param($this->name, PARAM_MULTILANG);
        }

        // Validate parent hierarchy.
        $this->parentid = $this->prepare_parent();
        if ($this->parentid == -1) {
            $this->error('missingcategoryparent', new lang_string('missingcategoryparent',
                'tool_uploadcoursecategory'));
            return false;
        }

        $this->existing = $this->exists();

        // Can we delete the category?
        if (!empty($this->options['deleted'])) {
            if (empty($this->existing)) {
                $this->error('cannotdeletecategorynotexist', new lang_string('cannotdeletecategorynotexist',
                    'tool_uploadcoursecategory'));
                return false;
            } else if (!$this->can_delete()) {
                $this->error('categorydeletionnotallowed', new lang_string('categorydeletionnotallowed',
                    'tool_uploadcoursecategory'));
                return false;
            }

            $this->do = self::DO_DELETE;
            
            // We only need the name and parent id for category deletion.
            return true;
        }

        // Can we create/update the course under those conditions?
        if ($this->existing) {

            if ($this->mode === tool_uploadcoursecategory_processor::MODE_CREATE_NEW) {
                $this->error('categoryexistsanduploadnotallowed',
                    new lang_string('categoryexistsanduploadnotallowed', 'tool_uploadcoursecategory'));
                return false;
            }
        } else {
            // If I cannot create the course, or I'm in update-only mode and I'm 
            // not renaming
            if (!$this->can_create() && 
                    $this->mode === tool_uploadcoursecategory_processor::MODE_UPDATE_ONLY &&
                    !isset($this->rawdata['oldname'])) {
                $this->error('categorydoesnotexistandcreatenotallowed',
                    new lang_string('categorydoesnotexistandcreatenotallowed', 
                        'tool_uploadcoursecategory'));
                return false;
            }
        }

        // Preparing final category data.
        $finaldata = array();
        foreach ($this->rawdata as $field => $value) {
            if (!in_array($field, self::$validfields)) {
                continue;
            }
            $finaldata[$field] = $value;
        }
        $finaldata['name'] = $this->name;
       
        // Can the category be renamed?
        if (!empty($finaldata['oldname'])) {
            if ($this->existing) {
                $this->error('cannotrenamenamealreadyinuse',
                    new lang_string('cannotrenamenamealreadyinuse', 
                        'tool_uploadcoursecategory'));
                return false;
            }

            $categories = explode('/', $finaldata['oldname']);
            $oldname = array_pop($categories);
            $oldname = trim($oldname);
            if ($this->importoptions['standardise']) {
                $oldname = clean_param($oldname, PARAM_MULTILANG);
            }
            $oldparentid = $this->prepare_parent($categories, 0);
            $this->existing = $this->exists($oldname, $oldparentid);

            /*
            print "\noldparentid = $oldparentid, oldname = $oldname\n";
            print "this->existing:\n";
            var_dump($this->existing);
            print "\n";
             */

            if ($oldparentid === -1) {
                $this->error('oldcategoryhierarchydoesnotexist', 
                    new lang_string('coldcategoryhierarchydoesnotexist',
                        'tool_uploadcoursecategory'));
                return false;
            } else if (!$this->can_update()) {
                $this->error('canonlyrenameinupdatemode', 
                    new lang_string('canonlyrenameinupdatemode', 'tool_uploadcoursecategory'));
                return false;
            } else if (!$this->existing) {
                $this->error('cannotrenameoldcategorynotexist',
                    new lang_string('cannotrenameoldcategorynotexist', 
                        'tool_uploadcoursecategory'));
                return false;
            } else if (!$this->can_rename()) {
                $this->error('categoryrenamingnotallowed',
                    new lang_string('categoryrenamingnotallowed', 
                        'tool_uploadcoursecategory'));
                return false;
            } else if (isset($this->rawdata['idnumber'])) {
                // If category id belongs to another category
                if ($this->existing->idnumber !== $finaldata['idnumber'] &&
                        $DB->record_exists('course_categories', array('idnumber' => $finaldata['idnumber']))) {
                    $this->error('idnumberalreadyexists', new lang_string('idnumberalreadyexists', 
                        'tool_uploadcoursecategory'));
                    return false;
                }
            }

            // All the needed operations for renaming are done.
            $this->finaldata = $this->get_final_update_data($finaldata, $this->existing);
            $this->do = self::DO_UPDATE;

            $this->set_status('coursecategoryrenamed', new lang_string('coursecategoryrenamed', 
                'tool_uploadcoursecategory', array('from' => $oldname, 'to' => $finaldata['name'])));

            return true;
        }

        // If exists, but we only want to create categories, increment the name.
        if ($this->existing && $this->mode === tool_uploadcoursecategory_processor::MODE_CREATE_ALL) {
            $original = $this->name;
            $this->name = cc_increment_name($this->name);
            // We are creating a new course category
            $this->existing = null;

            if ($this->name !== $original) {
                $this->set_status('coursecategoryrenamed',
                    new lang_string('coursecategoryrenamed', 'tool_uploadcoursecategory',
                    array('from' => $original, 'to' => $this->name)));
                if (isset($finaldata['idnumber'])) {
                    $originalidn = $finaldata['idnumber'];
                    $finaldata['idnumber'] = cc_increment_idnumber($finaldata['idnumber']);
                }
            }
        }  

        // Check if idnumber is already taken
        if (!$this->existing && isset($finaldata['idnumber']) &&
                $DB->record_exists('course_categories', array('idnumber' => $finaldata['idnumber']))) {
            $this->error('idnumbernotunique', new lang_string('idnumbernotunique',
                'tool_uploadcoursecategory'));
            return false;
        }

        // Ultimate check mode vs. existence.
        switch ($this->mode) {
            case tool_uploadcoursecategory_processor::MODE_CREATE_NEW:
            case tool_uploadcoursecategory_processor::MODE_CREATE_ALL:
                if ($this->existing) {
                    $this->error('categoryexistsanduploadnotallowed',
                        new lang_string('categoryexistsanduploadnotallowed', 
                            'tool_uploadcoursecategory'));
                    return false;
                }
                break;
            case tool_uploadcoursecategory_processor::MODE_UPDATE_ONLY:
                if (!$this->existing) {
                    $this->error('categorydoesnotexistandcreatenotallowed',
                        new lang_string('categorydoesnotexistandcreatenotallowed',
                            'tool_uploadcoursecategory'));
                    return false;
                }
                // No break!
            case tool_uploadcoursecategory_processor::MODE_CREATE_OR_UPDATE:
                if ($this->existing) {
                    if ($updatemode === tool_uploadcoursecategory_processor::UPDATE_NOTHING) {
                        $this->error('updatemodedoessettonothing',
                            new lang_string('updatemodedoessettonothing', 'tool_uploadcoursecategory'));
                        return false;
                    }
                }
                break;
            default:
                // O_o Huh?! This should really never happen here!
                $this->error('unknownimportmode', new lang_string('unknownimportmode', 
                    'tool_uploadcoursecategory'));
                return false;
        }

        // Get final data.
        if ($this->existing) {
            $missingonly = ($updatemode === tool_uploadcoursecategory_processor::UPDATE_MISSING_WITH_DATA_OR_DEFAULTS);
            $finaldata = $this->get_final_update_data($finaldata, $this->existing, $this->defaults, $missingonly);

            // Make sure we are not trying to mess with the front page, though we should never get here!
            if ($finaldata['id'] == $SITE->id) {
                $this->error('cannotupdatefrontpage', new lang_string('cannotupdatefrontpage', 
                    'tool_uploadcoursecategory'));
                return false;
            }

            $this->do = self::DO_UPDATE;
        } else {
            $finaldata = $this->get_final_create_data($coursedata);
            $this->do = self::DO_CREATE;
        }

        // Saving data.
        $this->finaldata = $finaldata;

        return true;
    }

    /**
     * Assemble the category data.
     *
     * This returns the final data to be passed to update_category().
     *
     * @param array $finaldata current data.
     * @param bool $usedefaults are defaults allowed?
     * @param array $existingdata existing category data.
     * @param bool $missingonly ignore fields which are already set.
     * @return array
     */
    protected function get_final_update_data($data, $existingdata, $usedefaults = false, $missingonly = false) {
        global $DB;

        $newdata = array();
        foreach (self::$validfields as $field) {
            if ($missingonly) {
                if (!is_null($existingdata->$field) and $existingdata->$field !== '') {
                    continue;
                }
            }
            if (isset($data[$field])) {
                $newdata[$field] = $data[$field];
            } else if ($usedefaults && isset($this->defaults[$field])) {
                $newdata[$field] = $this->defaults[$field];
            }
        }
        $newdata['id'] =  $existingdata->id;

        return $newdata;
    }

    /**
     * Assemble the course category data based on defaults.
     *
     * This returns the final data to be passed to create_category().
     *
     * @param array data current data.
     * @return array
     */
    protected function get_final_create_data($data) {
        foreach (self::$validfields as $field) {
            if (!isset($data[$field]) && isset($this->defaults[$field])) {
                $data[$field] = $this->defaults[$field];
            }
        }
        $data['parent'] = $this->parentid;
        // If we incremented the name
        $data['name'] = $this->name;

        return $data;
    }

    /**
     * Proceed with the import of the course category.
     *
     * @return void
     */
    public function proceed() {
        if (!$this->prepared) {
            throw new coding_exception('The course has not been prepared.');
        } else if ($this->has_errors()) {
            throw new moodle_exception('Cannot proceed, errors were detected.');
        } else if ($this->processstarted) {
            throw new coding_exception('The process has already been started.');
        }
        $this->processstarted = true;

        if ($this->do === self::DO_DELETE) {
            if ($this->delete()) {
                $this->set_status('coursecategorydeleted', 
                    new lang_string('coursecategorydeleted', 'tool_uploadcoursecategory'));
            } else {
                $this->error('errorwhiledeletingcourse', new lang_string('errorwhiledeletingcourse',
                    'tool_uploadcoursecategory'));
            }
            return true;
        } else if ($this->do === self::DO_CREATE) {
            try {
                $newcat = coursecat::create($this->finaldata); 
            }
            catch (moodle_exception $e) {
                $this->error('errorwhilecreatingcourse',
                    new lang_string('errorwhiledeletingcourse', 'tool_uploadcoursecategory'));
            }
            $this->id = $newcat->id;
            $this->set_status('coursecategoriescreated',
                new lang_string('coursecategoriescreated', 'tool_uploadcoursecategory'));
        } else if ($this->do === self::DO_UPDATE) {
            $cat = coursecat::get($this->existing->id, IGNORE_MISSING, true);
            try {
                $cat->update($this->finaldata);
            }
            catch (moodle_exception $e) {
                $this->error('errorwhileupdatingcourse', new lang_string('errorwhileupdatingcourse',
                    'tool_uploadcoursecategory'));
            }
            $this->id = $cat->id;
            $this->set_status('coursecategoryupdated', 
                new lang_string('coursecategoryupdated', 'tool_uploadcoursecategory'));
        }
    }
}
