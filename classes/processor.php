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
 * File containing processor class.
 *
 * @package    tool_uploadcoursecategory
 * @copyright  2015 Alexandru Elisei
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/csvlib.class.php');
require_once($CFG->libdir . '/datalib.php');

/**
 * Processor class.
 *
 * @package    tool_uploadcoursecategory
 * @copyright  2015 Alexandru Elisei
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_uploadcoursecategory_processor {

    /**
     * Create categories that do not exist yet.
     */
    const MODE_CREATE_NEW = 1;

    /**
     * Create all categories, appending a suffix to the name if the category exists.
     */
    const MODE_CREATE_ALL = 2;

    /**
     * Create categories, and update the ones that already exist.
     */
    const MODE_CREATE_OR_UPDATE = 3;

    /**
     * Only update existing categories.
     */
    const MODE_UPDATE_ONLY = 4;

    /**
     * Do not update existing categories.
     */
    const UPDATE_NOTHING = 5;

    /**
     * During update, only use data passed from the CSV.
     */
    const UPDATE_ALL_WITH_DATA_ONLY = 6;

    /**
     * During update, use either data from the CSV, or defaults.
     */
    const UPDATE_ALL_WITH_DATA_OR_DEFAULTS = 7;

    /**
     * During update, update missing values from either data from the CSV, or defaults.
     */
    const UPDATE_MISSING_WITH_DATA_OR_DEFAULTS = 8;

    /** @var int processor mode. */
    protected $mode;

    /** @var int upload mode. */
    protected $updatemode;

    /** @var bool are renames allowed. */
    protected $allowrenames;

    /** @var bool are deletes allowed. */
    protected $allowdeletes;

    /** @var bool are to be standardised. */
    protected $standardise;

    /** @var bool create missing parents in category hierarchy. */
    protected $createmissing;

    /** @var csv_import_reader */
    protected $cir;

    /** @var array CSV columns. */
    protected $columns = array();

    /** @var array of errors where the key is the line number. */
    protected $errors = array();

    /** @var int line number. */
    protected $linenum = 0;

    /** @var bool whether the process has been started or not. */
    protected $processstarted = false;

    /**
     * Constructor
     *
     * @param csv_import_reader $cir import reader object
     * @param array $options options of the process
     */
    public function __construct(csv_import_reader $cir, array $options) {

        // Extra sanity checks
        if (!isset($options['mode']) || !in_array($options['mode'], array(self::MODE_CREATE_NEW, self::MODE_CREATE_ALL,
                self::MODE_CREATE_OR_UPDATE, self::MODE_UPDATE_ONLY))) {
            throw new coding_exception('Unknown process mode');
        }

        // Force int to make sure === comparison work as expected.
        $this->mode = (int) $options['mode'];

        // Default update mode
        $this->updatemode = self::UPDATE_NOTHING;
        if (isset($options['updatemode'])) {
            $this->updatemode = (int) $options['updatemode'];
        }
        if (isset($options['allowrenames'])) {
            $this->allowrenames = $options['allowrenames'];
        }
        if (isset($options['allowdeletes'])) {
            $this->allowdeletes = $options['allowdeletes'];
        }
        if (isset($options['standardise'])) {
            $this->standardise = $options['standardise'];
        }

        if (isset($options['createmissing'])) {
            $this->createmissing = $options['createmissing'];
        }

        $this->cir = $cir;
        $this->columns = $cir->get_columns();
        $this->validate_csv();
        $this->reset();
    }

    /**
     * Execute the process.
     *
     * @param object $tracker the output tracker to use.
     * @return void
     */
    public function execute($tracker = null) {

        global $DB;

        if ($this->processstarted) {
            throw new moodle_exception('process_already_started', 'error');
        }
        $this->processstarted = true;

        if (is_null($tracker)) {
            $tracker = new tool_uploadcoursecategory_tracker(tool_uploadcoursecategory_tracker::OUTPUT_PLAIN);
        }
        $tracker->start();

        // Statistics for tracker.
        $total = 0;
        $created = 0;
        $updated = 0;
        $deleted = 0;
        $errors = 0;

        core_php_time_limit::raise();
        raise_memory_limit(MEMORY_HUGE);

        // Loop over CSV lines.
        while ($line = $this->cir->next()) {
            $this->linenum++;
            $total++;

            $data = $this->parse_line($line);
            $category = $this->get_coursecategory($data);
            if ($category->prepare()) {
                $category->proceed();
                $status = $category->get_status();
                if (array_key_exists('coursecategoriescreated', $status)) {
                    $created++;
                } else if (array_key_exists('coursecategoryupdated', $status)) {
                    $updated++;
                } else if (array_key_exists('coursecategorydeleted', $status)) {
                    $deleted++;
                }
                
                $data = array_merge($data, $category->get_finaldata(), array('id' => $category->get_id()));
                $tracker->output($this->linenum, true, $status, $data);
            } else {
                $errors++;
                $tracker->output($this->linenum, false, $category->get_errors(), $data);
            }
        }
        $tracker->results($total, $created, $updated, $deleted, $errors);
    }

    /**
     * Return a category import object.
     *
     * @param array $data to import the category with.
     * @return tool_uploadcoursecategory_category
     */
    protected function get_coursecategory($data) {
        $importoptions = array(
            'allowdeletes'  => $this->allowdeletes,
            'allowrenames'  => $this->allowrenames,
            'standardise'   => $this->standardise,
            'createmissing' => $this->createmissing,
        );
        return  new tool_uploadcoursecategory_category($this->mode, $this->updatemode, $data, $importoptions);
    }

    /**
     * Parse a line to return an array(column => value)
     *
     * @param array $line returned by csv_import_reader
     * @return array
     */
    protected function parse_line($line) {
        $data = array();
        foreach ($line as $keynum => $value) {
            $column = $this->columns[$keynum];
            $lccolumn = trim(core_text::strtolower($column));
            $data[$lccolumn] = $value;
        }
        return $data;
    }

    /** 
     * CSV file validation.
     *
     * @return void
     */
    protected function validate_csv() {
        if (empty($this->columns)) {
            throw new moodle_exception('cannot_read_tmp_file', 'error');
        }
    }

    /**
     * Reset the current process.
     *
     * @return void.
     */
    protected function reset() {
        $this->processstarted = false;
        $this->linenum = 0;
        $this->cir->init();
        $this->errors = array();
    }
}
