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
 * Data root folder target class.
 *
 * @package    tool_etl
 * @copyright  2017 Dmitrii Metelkin <dmitriim@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_etl\target;

use tool_etl\config_field;
use tool_etl\logger;

defined('MOODLE_INTERNAL') || die;

class target_dataroot extends target_base {
    /**
     * Name of the source.
     *
     * @var string
     */
    protected $name = "Site data";

    /**
     * Settings of the target.
     *
     * @var array
     */
    protected $settings = array(
        'path' => '',
        'clreateifnotexist' => 0,
        'filename' => '',
        'overwrite' => 1,
        'addtime' => 0,
        'delimiter' => '',
        'dateformat' => 'dmY_hi',
        'backupfiles' => 1,
    );

    /**
     * Return a full source path.
     *
     * @return string
     */
    protected function get_full_path() {
        global $CFG;

        return $CFG->dataroot . DIRECTORY_SEPARATOR . $this->settings['path'];
    }

    /**
     * Load data from files.
     *
     * @param array $filepaths A list of files to load from.
     *
     * @return bool
     * @throws \coding_exception If incorrect file paths format.
     */
    protected function load_from_files($filepaths) {
        if (!is_array($filepaths)) {
            throw new \coding_exception('File paths should be an array');
        }

        $this->backup_files($filepaths);

        $result = true;

        foreach ($filepaths as $file) {
            $target = $this->get_target_file_path($file);

            if (file_exists($target) && empty($this->settings['overwrite'])) {
                $this->log('load_data', 'Skip copying file ' . $file . ' to ' . $target . ' File exists.', logger::TYPE_WARNING);
                continue;
            }

            if ($file == $target) {
                $this->log('load_data', 'Skip to copy file ' . $file . ' to ' . $target . 'The same files.', logger::TYPE_ERROR);
                continue;
            }

            if (!copy($file, $target)) {
                $this->log('load_data', 'Failed to copy file ' . $file . ' to ' . $target, logger::TYPE_ERROR);
                $result = false; // Fail result if any file is failed.
            } else {
                $this->log('load_data', 'Successfully copied file ' . $file . ' to ' . $target);
            }
        }

        return $result;
    }

    /**
     * Load data from objects.
     *
     * @param array $objects A list of objects to load from.
     *
     * @return bool
     * @throws \coding_exception If incorrect file paths format.
     */
    protected function load_from_array($array) {

        $result = true;

        $target = $this->get_target_file_path($this->settings['filename']);

        if (file_exists($target) && empty($this->settings['overwrite'])) {
            $this->log('load_data', 'Skip saving sql query to ' . $target . ' File exists.', logger::TYPE_WARNING);
        }

        if (is_dir($target)) {
            throw new \Exception('Specified export file path is a dir: ' . $target);
        }

        if (($handle = fopen($target, 'w')) !== false) {

            foreach ($array as $row) {
                $tmp = get_object_vars($row);
                $result = fputcsv($handle, $tmp, ',');

                if (!$result) {
                    throw new \Exception('Can\'t write to the export file: ' . $target);
                }
            }
        } else {
            throw new \Exception('Can\'t open the export file: ' . $target);
        }

        fclose($handle);

        return $result;

    }

    /**
     * @inheritdoc
     */
    public function create_config_form_elements(\MoodleQuickForm $mform) {
        $fields = array();

        $fields['path'] = new config_field(
            'path',
            'Path',
            'text',
            $this->settings['path'],
            PARAM_SAFEPATH
        );
        $fields['clreateifnotexist'] = new config_field(
            'clreateifnotexist',
            'Create folder if not exists',
            'advcheckbox',
            $this->settings['clreateifnotexist'],
            PARAM_BOOL
        );
        $fields['filename'] = new config_field(
            'filename',
            'File name',
            'text',
            $this->settings['filename'],
            PARAM_FILE
        );
        $fields['overwrite'] = new config_field(
            'overwrite',
            'Overwrite files?',
            'advcheckbox',
            $this->settings['overwrite'],
            PARAM_BOOL
        );
        $fields['addtime'] = new config_field(
            'addtime',
            'Append files by date like ' . date($this->get_date_format(), time()),
            'advcheckbox',
            $this->settings['addtime'],
            PARAM_BOOL
        );

        $fields['dateformat'] = new config_field(
            'dateformat',
            'Date format',
            'select',
            $this->settings['dateformat'],
            PARAM_INT,
            $this->get_list_of_formats()
        );

        $fields['delimiter'] = new config_field(
            'delimiter',
            'Date delimiter',
            'text',
            $this->settings['delimiter'],
            PARAM_RAW
        );
        $fields['backupfiles'] = new config_field(
            'backupfiles',
            'Backup files?',
            'advcheckbox',
            $this->settings['backupfiles'],
            PARAM_BOOL
        );

        $elements = $this->get_config_form_elements($mform, $fields);

        // Disable Create folder setting if writing in the root of sitedata.
        $mform->disabledIf(
            $this->get_config_form_prefix() . 'clreateifnotexist',
            $this->get_config_form_prefix() . 'path',
            'eq',
            ''
        );

        // Disable delimiter setting if not appending files by date.
        $mform->disabledIf(
            $this->get_config_form_prefix() . 'delimiter' ,
            $this->get_config_form_prefix() . 'addtime'
        );

        return $elements;
    }

    /**
     * @inheritdoc
     */
    public function validate_config_form_elements($data, $files, $errors) {
        if (!empty($data[$this->get_config_form_prefix() . 'clreateifnotexist'])) {
            if (empty($data[$this->get_config_form_prefix() . 'path'])) {
                $errors[$this->get_config_form_prefix() . 'path'] = 'Path can not be empty';
            }
        }

        return $errors;
    }

    /**
     * @inheritdoc
     */
    public function is_available() {
        if (!empty($this->settings['clreateifnotexist'])) {
            check_dir_exists($this->get_full_path());
        }

        if (is_dir($this->get_full_path()) && is_writable($this->get_full_path())) {
            return true;
        }

        $this->log('load_data', 'Directory is not writable ' . $this->get_full_path(), logger::TYPE_ERROR);

        return false;
    }

    /**
     * Returns full path of target file.
     *
     * @param string $file temp file path or filename.
     *
     * @return string
     */
    protected function get_target_file_path($file) {
        return rtrim($this->get_full_path(), '/') . '/' . $this->get_target_file_name($file);
    }

    /**
     * Returns array of available date formats.
     *
     * @return array
     */
    protected function get_list_of_formats() {

        $availableformats = array('dmY_hi', 'dmy_hi', 'Ydm_hi', 'ydm_hi', 'dmYhi', 'dmyhi', 'Ydmhi', 'ydmhi');
        $formats = array();

        foreach ($availableformats as $format) {
            $formats[$format] = date($format, time());
        }

        return $formats;
    }
}
