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
 * Version details.
 *
 * @package    tool
 * @subpackage foundrysync
 * @copyright  2020 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
Foundry Sync Plugin for Moodle
Copyright 2020 Carnegie Mellon University.
NO WARRANTY. THIS CARNEGIE MELLON UNIVERSITY AND SOFTWARE ENGINEERING INSTITUTE MATERIAL IS FURNISHED ON AN "AS-IS" BASIS. CARNEGIE MELLON UNIVERSITY MAKES NO WARRANTIES OF ANY KIND, EITHER EXPRESSED OR IMPLIED, AS TO ANY MATTER INCLUDING, BUT NOT LIMITED TO, WARRANTY OF FITNESS FOR PURPOSE OR MERCHANTABILITY, EXCLUSIVITY, OR RESULTS OBTAINED FROM USE OF THE MATERIAL. CARNEGIE MELLON UNIVERSITY DOES NOT MAKE ANY WARRANTY OF ANY KIND WITH RESPECT TO FREEDOM FROM PATENT, TRADEMARK, OR COPYRIGHT INFRINGEMENT.
Released under a GNU GPL 3.0-style license, please see license.txt or contact permission@sei.cmu.edu for full terms.
[DISTRIBUTION STATEMENT A] This material has been approved for public release and unlimited distribution.  Please see Copyright notice for non-US Government use and distribution.
This Software includes and/or makes use of the following Third-Party Software subject to its own license:
1. Moodle (https://docs.moodle.org/dev/License) Copyright 1999 Martin Dougiamas.
DM20-0198
 */

namespace tool_foundrysync\task;

defined('MOODLE_INTERNAL') || die();

class check_content extends \core\task\scheduled_task {

    protected $systemauth;
    protected $sitename;

    /**
     * Get a descriptive name for this task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskcheckcontent', 'tool_foundrysync');
    }

    public function setup() {
        global $SITE;

        $issuerid = get_config('tool_foundrysync', 'issuerid');
        if (!$issuerid) {
                echo "tool_foundrysync does not have issuerid set<br>";
            return;
        }

        $issuer = \core\oauth2\api::get_issuer($issuerid);
        $this->systemauth = \core\oauth2\api::get_system_oauth_client($issuer);
        if ($this->systemauth === false) {
            $details = 'Cannot connect as system account';
            echo "$details<br>";
            throw new \Exception($details);
            return;
        }

        /* TODO strip spaces */
        $this->sitename = $SITE->shortname;
        $this->clientid = $this->systemauth->get_clientid();

        $this->interval = get_config('tool_foundrysync', 'interval');
        if (!$this->interval) {
            $this->interval = 15;
        }
        $this->course = 0;
    }

    /* http://php.net/manual/en/function.com-create-guid.php */
    public function guidv4() {
        if (function_exists('com_create_guid') === true)
            return trim(com_create_guid(), '{}');

        $data = openssl_random_pseudo_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public function store_playlist_in_db($courseid, $guid, $name, $foundryid) {
        global $DB;

        $table = "tool_foundrysync_playlists";
        $dataobject['courseid'] = $courseid;
        $dataobject['foundryid'] = $foundryid;
        $dataobject['guid'] = $guid;
        $dataobject['name'] = $name;

        // TODO maybe check that it doesnt already exist
        $DB->insert_record($table, $dataobject, $returnid=true, $bulk=false);

    }

    public function update_playlist_in_db($courseid, $guid, $name, $foundryid) {
        global $DB;

        list($oldguid, $oldid, $dbid) = $this->get_playlist_from_db($courseid);

        $table = "tool_foundrysync_playlists";
        $dataobject['courseid'] = $courseid;
        $dataobject['foundryid'] = $foundryid;
        $dataobject['guid'] = $guid;
        $dataobject['name'] = $name;
        $dataobject['id'] = $dbid;

        $DB->update_record($table, $dataobject);

    }

    public function get_playlist_from_db($courseid) {
        global $DB;
        $record = $DB->get_record_sql("SELECT * from {tool_foundrysync_playlists} WHERE courseid = ?", array($courseid));
        if (!$record) {
            echo "no record in our table for courseid $courseid<br>";
            $guid = null;
            $id = null;
            $dbid = null;
        } else {
            $guid = $record->guid;
            $id = $record->foundryid;
            $dbid = $record->id;
        }
        return array($guid, $id, $dbid);
    }

    public function store_course_in_db($courseid, $guid, $name, $foundryid) {
        global $DB;

        $table = "tool_foundrysync_contents";
        $dataobject['foundryid'] = $foundryid;
        $dataobject['courseid'] = $courseid;
        $dataobject['guid'] = $guid;
        $dataobject['name'] = $name;

        // TODO maybe check that it doesnt already exist, or
        // set the table to use content id as primary key and require unique?
        $DB->insert_record($table, $dataobject, $returnid=true, $bulk=false);
    }

    public function store_content_in_db($objectid, $guid, $name, $foundryid) {
        global $DB;

        $table = "tool_foundrysync_contents";
        $dataobject['foundryid'] = $foundryid;
        $dataobject['objectid'] = $objectid;
        $dataobject['guid'] = $guid;
        $dataobject['name'] = $name;

        // TODO maybe check that it doesnt already exist, or
        // set the table to use content id as primary key and require unique?
        $DB->insert_record($table, $dataobject, $returnid=true, $bulk=false);

    }

    public function update_content_in_db($objectid, $guid, $name, $foundryid) {
        global $DB;

        list($oldguid, $oldid, $oldname, $dbid) = $this->get_content_from_db($objectid);

        $table = "tool_foundrysync_contents";
        $dataobject['objectid'] = $objectid;
        $dataobject['foundryid'] = $foundryid;
        $dataobject['guid'] = $guid;
        $dataobject['name'] = $name;
        $dataobject['id'] = $dbid;
        $DB->update_record($table, $dataobject);
    }

    public function update_course_in_db($courseid, $guid, $name, $foundryid) {
        global $DB;

        list($oldguid, $oldid, $oldname, $dbid) = $this->get_course_from_db($courseid);

        $table = "tool_foundrysync_contents";
        $dataobject['courseid'] = $courseid;
        $dataobject['foundryid'] = $foundryid;
        $dataobject['guid'] = $guid;
        $dataobject['name'] = $name;
        $dataobject['id'] = $dbid;
        $DB->update_record($table, $dataobject);
    }

    public function get_content_from_db($objectid) {
        global $DB;
        $record = $DB->get_record_sql("SELECT * from {tool_foundrysync_contents} WHERE objectid = ?", array($objectid));
        if (!$record) {
            echo "no record in our table for objectid $objectid<br>";
            $guid = null;
            $id = null;
            $name = null;
            $dbid = null;
        } else {
            $guid = $record->guid;
            $id = $record->foundryid;
            $name = $record->name;
            $dbid = $record->id;
        }
        return array($guid, $id, $name, $dbid);
    }

    public function get_course_from_db($courseid) {
        global $DB;
        $record = $DB->get_record_sql("SELECT * from {tool_foundrysync_contents} WHERE courseid = ?", array($courseid));
        if (!$record) {
            echo "no record in our table for courseid $courseid<br>";
            $guid = null;
            $id = null;
            $name = null;
            $dbid = null;
        } else {
            $guid = $record->guid;
            $id = $record->foundryid;
            $name = $record->name;
            $dbid = $record->id;
        }
        return array($guid, $id, $name, $dbid);
    }


    /* remove content from foundry if deleted from moodle */
    public function delete_data_on_foundry($data) {

        list($guid, $id, $name, $dbid) = $this->get_content_from_db($data['objectid']);
        echo "attempting to delete $name $id<br>";

        if (!$id) {
            echo "will not delete data from foundry without a local db record for it<br>";
            return;
        }

        $url = get_config('tool_foundrysync', 'endpoint') . "/api/content/" . $id;
        echo "DELETE $url<br>";

        /* execute delete */
        $response = $this->systemauth->delete($url);

        if ($this->systemauth->errno) {
            echo "curl error: " . curl_strerror($this->systemauth->errno) . "<br>";
            //throw new \Exception($response);
            return;
        }

        echo "response:<br><pre>$response</pre>";
        if ($this->systemauth->info['http_code']  !== 200) {
            echo "response code error " . $this->systemauth->info['http_code'] . "<br>";
            //throw new \Exception($response);
            return;
        }
        echo "content item deleted successfully<br>";

    }

    /* query foundry api for content and return existing content info */
    public function lookup_foundry_content_by_id($id) {
        $url = get_config('tool_foundrysync', 'endpoint') . "/api/content/" . $id;

        echo "GET $url<br>";

        $response = $this->systemauth->get($url);
        if (!$response) {
            echo "curl error: " . curl_strerror($this->systemauth->errno) . "<br>";
            //throw new \Exception($response);
            return;
        }
        echo "response:<br><pre>$response</pre>";
        if ($this->systemauth->info['http_code']  !== 200) {
            echo "response code ". $this->systemauth->info['http_code'] . "<br>";
            //throw new \Exception($response);
            return;
        }
        $r = json_decode($response);

        if (!$r) {
            echo "could not find single unique content item by id<br>";
            return null;
        }
        return $r;
    }

    /* query foundry api for content and return existing content info */
    public function lookup_foundry_content_by_url($name, $contenturl) {

        // TODO when this is fixed in foundry:
        $url = get_config('tool_foundrysync', 'endpoint') . "/api/contents?take=2&filter=url%3D" . rawurlencode($contenturl);
        echo "GET $url<br>";

        // TODO until then, lookup name then url
        $url = get_config('tool_foundrysync', 'endpoint') . "/api/contents?term=" . rawurlencode("$name");

        echo "GET $url<br>";

        /* execute post */
        $response = $this->systemauth->get($url);

        if (!$response) {
            echo "curl error: " . curl_strerror($this->systemauth->errno) . "<br>";
            //throw new \Exception($response);
            return;
        }
        echo "response:<br><pre>$response</pre>";
        if ($this->systemauth->info['http_code']  !== 200) {
            echo "response code ". $this->systemauth->info['http_code'] . "<br>";
            //throw new \Exception($response);
            return;
        }

        $r = json_decode($response);

        if ($r->total !== 1) {
            echo "could not find single unique content item by url<br>";
            return null;
        }
        if (($r->results[0]->name == $name) && ($r->results[0]->url == $contenturl)) {
            return $r->results[0];
        }
        return null;
    }

    // TODO change this to lookup by something other then name alone
    /* query foundry api for playlist and return existing playlist info */
    public function lookup_foundry_playlist($name) {
        echo "WARNING WARNING WARNING dangerous lookup by name<br>";
        echo "WARNING WARNING WARNING dangerous lookup by name<br>";
        // https://api.foundryqa.cwd.local/api/playlists?Term=CMU Demo Course
        $url = get_config('tool_foundrysync', 'endpoint') . "/api/playlists?Term=" . rawurlencode($name);

        echo "GET $url<br>";

        /* execute post */
        $response = $this->systemauth->get($url);

        if (!$response) {
            echo "curl error: " . curl_strerror($this->systemauth->errno) . "<br>";
            echo "response code ". $this->systemauth->info['http_code'] . "<br>";
            //throw new \Exception($response);
            return;
        }
        echo "response:<br><pre>$response</pre>";
        if ($this->systemauth->info['http_code']  !== 200) {
            echo "response code ". $this->systemauth->info['http_code'] . "<br>";
            //throw new \Exception($response);
            return;
        }

        $r = json_decode($response);
        if (count($r->results) !== 1) {
            echo "could not find single unique plstlist item by name<br>";
            $guid = null;
            $id = null;
            $logourl = null;
        } else {
            $guid = $r->results[0]->globalId;
            $id = $r->results[0]->id;
            $logourl = $r->results[0]->logoUrl;
        }
        //return array($guid, $id, $logourl);
        if ($r->results[0]->name == $name) {
            echo "found exact name match for playlist $name<br>";
            return $r->results[0];
        }
        return null;
    }

    /* query foundry api for playlist and return existing playlist info */
    public function lookup_foundry_playlist_by_id($id) {
        $url = get_config('tool_foundrysync', 'endpoint') . "/api/playlist/" . $id;

        echo "GET $url<br>";

        $response = $this->systemauth->get($url);
        if (!$response) {
            echo "curl error: " . curl_strerror($this->systemauth->errno) . "<br>";
            echo "response code ". $this->systemauth->info['http_code'] . "<br>";
            //throw new \Exception($response);
            return;
        }
        echo "response:<br><pre>$response</pre>";
        if ($this->systemauth->info['http_code']  !== 200) {
            echo "response code ". $this->systemauth->info['http_code'] . "<br>";
            //throw new \Exception($response);
            return;
        }
        $r = json_decode($response);

        if (!$r) {
            echo "could not find single unique playlist item by id<br>";
            return;
        }
        return $r;
    }

    public function post_playlist_to_foundry($playlist) {
        $url = get_config('tool_foundrysync', 'endpoint') . "/api/playlists";
        echo "POST $url<br>";

        $data = json_encode($playlist);
        $this->systemauth->setHeader('Content-Type: application/json');
        echo "request data:<br><pre>$data</pre>";

        $response = $this->systemauth->post($url, $data);
        if (!$response) {
            echo "curl error: " . curl_strerror($this->systemauth->errno) . "<br>";
            //throw new \Exception($response);
            return;
        }
        echo "response:<br><pre>$response</pre>";
        if ($this->systemauth->info['http_code'] !== 201) {
            echo "response code ". $this->systemauth->info['http_code'] . "<br>";
            //throw new \Exception($response);
            return;
        }
        $r = json_decode($response);

        if (!$r) {
            echo "error adding playlist<br>";
        }

        return $r;
    }

    public function put_sections_to_foundry($id, $sections) {
        $url = get_config('tool_foundrysync', 'endpoint') . "/api/playlist/$id/organize";
        echo "PUT $url<br>";

        $data = json_encode($sections);
        $this->systemauth->setHeader('Content-Type: application/json');
        echo "request data:<br><pre>$data</pre>";

        $response = $this->systemauth->put($url, $data);
        if (!$response) {
            echo "curl error: " . curl_strerror($this->systemauth->errno) . "<br>";
            echo "response code ". $this->systemauth->info['http_code'] . "<br>";
            //throw new \Exception($response);
            return;
        }
        echo "response:<br><pre>$response</pre>";
        if ($this->systemauth->info['http_code'] !== 200) {
            echo "response code ". $this->systemauth->info['http_code'] . "<br>";
            //throw new \Exception($response);
            return;
        }
        $r = json_decode($response);

        if (!$r) {
            echo "error adding sections<br>";
        }

        return $r;
    }

    public function post_content_to_foundry($content) {
        $url = get_config('tool_foundrysync', 'endpoint') . "/api/contents";
        echo "POST $url<br>";

        $data = json_encode($content);
        $this->systemauth->setHeader('Content-Type: application/json');
        echo "request data:<br><pre>$data</pre>";

        $response = $this->systemauth->post($url,  $data);
        if (!$response) {
            echo "curl error: " . curl_strerror($this->systemauth->errno) . "<br>";
            //throw new \Exception($response);
            return;
        }
        echo "response:<br><pre>$response</pre>";
        if ($this->systemauth->info['http_code'] !== 201) {
            echo "response code ". $this->systemauth->info['http_code'] . "<br>";
            //throw new \Exception($response);
            return;
        }
        $r = json_decode($response);

        if (!$r) {
            echo "error adding content<br>";
        }

        return $r;
    }

    public function update_playlist_in_foundry($playlist) {
        $url = get_config('tool_foundrysync', 'endpoint') . "/api/playlist/" . $playlist->id;
        echo "PUT $url<br>";

        $data = json_encode($playlist);
        $this->systemauth->setHeader('Content-Type: application/json');
        echo "request data:<br><pre>$data</pre>";

        $response = $this->systemauth->put($url,  $data);
        if (!$response) {
            echo "curl error: " . curl_strerror($this->systemauth->errno) . "<br>";
            //throw new \Exception($response);
            //return;
        }
        echo "response:<br><pre>$response</pre>";
        if ($this->systemauth->info['http_code'] !== 200) {
            echo "response code ". $this->systemauth->info['http_code'] . "<br>";
            //throw new \Exception($response);
            return;
        }
        $r = json_decode($response);

        if (!$r) {
            echo "error updating playlist<br>";
        }

        return $r;
    }

    public function update_content_in_foundry($content) {
        $url = get_config('tool_foundrysync', 'endpoint') . "/api/content/" . $content->id;
        echo "PUT $url<br>";

        $data = json_encode($content);
        $this->systemauth->setHeader('Content-Type: application/json');
        echo "request data:<br><pre>$data</pre>";

        $response = $this->systemauth->put($url,  $data);
        if (!$response) {
            echo "curl error: " . curl_strerror($this->systemauth->errno) . "<br>";
            //throw new \Exception($response);
            //return;
        }
        echo "response:<br><pre>$response</pre>";
        if ($this->systemauth->info['http_code'] !== 200) {
            echo "response code ". $this->systemauth->info['http_code'] . "<br>";
            //throw new \Exception($response);
            return;
        }
        $r = json_decode($response);

        if (!$r) {
            echo "error updating content<br>";
        }

        return $r;
    }

    /* checks whether course needs to sync as a playlist or a content item */
    /* this requires the local_metadata plugin to create fields checkboxes for syncplaylist and synccontent at the course level */
    public function get_local_metadata_course($course) {
        global $DB;

        $sync_playlist = 0;
        $sync_content = 0;

        $tables = $DB->get_tables();
        if (in_array('local_metadata_field', $tables )) {
            $syncplaylist_field = $DB->get_record_sql("SELECT id FROM {local_metadata_field} WHERE shortname = ?", array('syncplaylist'));
            $synccontent_field = $DB->get_record_sql("SELECT id FROM {local_metadata_field} WHERE shortname = ?", array('synccontent'));

            if ($syncplaylist_field) {
                $setting = $DB->get_record_sql("SELECT data FROM {local_metadata} WHERE instanceid = ? AND fieldid = ?", array($course->id, $syncplaylist_field->id));
                if ($setting->data == "1") {
                    echo "this course needs to sync as a playlist<br>";
                    $sync_playlist = 1;
                }
            }
            if ($synccontent_field) {
                $setting = $DB->get_record_sql("SELECT data FROM {local_metadata} WHERE instanceid = ? AND fieldid = ?", array($course->id, $synccontent_field->id));
                if ($setting->data == "1") {
                    echo "this course needs to sync as a content item<br>";
                    $sync_content= 1;
                }
            }
        }
        return array($sync_playlist, $sync_content);
    }

    /* queries for created/updated/deleted content */
    public function execute() {
        global $DB;

        if (!get_config('tool_foundrysync', 'enablecontentsync')) {
            echo "tool_foundrysync disabled in config<br>";
            return; // The tool is disabled. Nothing to do.
        }

        $this->setup();

        // prepare query
        $time = time() - $this->interval * 60;

        // get records
        $records = $DB->get_records_sql("SELECT * from {logstore_standard_log} WHERE eventname REGEXP 'course_module_(created|updated|deleted)' AND timecreated >= ?", array($time));
        echo count($records) . " record(s) updated during the last $this->interval minutes<br>";

        // get courses
        $courses = $DB->get_records_sql("SELECT * from {logstore_standard_log} WHERE eventname REGEXP 'course_(created|updated|deleted)' AND timecreated >= ?", array($time));
        echo count($courses) . " course(s) updated during the last $this->interval minutes<br>";

        $this->process_courses($courses);
        echo "<br>DONE PROCESSING COURSES<br>";
        // TODO remove course content item from foundry on deletion
        // TODO remove course playlist and content items from foundry on deletion

        $this->process_modules($records);
        echo "<br>DONE PROCESSING MODULES<br>";
    }

    public function process_courses($courses) {
        global $DB, $CFG;
        $data = array();
        $count = 0;

        foreach ($courses as $course_event) {
            echo "#################### processing course " . $count++ . "<br>";

            /* remove the content item from foundry if it was deleted from moodle */
            if ($course_event->action == "deleted") {
                echo "record $course_event->objectid was deleted<br>";
                $data['objectid'] = $course_event->objectid;
                $this->delete_data_on_foundry($data);
            }

            $course = $DB->get_record_sql("SELECT * FROM {course} WHERE id = ?", array($course_event->courseid));
            if (!$course) {
                    echo "no course found<br>";
                    continue;
            }
            echo "course name \"$course->fullname\"<br>";

            $idnumber = $DB->get_field('user', 'idnumber', array('id' => $course_event->userid));

            if ($idnumber) {
                echo "content author idnumber $idnumber<br>";
            }

            $sync_playlist = 0;
            $sync_content = 0;

            // check metadata
            list($sync_playlist, $sync_content) = $this->get_local_metadata_course($course);

            // check plugin setting (this may go away)
            if ($course_event->courseid == $this->course) {
                echo "this course needs a full playlist sync according to this plugins config<br>";
                $sync_playlist = 1;
            }

            // TODO: pass user guid value
            if ($sync_playlist) {
                echo "this course is set to be synced as a playlist<br>";
                $this->sync_as_playlist($course, $idnumber);
            }

            if ($sync_content) {
                echo "this course is set to be synced as a content item<br>";
                $this->sync_as_content($course, $idnumber);
            }
        }
    }

    public function process_modules($records) {
        global $DB, $CFG;
        $count = 0;
        foreach ($records as $record) {
            $data = array();
            $addtodb = 0;
            $guid = null;
            $id = null;
            $name = null;

            echo "#################### processing module " . $count++ . "<br>";

            $course = $DB->get_record_sql("SELECT * FROM {course} WHERE id = ?", array($record->courseid));
            if (!$course) {
                    echo "no course found for this module<br>";
                    continue;
            }
            echo "course name \"$course->fullname\"<br>";

            $idnumber = $DB->get_field('user', 'idnumber', array('id' => $record->userid));

            if ($idnumber) {
                echo "content author idnumber $idnumber<br>";
            }

            $sync_playlist = 0;
            $sync_content = 0;

            // check metadata
            list($sync_playlist, $sync_content) = $this->get_local_metadata_course($course);

            // check plugin setting (this may go away)
            if ($record->courseid == $this->course) {
                echo "this course needs a full playlist sync according to this plugin<br>";
                $sync_playlist = 1;
            }

            // make sure playlist exists in foundry
            if ($sync_playlist) {
                $this->sync_as_playlist($course, $idmnuber);
            } else {
                $coursemodule =  $DB->get_record_sql("SELECT * from {course_modules} WHERE id = ?", array($record->objectid));
                if (!$coursemodule) {
                    echo "no course module found for record $record->objectid<br>";
                    continue;
                }
                $this->create_content($coursemodule, $idnumber);
            }

            /* remove the content item from foundry if it was deleted from moodle */
            if ($record->action == "deleted") {
                echo "record $record->objectid was deleted<br>";
                $data['objectid'] = $record->objectid;
                $this->delete_data_on_foundry($data);
            }
            echo "DONE PROCESSING MODULE EVENT<br>";
        }
    }

    public function sync_as_content($course, $idnumber) {
        global $DB, $CFG;

        $url = $CFG->wwwroot . "/course/view.php?id=" . $course->id;
        echo "course url: $url<br>";

        // check db for content, then check foundry for content
        $foundrycontent = null;
        $addtofoundry = 0;
        $addtodb = 0;
        $updatedb = 0;
        $dbentry = $DB->get_record_sql("SELECT * from {tool_foundrysync_contents} WHERE courseid = ?", array($course->id));
        if ($dbentry) {
            echo "have local db content for course<br>";
            // get content from foundry using id
            $foundrycontent = $this->lookup_foundry_content_by_id($dbentry->foundryid);
            if ($foundrycontent) {
                echo "found course in foundry<br>";
            } else {
                echo "course not found in foundry<br>";
                // we need to add it to foundry
                // this happens if it gets deleted from foundry
                $addtofoundry = 1;
                $updatedb = 1;
            }
        } else {
            $addtodb = 1;
            echo "no content found in db<br>";
            // we need to add it to db and maybe foundry
        }

        if (!$foundrycontent) {
            $foundrycontent = $this->lookup_foundry_content_by_url($course->fullname, $url);
            $addtofoundry = 0;
        }
        if ($foundrycontent && $addtodb) {
            echo "found course in foundry<br>";
            // add to local db
            $this->store_course_in_db($course->id, $foundrycontent->globalId, $course->fullname, $foundrycontent->id);
            echo "added course to local db<br>";
            // need to update down below
            // TODO check a return code on that call
            $addtodb = 0;
        } else {
            $addtofoundry = 1;
        }
        if ($foundrycontent && $updatedb) {
            $this->update_course_in_db($course->id, $foundrycontent->globalId, $course->fullname, $foundrycontent->id);
            echo "updated course in db<br>";
        }


        // TODO check for course image or thumbnail on video in course summary
        $moodlelogourl = $CFG->wwwroot . "/pix/moodlelogo.svg";
        $courseimage = '';
        $trailerurl = '';
        $summary = '';

        // check for poster and video in course summary
        if (preg_match('/video poster="(.*)" controls/', $course->summary, $match)) {
            preg_match('/http[^"]*/', $match[0], $poster);
            if ($poster[0]) {
                $courseimage = $poster[0];
                echo "course poster image url: $courseimage<br>";
            }
            // strip video from summary
            $summary = strip_tags(preg_replace('/.*video>/', '', $course->summary));
            $summary = preg_replace('/&nbsp;/', ' ', $summary);
        }
        if (preg_match('/src=.*<\/video/', $course->summary, $match)) {
            preg_match('/http[^"]*/', $match[0], $trailer);
            if ($trailer[0]) {
                $trailerurl = $trailer[0];
                echo "course trailer url: $trailerurl<br>";
            }
        }
// TODO add time fields
//"created":"2019-02-08T16:22:29.398942",
//"updated":"2019-02-15T14:23:30.050388",
//public 'timecreated' => string '1543898781' (length=10)
//public 'timemodified' => string '1550249465' (length=10)

        if (!$foundrycontent && $addtofoundry) {
            echo "creating new content item for course<br>";

            if (!$coureseimage) {
                $courseimage = $moodlelogourl;
            }
            if (!$summary) {
                $summary = strip_tags($course->summary);
            }

            //$foundrycontent->id = 0;
            $foundrycontent->name = $course->fullname;
            $foundrycontent->description = $summary;
            $foundrycontent->summary = "";
            // TODO get copyright from moodle
            $foundrycontent->copyright = "Carnegie Mellon University";
            if ($idnumber) {
                $foundrycontent->authorId = $idnumber;
            }
            $foundrycontent->tags = array("moodle");
            $foundrycontent->url = $url;
            $foundrycontent->logoUrl = $courseimage;
            $foundrycontent->hoverUrl = $courseimage;
            $foundrycontent->thumbnailUrl = $courseimage;
            $foundrycontent->trailerUrl = $trailerurl;
            $foundrycontent->settings = "";
            $foundrycontent->order = 0;
            $foundrycontent->publisherId = "";
            $foundrycontent->isDisabled = false;
            $foundrycontent->isRecommended = false;
            $foundrycontent->isFeatured = false;
            $foundrycontent->featuredOrder = 0;
            $foundrycontent->type = "Course";
            $foundrycontent->startDate = "";
            $foundrycontent->startTime = "";
            $foundrycontent->endDate = "";
            $foundrycontent->endTime = "";

            $result = $this->post_content_to_foundry($foundrycontent);
            // TODO check return code
            echo "sent post content to foundry<br>";
            if ($result && $addtodb) {
                // add to local db
                $this->store_course_in_db($course->id, $result->globalId, $course->fullname, $result->id);
                // TODO check return code
                $addtodb = 0;
            }
        } else if ($foundrycontent) {
            /* update foundry if this was the content that updated */
            echo "updating content item for course<br>";

            $oldcontent = $foundrycontent;
            $foundrycontent = null;

            if (!$courseimage) {
                $courseimage = $oldcontent->logoUrl;
            }
            if (!$summary) {
                $summary = strip_tags($course->summary);
            }

            $newtags = array();
            foreach ($oldcontent->tags as $tag) {
                array_push($newtags, $tag->name);
            }

            $foundrycontent->id = $oldcontent->id;
            $foundrycontent->name = $course->fullname;
            $foundrycontent->description = $summary;
            $foundrycontent->summary = $oldcontent->summary;
            $foundrycontent->copyright = $oldcontent->copyright;
            // TODO set to old content
            $foundrycontent->authorId = $idnumber;
            $foundrycontent->tags = $newtags;
            $foundrycontent->url = $url;
            $foundrycontent->logoUrl = $courseimage;
            $foundrycontent->hoverUrl = $courseimage;
            $foundrycontent->thumbnailUrl = $courseimage;
            $foundrycontent->trailerUrl = $trailerurl;
            if ($oldcontent->settings)
                $foundrycontent->settings = $oldcontent->settings;
            else
                $foundrycontent->settings = "";
            $foundrycontent->order = $oldcontent->order;
            if ($oldcontent->publisherId)
                $foundrycontent->publisherId = $oldcontent->publisherId;
            else
                 $foundrycontent->publisherId = "";
            $foundrycontent->isDisabled = $oldcontent->isDisabled;
            $foundrycontent->isRecommended = $oldcontent->isRecommended;
            $foundrycontent->isFeatured = $oldcontent->isFeatured;
            $foundrycontent->featuredOrder = $oldcontent->featuredOrder;
            $foundrycontent->type = $oldcontent->type;
            $foundrycontent->startDate = $oldcontent->startDate;
            $foundrycontent->startTime = $oldcontent->startTime;
            $foundrycontent->endDate = $oldcontent->endDate;
            $foundrycontent->endTime = $oldcontent->endTime;

            $result = $this->update_content_in_foundry($foundrycontent);
            if ($result) {
                echo "content name \"$foundrycontent->name\" was updated on foundry<br>";
            }
        }

        echo "DONE PROCESSING COURSE<br>";
    }

    /* syncs a course as a playlist */
    public function sync_as_playlist($course, $idnumber) {
        global $DB, $CFG;
        $moodlelogourl = $CFG->wwwroot . "/pix/moodlelogo.svg";
        $courseimage = '';
        $trailerurl = '';
        $summary = '';
        if (preg_match('/video poster="(.*)" controls/', $course->summary, $match)) {
            preg_match('/http[^"]*/', $match[0], $poster);
            if ($poster[0]) {
                $courseimage = $poster[0];
                echo "course poster image url $courseimage<br>";
                // strip video from summary
                $summary = strip_tags(preg_replace('/.*video>/', '', $course->summary));
                $summary = preg_replace('/&nbsp;/', ' ', $summary);
            }
        }
        if (preg_match('/src=.*<\/video/', $course->summary, $match)) {
            preg_match('/http[^"]*/', $match[0], $trailer);
            if ($trailer[0]) {
                $trailerurl = $trailer[0];
                echo "course trailer url $trailerurl<br>";
            }
        }

        /* check whether playlist exists for this course */
        /* check our db first, then check foundry */
        /* check db for playlist info */
        $playlist = null;
        $addplaylisttodb = 0;
        list($playlistguid, $playlistid, $dbid) = $this->get_playlist_from_db($course->id);
        if ($playlistid) {
            /* get latest logourl from foundry */
            $playlist = $this->lookup_foundry_playlist_by_id($playlistid);
            $playlistlogourl = $playlist->logoUrl;
        } else {
            /* lookup by name */
            $playlist = $this->lookup_foundry_playlist($course->fullname);
            $playlistlogourl = $playlist->logoUrl;
            $addplaylisttodb = 1;
        }
        if (!$playlistlogourl) {
            $playlistlogourl = $moodlelogourl;
        }

        /* if playlist not set, we need to create it */
        if (!$playlist) {
            echo "we could not find a playlist in foundry<br>";

            if (!$courseimage)
                $courseimage = $playlistlogourl;
            if (!$summary) {
                $summary = strip_tags($course->summary);
            }

            /* build playlist */
            $playlist->name = $course->fullname;
            $playlist->description = $summary;
            $playlist->summary = "";
            $playlist->tags = array("moodle");
            // TODO do we need to gerneate this?
            $playlist->globalId = $this->guidv4();
            if ($course->visible)
                $playlist->isPublic = true;
            else
                $playlist->isPublic = false;
            $playlist->isDefault = false;
            $playlist->logoUrl = $courseimage;
            $playlist->trailerUrl = $trailerurl;
            $playlist->isRecommended = false;
            $playlist->isFeatured = false;
            $playlist->featuredOrder = 0;
            // TODO get copyright from moodle
            $playlist->copyright = "Carnegie Mellon University";
            $playlist->publisherId = "";
            // TODO this field doesnt exist yet in the api
            //if ($idnumber) {
            //    $playlist->authorId = $idnumber;
            //}

            /* post to foundry */
            $result = $this->post_playlist_to_foundry($playlist);
            if (!$result) {
                echo "error posting playlist to foundry<br>";
            }
            $playlist->id = $result->id;
            if ($addplaylisttodb) {
                /* save playlist in our db */
                $this->store_playlist_in_db($course->id, $result->globalId, $course->fullname, $result->id);
            } else {
                // update db
                $this->update_playlist_in_db($course->id, $result->globalId, $course->fullname, $result->id);
            }
        } else {
            /* we are updating the playlist */
            $foundryplaylist = $playlist;
            $playlist = null;

            if (!$trailerurl)
                $trailerurl = $foundryplaylist->trailerUrl;
            if (!$courseimage)
                $courseimage = $foundryplaylist->logoUrl;
            if (!$summary) {
                $summary = strip_tags($course->summary);
            }
            $newtags = array();
            foreach ($foundryplaylist->tags as $tag) {
                array_push($newtags, $tag->name);
            }

            /* update the playlist */
            $playlist->id = $foundryplaylist->id;
            $playlist->name = $course->fullname;
            $playlist->description = $summary;
            $playlist->tags = $newtags;
            $playlist->globalId = $foundryplaylist->globalId;
            if ($course->visible)
                $playlist->isPublic = true;
            else
                $playlist->isPublic = false;
            $playlist->isDefault = $foundryplaylist->isDefault;
            $playlist->logoUrl = $courseimage;
            $playlist->trailerUrl = $trailerurl;
            $playlist->isRecommended = $foundryplaylist->isRecommended;
            $playlist->isFeatured = $foundryplaylist->isFeatured;
            $playlist->featuredOrder = $foundryplaylist->featuredOrder;
            $playlist->copyright = $foundryplaylist->copyright;
            $playlist->publisherId = "";
            // TODO this doesnt exist yet in the api
            //if ($idnumber) {
            //    $playlist->authorId = $foundryplaylist->idnumber;
            //}

            /* post to foundry */
            $result = $this->update_playlist_in_foundry($playlist);
            if (!$result) {
                echo "error putting playlist update to foundry<br>";
            }
            if ($addplaylisttodb) {
                /* save playlist in our db */
                $this->store_playlist_in_db($course->id, $playlist->globalId, $course->fullname, $playlist->id);
            }
        }
        // get all content in course and make sure they exist in foundry
        $coursemodules =  $DB->get_records_sql("SELECT * from {course_modules} WHERE course = ?", array($course->id));
        if (!$coursemodules) {
            echo "no course modules found for course id $record->courseid<br>";
            echo "DONE PROCESSING COURSE<br>";
            return;
        }

        foreach ($coursemodules as $coursemodule) {
            $this->create_content($coursemodule, $idnumber);
        }
        echo "<br>GETTING CONTENT ORDER AND POSTING TO ORGANIZE PLAYLIST<br>";

        $sections = $DB->get_records_sql("SELECT * FROM {course_sections} WHERE course = ?", array($course->id));
        if (!$sections) {
            echo "no course sections found for course $course->id<br>";
            echo "DONE PROCESSING COURSE<br>";
            return;
        }
        $foundrysections = array();

        foreach ($sections as $section) {
            $foundrysection = null;
            $foundrysection->name = null;
            $foundrysection->contentIds = array();
            //do something
            if ($section->name) {
                $foundrysection->name = $section->name;
            } else if ($section->section == 0) {
                $foundrysection->name = "General";
            } else {
                $foundrysection->name = "Section $section->section";
            }
            echo "section name \"$foundrysection->name\"<br>";

            if ($section->sequence) {
                $parts = explode(",", $section->sequence);
                foreach ($parts as $part) {
                    $dbentry = $DB->get_record_sql("SELECT * from {tool_foundrysync_contents} WHERE objectid = ?", array($part));
                    if ($dbentry) {
                        array_push($foundrysection->contentIds, $dbentry->foundryid);
                    } else {
                        echo "could not get local db result for $part<br>";
                    }
                }
            }
            array_push($foundrysections, $foundrysection);
        }
        // post to organize */
        $this->put_sections_to_foundry($playlist->id, $foundrysections);
        echo "DONE PROCESSING MODULE<br>";
    }

    public function get_module_type_name($moduletype) {
        if ($moduletype->name == "url") {
            $moduletypename = "Webpage";
        } else if ($moduletype->name == "page") {
            $moduletypename = "Webpage";
        } else if ($moduletype->name == "feedback") {
            $moduletypename = "Webpage";
        } else if ($moduletype->name == "forum") {
            $moduletypename = "Webpage";
        } else if ($moduletype->name == "quiz") {
            $moduletypename = "Quiz";
        } else if ($moduletype->name == "chat") {
            $moduletypename = "Webpage";
        } else if ($moduletype->name == "hvp") {
            $moduletypename = "Webpage";
        } else if ($moduletype->name == "assign") {
            $moduletypename = "Webpage";
        } else if ($moduletype->name == "vpl") {
            $moduletypename = "Lab";
        } else if ($moduletype->name == "game") {
            $moduletypename = "Game";
        } else {
            $moduletypename = $moduletype->name;
        }
        return $moduletypename;
    }

    /* will create or update content item for a module */
    public function create_content($coursemodule, $idnumber) {
        global $DB, $CFG;

        echo "#################### processing module<br>";

        // get the module type name
        $moduletype = $DB->get_record_sql("SELECT * FROM {modules} WHERE id = ?", array($coursemodule->module));
        if (!$moduletype) {
            echo "no course module type found for course module $coursemodule->id<br>";
            return;
        }

        // get the actual module
        $tablename = $moduletype->name;
        $tablename = "mdl_" . $moduletype->name;
        $content = $DB->get_record_sql("SELECT * from {$tablename} WHERE id = ?", array($coursemodule->instance));
        if (!$content) {
            echo "no content found in $tablename<br>";
            return;
        }
        echo "content name \"$content->name\"<br>";

        // get module info
        // TODO should check if the module png file exists before using it
        $moodlelogourl = $CFG->wwwroot . "/pix/moodlelogo.svg";
        $logourl = $CFG->wwwroot . "/mod/$moduletype->name/pix/icon.svg";

        // for H5P, grab the activity icon
        if ($moduletype->name === "hvp") {
            $info = hvp_get_coursemodule_info($coursemodule);
            $logourl = (string)$info->iconurl;
        }

        $moduletypename = $this->get_module_type_name($moduletype);

        $url = null;
        if ($moduletype->name == "url") {
            $url = $content->externalurl;
        } else {
            $url = $CFG->wwwroot . "/mod/" . $moduletype->name . "/view.php?id=" . $coursemodule->id;
        }
        echo "content url: $url<br>";

        // check db for content, then check foundry for content
        $foundrycontent = null;
        $addtofoundry = 0;
        $addtodb = 0;
        $updatedb = 0;
        $dbentry = $DB->get_record_sql("SELECT * from {tool_foundrysync_contents} WHERE objectid = ?", array($coursemodule->id));
        if ($dbentry) {
            echo "have local db content<br>";
            // get content from foundry using id
            $foundrycontent = $this->lookup_foundry_content_by_id($dbentry->foundryid);
            if ($foundrycontent) {
                echo "found content in foundry<br>";
            } else {
                echo "content not found in foundry<br>";
                // we need to add it to foundry
                // this happens if it gets deleted from foundry
                $addtofoundry = 1;
                $updatedb = 1;
            }
        } else {
            $addtodb = 1;
            echo "no content found in db<br>";
            // we need to add it to db and maybe foundry
        }

        if (!$foundrycontent) {
            $foundrycontent = $this->lookup_foundry_content_by_url($content->name, $url);
        }
        if ($foundrycontent && $addtodb) {
            echo "found content in foundry<br>";
            // add to local db
            $this->store_content_in_db($coursemodule->id, $foundrycontent->globalId, $content->name, $foundrycontent->id);
            echo "added content to local db<br>";
            // need to update down below
            // TODO check a return code on that call
            $addtodb = 0;
        } else {
            $addtofoundry = 1;
        }
        if ($foundrycontent && $updatedb) {
            $this->update_content_in_db($coursemodule->id, $foundrycontent->globalId, $content->name, $foundrycontent->id);
            echo "updated content in db<br>";
        }

        // TODO check whether summary or intro is valid for each module type
        if (!$foundrycontent && $addtofoundry) {
            //$foundrycontent->id = 0;
            $foundrycontent->name = $content->name;
            $foundrycontent->description = strip_tags($content->intro);
            $foundrycontent->summary = "";
            // TODO get copyright from moodle
            $foundrycontent->copyright = "Carnegie Mellon University";
            $foundrycontent->authorId = $idnumber;
            $foundrycontent->tags = array("moodle", $moduletypename);
            $foundrycontent->url = $url;
            $foundrycontent->logoUrl = $logourl;
            $foundrycontent->hoverUrl = $logourl;
            $foundrycontent->thumbnailUrl = $logourl;
            $foundrycontent->settings = "";
            $foundrycontent->order = 0;
            $foundrycontent->publisherId = "";
            $foundrycontent->isDisabled = false;
            $foundrycontent->isRecommended = false;
            $foundrycontent->isFeatured = false;
            $foundrycontent->featuredOrder = 0;
            $foundrycontent->type = $moduletypename;
            $foundrycontent->startDate = "";
            $foundrycontent->startTime = "";
            $foundrycontent->endDate = "";
            $foundrycontent->endTime = "";

            $result = $this->post_content_to_foundry($foundrycontent);
            echo "sent post content to foundry<br>";
            if ($result && $addtodb) {
                // add to local db
                $this->store_content_in_db($coursemodule->id, $result->globalId, $content->name, $result->id);
                // TODO check return code
                $addtodb = 0;
            }
        } else if ($foundrycontent) {
            // if it exists, we should update it
            echo "content exists on foundry, we need to update it<br>";

            $oldcontent = $foundrycontent;
            $foundrycontent = null;
            $newtags = array();
            foreach ($oldcontent->tags as $tag) {
                array_push($newtags, $tag->name);
            }

            $foundrycontent->id = $oldcontent->id;
            $foundrycontent->name = $content->name;
            $foundrycontent->description = strip_tags($content->intro);
            $foundrycontent->summary = $oldcontent->summary;
            $foundrycontent->copyright = $oldcontent->copyright;
            $foundrycontent->authorId = $oldcontent->authorId;
            $foundrycontent->tags = $newtags;
            $foundrycontent->url = $url;
            $foundrycontent->logoUrl = $oldcontent->logoUrl;
            $foundrycontent->hoverUrl = $oldcontent->hoverUrl;
            $foundrycontent->thumbnailUrl = $oldcontent->thumbnailUrl;
            $foundrycontent->settings = $oldcontent->settings;
            $foundrycontent->order = $oldcontent->order;
            $foundrycontent->publisherId = $oldcontent->publisherId;
            $foundrycontent->isDisabled = $oldcontent->isDisabled;
            $foundrycontent->isRecommended = $oldcontent->isRecommended;
            $foundrycontent->isFeatured = $oldcontent->isFeatured;
            $foundrycontent->featuredOrder = $oldcontent->featuredOrder;
            $foundrycontent->type = $oldcontent->type;
            $foundrycontent->startDate = $oldcontent->startDate;
            $foundrycontent->startTime = $oldcontent->startTime;
            $foundrycontent->endDate = $oldcontent->endDate;
            $foundrycontent->endTime = $oldcontent->endTime;

            $result = $this->update_content_in_foundry($foundrycontent);
            if ($result) {
                echo "content name \"$foundrycontent->name\" was updated on skecth<br>";
            }
        }
    }
}

