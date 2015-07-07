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
 * File containing the course class.
 *
 * @package    tool_uploadcourse
 * @copyright  2013 Frédéric Massart
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Course class.
 *
 * @package    tool_uploadcourse
 * @copyright  2013 Frédéric Massart
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_uploadcoursecategory_category {

    /** Outcome of the process: creating the course */
    const DO_CREATE = 1;

    /** Outcome of the process: updating the course */
    const DO_UPDATE = 2;

    /** Outcome of the process: deleting the course */
    const DO_DELETE = 3;

    /** @var array final import data. */
    protected $finaldata = array();

    /** @var array errors. */
    protected $errors = array();

    /** @var int the ID of the course category that had been processed. */
    protected $id;

    /** @var array containing options passed from the processor. */
    protected $importoptions = array();

    /** @var int import mode. Matches tool_uploadcourse_processor::MODE_* */
    protected $mode;

    /** @var array course import options. */
    protected $options = array();

    /** @var int constant value of self::DO_*, what to do with that course */
    protected $do;

    /** @var bool set to true once we have prepared the course */
    protected $prepared = false;

    /** @var bool set to true once we have started the process of the course */
    protected $processstarted = false;
,
    /** @var array course import data. */
    protected $rawdata = array();

    /** @var string category name. */
    protected $name;

    /** @var int update mode. Matches tool_uploadcourse_processor::UPDATE_* */
    protected $updatemode;

    /** @var array fields allowed as course data. */
    static protected $validfields = array('name', 'description', 'idnumber',
        'visible', 'delete', 'theme', 'oldname');

    /** @var array fields required on course creation. */
    static protected $mandatoryfields = array('name');

    /** @var array fields which are considered as options. */
    static protected $optionfields = array('delete' => false, 'visible' => true,
        'oldname' => null);
}
