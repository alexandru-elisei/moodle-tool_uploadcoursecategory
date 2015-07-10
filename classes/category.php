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

    /** @var int the ID of the course category that had been processed. */
    protected $id;

    /** @var int the ID of the course category parent, default 0 ("Top"). */
    protected $parent = 0;

    /** @var array containing options passed from the processor. */
    protected $importoptions = array();

    /** @var int import mode. Matches tool_uploadcoursecategory_processor::MODE_* */
    protected $mode;

    /** @var int update mode. Matches tool_uploadcourse_processor::UPDATE_* */
    protected $updatemode;

    /** @var array course category import options. */
    protected $options = array();

    /** @var int constant value of self::DO_*, what to do with that course category */
    protected $do;

    /** @var object database record of an existing category */
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
        }
        $this->rawdata = $rawdata;

        // Extract course options.
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
     * Return the errors found during preparation.
     *
     * @return array
     */
    public function get_errors() {
        return $this->errors;
    }

    /**
     * Return the course category database entry, or null.
     *
     * @param string $name the name to use to check if the category exists.
     * @param int $parent the id of the parent.
     * @return bool
     */
    protected function exists($name = null, $parent = null) {
        global $DB;

        if (is_null($name)) {
            $name = $this->name;
        }
        if (is_null($parent)) {
            $parent = $this->parent;
        }
        return $DB->get_record('course_categories', array('name' => $name,
                'parent' => $parent));
    }

    /**
     * Extracts the parent and validates the category hierarchy.
     *
     * @return bool false if one of the parents doesn't exist
     */
    protected function prepare_parent(){
        global $DB;

        $categories = explode('/', $this->rawdata['name']);
        // Removing from hierarchy the category we wish to create/modify
        array_pop($categories);
        
        // Removing "Top" parent category
        if (count($categories) > 0) {
            if ($categories[0] == get_string('top')) {
                array_shift($categories);
            }
        }

        // Walking the hierarchy to check if parents exist
        if (count($categories) > 0) {
            foreach ($categories as $cat) {
                $cat = trim($cat);
                $category = $DB->get_record('course_categories', 
                        array('name' => $cat, 'parent' => $this->parent));
                if (empty($category)) {
                    return false;
                }
                $this->parent = $category->id;
            }
        }

        return true;
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
     * Proceed with the import of the category.
     *
     * @return bool false if an error occured.
     */
    public function proceed() {
        global $CFG, $USER;

        if (!$this->prepared) {
            throw new coding_exception('The course has not been prepared.');
        } else if ($this->has_errors()) {
            throw new moodle_exception('Cannot proceed, errors were detected.');
        } else if ($this->processstarted) {
            throw new coding_exception('The process has already been started.');
        }
        $this->processstarted = true;

        if ($this->do === self::DO_DELETE) {
            if (!$this->delete()) {
                $this->error('errorwhiledeletingcategory', 
                    new lang_string('errorwhiledeletingcourse', 'tool_uploadcoursecategory'));
                return false;
            }
            $this->processstarted = false;

            return true;
        }
    }

    /**
     * Validates and prepares the data.
     *
     * @return bool false is any error occured.
     */
    public function prepare() {
        global $DB;

        $this->prepared = true;

        print "\nEntering prepare...\n";
        
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
        if(!$this->prepare_parent()) {
            $this->error('missingcategoryparent', new lang_string('missingcategoryparent',
                'tool_uploadcoursecategory'));
            return false;
        }

        $this->existing = $this->exists();

        // Can we delete the category?
        if (!empty($this->options['deleted'])) {
            if (is_null($this->existing)) {
                $this->error('cannotdeletecategorynotexist', new lang_string('cannotdeletecategorynotexist',
                    'tool_uploadcoursecategory'));
                return false;
            } else if (!$this->can_delete()) {
                $this->error('categorydeletionnotallowed', new lang_string('categorydeletionnotallowed',
                    'tool_uploadcoursecategory'));
                return false;
            }

            $this->do = self::DO_DELETE;

            print "\ncategory: category deletion accepted\n";

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
        
        // Check if idnumber already exists, idnumber updating not allowed
        /*
        if ($this->existing && isset($this->rawdata['idnumber']) &&
                $DB->record_exists('course_categories', array('idnumber' => $thos->rawdata['idnumber']))) {
            $this->error('idnumbernotunique', new lang_string('idnumbernotunique',
                'tool_uploadcoursecategory'));
            return false;

            print "\ncategory id check passed\n";

        }
         */
        
        // Can the category be renamed?
        if (!empty($this->rawdata['oldname'])) {
            $oldname = $this->rawdata['oldname'];

            print "\noldname: $oldname, new name: $this->name\n";

            if (!$this->can_update()) {
                $this->error('canonlyrenameinupdatemode', 
                    new lang_string('canonlyrenameinupdatemode', 'tool_uploadcoursecategory'));
                return false;
            } else if (!$this->exists($oldname)) {
                $this->error('cannotrenamecategorynotexist',
                    new lang_string('cannotrenamecategorynotexist', 
                        'tool_uploadcoursecategory'));
                return false;
            } else if (!$this->can_rename()) {
                $this->error('categoryrenamingnotallowed',
                    new lang_string('categoryrenamingnotallowed', 
                        'tool_uploadcoursecategory'));
                return false;
            } else if ($this->exists($this->name)) {
                $this->error('cannotrenamenamealreadyinuse',
                    new lang_string('cannotrenamenamealreadyinuse', 
                        'tool_uploadcoursecategory'));
                return false;
            }
        }

                /*
            } else if (isset($coursedata['idnumber']) &&
                    $DB->count_records_select('course', 'idnumber = :idn AND shortname != :sn',
                    array('idn' => $coursedata['idnumber'], 'sn' => $this->shortname)) > 0) {
                $this->error('cannotrenameidnumberconflict', new lang_string('cannotrenameidnumberconflict', 'tool_uploadcourse'));
                return false;
            }
            $coursedata['shortname'] = $this->options['rename'];
            $this->status('courserenamed', new lang_string('courserenamed', 'tool_uploadcourse',
                array('from' => $this->shortname, 'to' => $coursedata['shortname'])));
                 */

        print "\nCan rename!\n";


        // If exists, but we only want to create courses, increment the shortname.
        /*
        if ($exists && $mode === tool_uploadcourse_processor::MODE_CREATE_ALL) {
            $original = $this->shortname;
            $this->shortname = tool_uploadcourse_helper::increment_shortname($this->shortname);
            $exists = false;
            if ($this->shortname != $original) {
                $this->status('courseshortnameincremented', new lang_string('courseshortnameincremented', 'tool_uploadcourse',
                    array('from' => $original, 'to' => $this->shortname)));
                if (isset($coursedata['idnumber'])) {
                    $originalidn = $coursedata['idnumber'];
                    $coursedata['idnumber'] = tool_uploadcourse_helper::increment_idnumber($coursedata['idnumber']);
                    if ($originalidn != $coursedata['idnumber']) {
                        $this->status('courseidnumberincremented', new lang_string('courseidnumberincremented', 'tool_uploadcourse',
                            array('from' => $originalidn, 'to' => $coursedata['idnumber'])));
                    }
                }
            }
        }

        // If the course does not exist, ensure that the ID number is not taken.
        if (!$exists && isset($coursedata['idnumber'])) {
            if ($DB->count_records_select('course', 'idnumber = :idn', array('idn' => $coursedata['idnumber'])) > 0) {
                $this->error('idnumberalreadyinuse', new lang_string('idnumberalreadyinuse', 'tool_uploadcourse'));
                return false;
            }
        }

        // Ultimate check mode vs. existence.
        switch ($mode) {
            case tool_uploadcourse_processor::MODE_CREATE_NEW:
            case tool_uploadcourse_processor::MODE_CREATE_ALL:
                if ($exists) {
                    $this->error('courseexistsanduploadnotallowed',
                        new lang_string('courseexistsanduploadnotallowed', 'tool_uploadcourse'));
                    return false;
                }
                break;
            case tool_uploadcourse_processor::MODE_UPDATE_ONLY:
                if (!$exists) {
                    $this->error('coursedoesnotexistandcreatenotallowed',
                        new lang_string('coursedoesnotexistandcreatenotallowed', 'tool_uploadcourse'));
                    return false;
                }
                // No break!
            case tool_uploadcourse_processor::MODE_CREATE_OR_UPDATE:
                if ($exists) {
                    if ($updatemode === tool_uploadcourse_processor::UPDATE_NOTHING) {
                        $this->error('updatemodedoessettonothing',
                            new lang_string('updatemodedoessettonothing', 'tool_uploadcourse'));
                        return false;
                    }
                }
                break;
            default:
                // O_o Huh?! This should really never happen here!
                $this->error('unknownimportmode', new lang_string('unknownimportmode', 'tool_uploadcourse'));
                return false;
        }

        // Get final data.
        if ($exists) {
            $missingonly = ($updatemode === tool_uploadcourse_processor::UPDATE_MISSING_WITH_DATA_OR_DEFAUTLS);
            $coursedata = $this->get_final_update_data($coursedata, $usedefaults, $missingonly);

            // Make sure we are not trying to mess with the front page, though we should never get here!
            if ($coursedata['id'] == $SITE->id) {
                $this->error('cannotupdatefrontpage', new lang_string('cannotupdatefrontpage', 'tool_uploadcourse'));
                return false;
            }

            $this->do = self::DO_UPDATE;
        } else {
            $coursedata = $this->get_final_create_data($coursedata);
            $this->do = self::DO_CREATE;
        }

        // Course start date.
        if (!empty($coursedata['startdate'])) {
            $coursedata['startdate'] = strtotime($coursedata['startdate']);
        }

        // Add role renaming.
        $errors = array();
        $rolenames = tool_uploadcourse_helper::get_role_names($this->rawdata, $errors);
        if (!empty($errors)) {
            foreach ($errors as $key => $message) {
                $this->error($key, $message);
            }
            return false;
        }
        foreach ($rolenames as $rolekey => $rolename) {
            $coursedata[$rolekey] = $rolename;
        }

        // Some validation.
        if (!empty($coursedata['format']) && !in_array($coursedata['format'], tool_uploadcourse_helper::get_course_formats())) {
            $this->error('invalidcourseformat', new lang_string('invalidcourseformat', 'tool_uploadcourse'));
            return false;
        }

        // Saving data.
        $this->data = $coursedata;
        $this->enrolmentdata = tool_uploadcourse_helper::get_enrolment_data($this->rawdata);

        // Restore data.
        // TODO Speed up things by not really extracting the backup just yet, but checking that
        // the backup file or shortname passed are valid. Extraction should happen in proceed().
        $this->restoredata = $this->get_restore_content_dir();
        if ($this->restoredata === false) {
            return false;
        }

        // We can only reset courses when allowed and we are updating the course.
        if ($this->importoptions['reset'] || $this->options['reset']) {
            if ($this->do !== self::DO_UPDATE) {
                $this->error('canonlyresetcourseinupdatemode',
                    new lang_string('canonlyresetcourseinupdatemode', 'tool_uploadcourse'));
                return false;
            } else if (!$this->can_reset()) {
                $this->error('courseresetnotallowed', new lang_string('courseresetnotallowed', 'tool_uploadcourse'));
                return false;
            }
        }
         */

        return true;
    }
}
