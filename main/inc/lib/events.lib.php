<?php
/* See license terms in /license.txt */

//use Chamilo\UserBundle\Entity\User;
use ChamiloSession as Session;
use Chamilo\CoreBundle\Component\Utils\ChamiloApi;

/**
 * Class Event
 * Functions of this library are used to record informations when some kind
 * of event occur. Each event has his own types of informations then each event
 * use its own function.
 */
class Event
{
    /**
     * @author Sebastien Piraux <piraux_seb@hotmail.com>
     * @desc Record information for open event (when homepage is opened)
     */
    public static function open()
    {
        global $_configuration;
        global $TABLETRACK_OPEN;

        // @getHostByAddr($_SERVER['REMOTE_ADDR']) : will provide host and country information
        // $_SERVER['HTTP_USER_AGENT'] :  will provide browser and os information
        // $_SERVER['HTTP_REFERER'] : provide information about refering url
        if (isset($_SERVER['HTT_REFERER'])) {
            $referer = Database::escape_string($_SERVER['HTTP_REFERER']);
        } else {
            $referer = '';
        }
        // record informations only if user comes from another site
        //if(!eregi($_configuration['root_web'],$referer))
        $pos = strpos($referer, $_configuration['root_web']);
        if ($pos === false && $referer != '') {
            $ip = api_get_real_ip();
            $remhost = @ getHostByAddr($ip);
            if ($remhost == $ip) {
                $remhost = "Unknown";
            } // don't change this
            $reallyNow = api_get_utc_datetime();
            $params = [
                'open_remote_host' => $remhost,
                'open_agent' => $_SERVER['HTTP_USER_AGENT'],
                'open_referer' => $referer,
                'open_date' => $reallyNow,
            ];
            Database::insert($TABLETRACK_OPEN, $params);
        }

        return 1;
    }

    /**
     * @author Sebastien Piraux <piraux_seb@hotmail.com> old code
     * @author Julio Montoya
     * @param int $userId
     * @return bool
     * @desc Record information for login event when an user identifies himself with username & password
     */
    public static function eventLogin($userId)
    {
        $userInfo = api_get_user_info($userId);
        $userId = intval($userId);

        if (empty($userInfo)) {
            return false;
        }

        $table = Database::get_main_table(TABLE_STATISTIC_TRACK_E_LOGIN);
        $reallyNow = api_get_utc_datetime();

        $sql = "INSERT INTO ".$table." (login_user_id, user_ip, login_date, logout_date) VALUES
                    ('".$userId."',
                    '".Database::escape_string(api_get_real_ip())."',
                    '".$reallyNow."',
                    '".$reallyNow."'
                    )";
        Database::query($sql);

        // Auto subscribe
        $user_status = $userInfo['status'] == SESSIONADMIN ? 'sessionadmin' : $userInfo['status'] == COURSEMANAGER ? 'teacher' : $userInfo['status'] == DRH ? 'drh' : 'student';
        $autoSubscribe = api_get_setting($user_status.'_autosubscribe');
        if ($autoSubscribe) {
            $autoSubscribe = explode('|', $autoSubscribe);
            foreach ($autoSubscribe as $code) {
                if (CourseManager::course_exists($code)) {
                    CourseManager::subscribe_user($userId, $code);
                }
            }
        }
        return true;
    }

    /**
     * @author Sebastien Piraux <piraux_seb@hotmail.com>
     * @desc Record information for access event for courses
     */
    public static function accessCourse()
    {
        $TABLETRACK_ACCESS = Database::get_main_table(TABLE_STATISTIC_TRACK_E_ACCESS);
        //for "what's new" notification
        $TABLETRACK_LASTACCESS = Database::get_main_table(TABLE_STATISTIC_TRACK_E_LASTACCESS);

        $id_session = api_get_session_id();
        $now = api_get_utc_datetime();
        $courseId = api_get_course_int_id();
        $user_id = api_get_user_id();
        $ip = api_get_real_ip();

        if ($user_id) {
            $user_id = "'".$user_id."'";
        } else {
            $user_id = "0"; // no one
        }
        $sql = "INSERT INTO ".$TABLETRACK_ACCESS."  (user_ip, access_user_id, c_id, access_date, access_session_id) 
                VALUES ('".$ip."', ".$user_id.", '".$courseId."', '".$now."','".$id_session."')";

        Database::query($sql);

        // added for "what's new" notification
        $sql = "UPDATE $TABLETRACK_LASTACCESS  SET access_date = '$now'
                WHERE 
                  access_user_id = $user_id AND
                  c_id = '$courseId' AND 
                  access_tool IS NULL AND 
                  access_session_id=".$id_session;
        $result = Database::query($sql);

        if (Database::affected_rows($result) == 0) {
            $sql = "INSERT INTO $TABLETRACK_LASTACCESS (access_user_id, c_id, access_date, access_session_id)
                    VALUES (".$user_id.", '".$courseId."', '$now', '".$id_session."')";
            Database::query($sql);
        }

        return 1;
    }

    /**
     * @param string $tool name of the tool (name in mainDb.accueil table)
     * @author Sebastien Piraux <piraux_seb@hotmail.com>
     * @desc Record information for access event for tools
     *
     *  $tool can take this values :
     *  Links, Calendar, Document, Announcements,
     *  Group, Video, Works, Users, Exercises, Course Desc
     *  ...
     *  Values can be added if new modules are created (15char max)
     *  I encourage to use $nameTool as $tool when calling this function
     *
     * Functionality for "what's new" notification is added by Toon Van Hoecke
     * @return bool
     */
    public static function event_access_tool($tool)
    {
        $tool = Database::escape_string($tool);

        if (empty($tool)) {
            return false;
        }

        $_course = api_get_course_info();
        $courseId = api_get_course_int_id();
        $sessionId = api_get_session_id();
        $reallyNow = api_get_utc_datetime();
        $user_id = api_get_user_id();

        if (empty($_course)) {
            return false;
        }

        $tableAccess = Database::get_main_table(TABLE_STATISTIC_TRACK_E_ACCESS);
        //for "what's new" notification
        $tableLastAccess = Database::get_main_table(TABLE_STATISTIC_TRACK_E_LASTACCESS);

        // record information
        // only if user comes from the course $_cid
        //if( eregi($_configuration['root_web'].$_cid,$_SERVER['HTTP_REFERER'] ) )
        //$pos = strpos($_SERVER['HTTP_REFERER'],$_configuration['root_web'].$_cid);
        $coursePath = isset($_course['path']) ? $_course['path'] : null;

        $pos = isset($_SERVER['HTTP_REFERER']) ? strpos(strtolower($_SERVER['HTTP_REFERER']), strtolower(api_get_path(WEB_COURSE_PATH).$coursePath)) : false;
        // added for "what's new" notification
        $pos2 = isset($_SERVER['HTTP_REFERER']) ? strpos(strtolower($_SERVER['HTTP_REFERER']), strtolower(api_get_path(WEB_PATH)."index")) : false;

        // end "what's new" notification
        if ($pos !== false || $pos2 !== false) {
            $params = [
                'access_user_id' => $user_id,
                'c_id' => $courseId,
                'access_tool' => $tool,
                'access_date' => $reallyNow,
                'access_session_id' => $sessionId,
                'user_ip' => api_get_real_ip()
            ];
            Database::insert($tableAccess, $params);
        }

        // "what's new" notification
        $sql = "UPDATE $tableLastAccess
                SET access_date = '$reallyNow'
                WHERE 
                    access_user_id = ".$user_id." AND 
                    c_id = '".$courseId."' AND 
                    access_tool = '".$tool."' AND 
                    access_session_id=".$sessionId;
        $result = Database::query($sql);

        if (Database::affected_rows($result) == 0) {
            $params = [
                'access_user_id' => $user_id,
                'c_id' => $courseId,
                'access_tool' => $tool,
                'access_date' => $reallyNow,
                'access_session_id' => $sessionId
            ];
            Database::insert($tableLastAccess, $params);
        }
        return true;
    }

    /**
     * @param string $doc_url
     * @author Sebastien Piraux <piraux_seb@hotmail.com>
     * @desc Record information for download event
     * (when an user click to d/l a document)
     * it will be used in a redirection page
     * bug fixed: Roan Embrechts
     * Roan:
     * The user id is put in single quotes,
     * (why? perhaps to prevent sql insertion hacks?)
     * and later again.
     * Doing this twice causes an error, I remove one of them.
     * @return int
     */
    public static function event_download($doc_url)
    {
        $table = Database::get_main_table(TABLE_STATISTIC_TRACK_E_DOWNLOADS);
        $doc_url = Database::escape_string($doc_url);

        $reallyNow = api_get_utc_datetime();
        $user_id = "'".api_get_user_id()."'";
        $_cid = api_get_course_int_id();

        $sql = "INSERT INTO $table (
                 down_user_id,
                 c_id,
                 down_doc_path,
                 down_date,
                 down_session_id
                )
                VALUES (
                 ".$user_id.",
                 '".$_cid."',
                 '".$doc_url."',
                 '".$reallyNow."',
                 '".api_get_session_id()."'
                )";
        Database::query($sql);

        return 1;
    }

    /**
     * @param int $doc_id of document (id in mainDb.document table)
     * @author Sebastien Piraux <piraux_seb@hotmail.com>
     * @desc Record information for upload event
     * used in the works tool to record informations when
     * an user upload 1 work
     * @return int
     */
    public static function event_upload($doc_id)
    {
        $table = Database::get_main_table(TABLE_STATISTIC_TRACK_E_UPLOADS);
        $courseId = api_get_course_int_id();
        $reallyNow = api_get_utc_datetime();
        $user_id = api_get_user_id();
        $doc_id = intval($doc_id);

        $sql = "INSERT INTO $table
                ( upload_user_id,
                  c_id,
                  upload_cours_id,
                  upload_work_id,
                  upload_date,
                  upload_session_id
                )
                VALUES (
                 ".$user_id.",
                 '".$courseId."',
                 '',
                 '".$doc_id."',
                 '".$reallyNow."',
                 '".api_get_session_id()."'
                )";
        Database::query($sql);

        return 1;
    }

    /**
     * @param int $link_id (id in coursDb liens table)
     * @author Sebastien Piraux <piraux_seb@hotmail.com>
     * @desc Record information for link event (when an user click on an added link)
     * it will be used in a redirection page
     * @return int
     */
    public static function event_link($link_id)
    {
        $table = Database::get_main_table(TABLE_STATISTIC_TRACK_E_LINKS);
        $reallyNow = api_get_utc_datetime();
        $user_id = api_get_user_id();
        $sql = "INSERT INTO ".$table."
                    ( links_user_id,
                     c_id,
                     links_link_id,
                     links_date,
                     links_session_id
                    ) VALUES (
                     ".$user_id.",
                     '".api_get_course_int_id()."',
                     '".Database::escape_string($link_id)."',
                     '".$reallyNow."',
                     '".api_get_session_id()."'
                    )";
        Database::query($sql);
        return 1;
    }

    /**
     * Update the TRACK_E_EXERCICES exercises
     *
     * @param   int     exeid id of the attempt
     * @param   int     exo_id    exercise id
     * @param   mixed   result    score
     * @param   int     weighting ( higher score )
     * @param   int     duration ( duration of the attempt in seconds )
     * @param   int     session_id
     * @param   int     learnpath_id (id of the learnpath)
     * @param   int     learnpath_item_id (id of the learnpath_item)
     * @return bool
     *
     * @author Sebastien Piraux <piraux_seb@hotmail.com>
     * @author Julio Montoya Armas <gugli100@gmail.com> Reworked 2010
     * @desc Record result of user when an exercise was done
     */
    public static function updateEventExercise(
        $exeid,
        $exo_id,
        $score,
        $weighting,
        $session_id,
        $learnpath_id = 0,
        $learnpath_item_id = 0,
        $learnpath_item_view_id = 0,
        $duration = 0,
        $question_list = [],
        $status = '',
        $remind_list = [],
        $end_date = null
    ) {
        if ($exeid != '') {
            /*
             * Code commented due BT#8423 do not change the score to 0.
             *
             * Validation in case of fraud with actived control time
            if (!ExerciseLib::exercise_time_control_is_valid($exo_id, $learnpath_id, $learnpath_item_id)) {
                $score = 0;
            }
            */
            if (!isset($status) || empty($status)) {
                $status = '';
            } else {
                $status = Database::escape_string($status);
            }

            $table = Database::get_main_table(TABLE_STATISTIC_TRACK_E_EXERCISES);

            if (!empty($question_list)) {
                $question_list = array_map('intval', $question_list);
            }

            if (!empty($remind_list)) {
                $remind_list = array_map('intval', $remind_list);
                $remind_list = array_filter($remind_list);
                $remind_list = implode(",", $remind_list);
            } else {
                $remind_list = '';
            }

            if (empty($end_date)) {
                $end_date = api_get_utc_datetime();
            }

            $sql = "UPDATE $table SET
        		   exe_exo_id = '".Database::escape_string($exo_id)."',
        		   exe_result = '".Database::escape_string($score)."',
        		   exe_weighting = '".Database::escape_string($weighting)."',
        		   session_id = '".Database::escape_string($session_id)."',
        		   orig_lp_id = '".Database::escape_string($learnpath_id)."',
        		   orig_lp_item_id = '".Database::escape_string($learnpath_item_id)."',
                   orig_lp_item_view_id = '".Database::escape_string($learnpath_item_view_id)."',
        		   exe_duration = '".Database::escape_string($duration)."',
        		   exe_date = '".$end_date."',
        		   status = '".$status."',
        		   questions_to_check = '".$remind_list."',
        		   data_tracking = '".implode(',', $question_list)."',
                   user_ip = '" . Database::escape_string(api_get_real_ip())."'
        		 WHERE exe_id = '".Database::escape_string($exeid)."'";
            Database::query($sql);

            //Deleting control time session track
            //ExerciseLib::exercise_time_control_delete($exo_id);
            return true;
        } else {
            return false;
        }
    }

    /**
     * Record an event for this attempt at answering an exercise
     * @param    float    Score achieved
     * @param    string    Answer given
     * @param    integer    Question ID
     * @param    integer Exercise attempt ID a.k.a exe_id (from track_e_exercise)
     * @param    integer    Position
     * @param    integer Exercise ID (from c_quiz)
     * @param    bool update results?
     * @param    $fileName string  Filename (for audio answers - using nanogong)
     * @param    int User ID The user who's going to get this score. Default value of null means "get from context".
     * @param    int Course ID (from the "id" column of course table). Default value of null means "get from context".
     * @param    int Session ID (from the session table). Default value of null means "get from context".
     * @param    int Learnpath ID (from c_lp table). Default value of null means "get from context".
     * @param    int Learnpath item ID (from the c_lp_item table). Default value of null means "get from context".
     * @return    boolean    Result of the insert query
     */
    public static function saveQuestionAttempt(
        $score,
        $answer,
        $question_id,
        $exe_id,
        $position,
        $exercise_id = 0,
        $updateResults = false,
        $fileName = null,
        $user_id = null,
        $course_id = null,
        $session_id = null,
        $learnpath_id = null,
        $learnpath_item_id = null
    ) {
        global $debug;
        $question_id = Database::escape_string($question_id);
        $exe_id = Database::escape_string($exe_id);
        $position = Database::escape_string($position);
        $now = api_get_utc_datetime();

        // check user_id or get from context
        if (empty($user_id)) {
            $user_id = api_get_user_id();
            // anonymous
            if (empty($user_id)) {
                $user_id = api_get_anonymous_id();
            }
        }
        // check course_id or get from context
        if (empty($course_id) or intval($course_id) != $course_id) {
            $course_id = api_get_course_int_id();
        }
        // check session_id or get from context
        if (empty($session_id)) {
            $session_id = api_get_session_id();
        }
        // check learnpath_id or get from context
        if (empty($learnpath_id)) {
            global $learnpath_id;
        }
        // check learnpath_item_id or get from context
        if (empty($learnpath_item_id)) {
            global $learnpath_item_id;
        }

        $TBL_TRACK_ATTEMPT = Database::get_main_table(TABLE_STATISTIC_TRACK_E_ATTEMPT);

        if ($debug) {
            error_log("----- entering saveQuestionAttempt() function ------");
            error_log("answer: $answer");
            error_log("score: $score");
            error_log("question_id : $question_id");
            error_log("position: $position");
        }

        //Validation in case of fraud with active control time
        if (!ExerciseLib::exercise_time_control_is_valid($exercise_id, $learnpath_id, $learnpath_item_id)) {
            if ($debug) {
                error_log("exercise_time_control_is_valid is false");
            }
            $score = 0;
            $answer = 0;
        }

        $session_id = api_get_session_id();

        if (!empty($question_id) && !empty($exe_id) && !empty($user_id)) {
            if (is_null($answer)) {
                $answer = '';
            }
            $attempt = [
                'user_id' => $user_id,
                'question_id' => $question_id,
                'answer' => $answer,
                'marks' => $score,
                'c_id' => $course_id,
                'session_id' => $session_id,
                'position' => $position,
                'tms' => $now,
                'filename' => !empty($fileName) ? basename($fileName) : $fileName,
                'teacher_comment' => ''
            ];

            // Check if attempt exists.
            $sql = "SELECT exe_id FROM $TBL_TRACK_ATTEMPT
                    WHERE
                        c_id = $course_id AND
                        session_id = $session_id AND
                        exe_id = $exe_id AND
                        user_id = $user_id AND
                        question_id = $question_id AND
                        position = $position";
            $result = Database::query($sql);
            if (Database::num_rows($result)) {
                if ($debug) {
                    error_log("Attempt already exist: exe_id: $exe_id - user_id:$user_id - question_id:$question_id");
                }
                if ($updateResults == false) {
                    //The attempt already exist do not update use  update_event_exercise() instead
                    return false;
                }
            } else {
                $attempt['exe_id'] = $exe_id;
            }

            if ($debug) {
                error_log("updateResults : $updateResults");
                error_log("Saving question attempt: ");
                error_log($sql);
            }

            $recording_table = Database::get_main_table(TABLE_STATISTIC_TRACK_E_ATTEMPT_RECORDING);

            if ($updateResults == false) {
                $attempt_id = Database::insert($TBL_TRACK_ATTEMPT, $attempt);

                if ($debug) {
                    error_log("Insert attempt with id #$attempt_id");
                }

                if (defined('ENABLED_LIVE_EXERCISE_TRACKING')) {
                    if ($debug) {
                        error_log("Saving e attempt recording ");
                    }
                    $attempt_recording = [
                        'exe_id' => $attempt_id,
                        'question_id' => $question_id,
                        'marks' => $score,
                        'insert_date' => $now,
                        'author' => '',
                        'session_id' => $session_id,
                    ];
                    Database::insert($recording_table, $attempt_recording);
                }
            } else {
                Database::update(
                    $TBL_TRACK_ATTEMPT,
                    $attempt,
                    [
                        'exe_id = ? AND question_id = ? AND user_id = ? ' => [
                            $exe_id,
                            $question_id,
                            $user_id
                        ]
                    ]
                );

                if (defined('ENABLED_LIVE_EXERCISE_TRACKING')) {
                    $attempt_recording = [
                        'exe_id' => $exe_id,
                        'question_id' => $question_id,
                        'marks' => $score,
                        'insert_date' => $now,
                        'author' => '',
                        'session_id' => $session_id,
                    ];

                    Database::update(
                        $recording_table,
                        $attempt_recording,
                        [
                            'exe_id = ? AND question_id = ? AND session_id = ? ' => [
                                $exe_id,
                                $question_id,
                                $session_id
                            ]
                        ]
                    );
                }
                $attempt_id = $exe_id;
            }

            return $attempt_id;
        } else {
            return false;
        }
    }

    /**
     * Record an hotspot spot for this attempt at answering an hotspot question
     * @param int $exeId
     * @param int $questionId Question ID
     * @param int $answerId Answer ID
     * @param int $correct
     * @param string $coords Coordinates of this point (e.g. 123;324)
     * @param bool $updateResults
     * @param int $exerciseId
     *
     * @return bool Result of the insert query
     * @uses Course code and user_id from global scope $_cid and $_user
     */
    public static function saveExerciseAttemptHotspot(
        $exeId,
        $questionId,
        $answerId,
        $correct,
        $coords,
        $updateResults = false,
        $exerciseId = 0
    ) {
        global $safe_lp_id, $safe_lp_item_id;

        if ($updateResults == false) {
            // Validation in case of fraud with activated control time
            if (!ExerciseLib::exercise_time_control_is_valid($exerciseId, $safe_lp_id, $safe_lp_item_id)) {
                $correct = 0;
            }
        }

        if (empty($exeId)) {
            return false;
        }

        $table = Database::get_main_table(TABLE_STATISTIC_TRACK_E_HOTSPOT);
        if ($updateResults) {
            $params = [
                'hotspot_correct' => $correct,
                'hotspot_coordinate' => $coords
            ];
            Database::update(
                $table,
                $params,
                [
                    'hotspot_user_id = ? AND hotspot_exe_id = ? AND hotspot_question_id = ? AND hotspot_answer_id = ? ' => [
                        api_get_user_id(),
                        $exeId,
                        $questionId,
                        $answerId
                    ]
                ]
            );
        } else {
            return Database::insert(
                $table,
                [
                    'hotspot_course_code' => api_get_course_id(),
                    'hotspot_user_id' => api_get_user_id(),
                    'c_id' => api_get_course_int_id(),
                    'hotspot_exe_id' => $exeId,
                    'hotspot_question_id' => $questionId,
                    'hotspot_answer_id' => $answerId,
                    'hotspot_correct' => $correct,
                    'hotspot_coordinate' => $coords
                ]
            );
        }
    }

    /**
     * Records information for common (or admin) events (in the track_e_default table)
     * @author Yannick Warnier <yannick.warnier@beeznest.com>
     * @param   string  $event_type Type of event
     * @param   string  $event_value_type Type of value
     * @param   string  $event_value Value
     * @param   string  $datetime Datetime (UTC) (defaults to null)
     * @param   int     $user_id User ID (defaults to null)
     * @param   int $course_id Course ID (defaults to null)
     * @param   int $sessionId Session ID
     * @return  bool
     * @assert ('','','') === false
     */
    public static function addEvent(
        $event_type,
        $event_value_type,
        $event_value,
        $datetime = null,
        $user_id = null,
        $course_id = null,
        $sessionId = 0
    ) {
        $table = Database::get_main_table(TABLE_STATISTIC_TRACK_E_DEFAULT);

        if (empty($event_type)) {
            return false;
        }
        $event_type = Database::escape_string($event_type);
        $event_value_type = Database::escape_string($event_value_type);
        if (!empty($course_id)) {
            $course_id = intval($course_id);
        } else {
            $course_id = api_get_course_int_id();
        }
        if (!empty($sessionId)) {
            $sessionId = intval($sessionId);
        } else {
            $sessionId = api_get_session_id();
        }

        //Clean the user_info
        if ($event_value_type == LOG_USER_OBJECT) {
            if (is_array($event_value)) {
                unset($event_value['complete_name']);
                unset($event_value['complete_name_with_username']);
                unset($event_value['firstName']);
                unset($event_value['lastName']);
                unset($event_value['avatar_small']);
                unset($event_value['avatar']);
                unset($event_value['mail']);
                unset($event_value['password']);
                unset($event_value['last_login']);
                unset($event_value['picture_uri']);
                $event_value = serialize($event_value);
            }
        }
        // If event is an array then the $event_value_type should finish with
        // the suffix _array for example LOG_WORK_DATA = work_data_array
        if (is_array($event_value)) {
            $event_value = serialize($event_value);
        }

        $event_value = Database::escape_string($event_value);
        $sessionId = empty($sessionId) ? api_get_session_id() : intval($sessionId);

        if (!isset($datetime)) {
            $datetime = api_get_utc_datetime();
        }

        $datetime = Database::escape_string($datetime);

        if (!isset($user_id)) {
            $user_id = api_get_user_id();
        }

        $params = [
            'default_user_id' => $user_id,
            'c_id' => $course_id,
            'default_date' => $datetime,
            'default_event_type' => $event_type,
            'default_value_type' => $event_value_type,
            'default_value' => $event_value,
            'session_id' => $sessionId
        ];
        Database::insert($table, $params);

        return true;
    }


    /**
     * Gets the last attempt of an exercise based in the exe_id
     * @param int $exe_id
     * @return mixed
     */
    public static function getLastAttemptDateOfExercise($exe_id)
    {
        $exe_id = intval($exe_id);
        $track_attempts = Database::get_main_table(TABLE_STATISTIC_TRACK_E_ATTEMPT);
        $sql = 'SELECT max(tms) as last_attempt_date
                FROM '.$track_attempts.'
                WHERE exe_id='.$exe_id;
        $rs_last_attempt = Database::query($sql);
        $row_last_attempt = Database::fetch_array($rs_last_attempt);
        $date = $row_last_attempt['last_attempt_date']; //Get the date of last attempt

        return $date;
    }

    /**
     * Gets the last attempt of an exercise based in the exe_id
     * @param int $exe_id
     * @return mixed
     */
    public static function getLatestQuestionIdFromAttempt($exe_id)
    {
        $exe_id = intval($exe_id);
        $track_attempts = Database::get_main_table(TABLE_STATISTIC_TRACK_E_ATTEMPT);
        $sql = 'SELECT question_id FROM '.$track_attempts.'
                WHERE exe_id='.$exe_id.'
                ORDER BY tms DESC
                LIMIT 1';
        $result = Database::query($sql);
        if (Database::num_rows($result)) {
            $row = Database::fetch_array($result);
            return $row['question_id'];
        } else {
            return false;
        }
    }

    /**
     * Gets how many attempts exists by user, exercise, learning path
     * @param   int user id
     * @param   int exercise id
     * @param   int lp id
     * @param   int lp item id
     * @param   int lp item view id
     * @return int
     */
    public static function get_attempt_count(
        $user_id,
        $exerciseId,
        $lp_id,
        $lp_item_id,
        $lp_item_view_id
    ) {
        $stat_table = Database::get_main_table(TABLE_STATISTIC_TRACK_E_EXERCISES);
        $user_id = intval($user_id);
        $exerciseId = intval($exerciseId);
        $lp_id = intval($lp_id);
        $lp_item_id = intval($lp_item_id);
        $lp_item_view_id = intval($lp_item_view_id);

        $sql = "SELECT count(*) as count
                FROM $stat_table
                WHERE
                    exe_exo_id = $exerciseId AND
                    exe_user_id = $user_id AND
                    status != 'incomplete' AND
                    orig_lp_id = $lp_id AND
                    orig_lp_item_id = $lp_item_id AND
                    orig_lp_item_view_id = $lp_item_view_id AND
                    c_id = '".api_get_course_int_id()."' AND
                    session_id = '".api_get_session_id()."'";

        $query = Database::query($sql);
        if (Database::num_rows($query) > 0) {
            $attempt = Database::fetch_array($query, 'ASSOC');
            return $attempt['count'];
        } else {
            return 0;
        }
    }

    /**
     * @param $user_id
     * @param $exerciseId
     * @param $lp_id
     * @param $lp_item_id
     * @return int
     */
    public static function get_attempt_count_not_finished(
        $user_id,
        $exerciseId,
        $lp_id,
        $lp_item_id
    ) {
        $stat_table = Database::get_main_table(TABLE_STATISTIC_TRACK_E_EXERCISES);
        $user_id = intval($user_id);
        $exerciseId = intval($exerciseId);
        $lp_id = intval($lp_id);
        $lp_item_id = intval($lp_item_id);
        //$lp_item_view_id = intval($lp_item_view_id);

        $sql = "SELECT count(*) as count
                FROM $stat_table
                WHERE
                    exe_exo_id 			= $exerciseId AND
                    exe_user_id 		= $user_id AND
                    status 				!= 'incomplete' AND
                    orig_lp_id 			= $lp_id AND
                    orig_lp_item_id 	= $lp_item_id AND
                    c_id = '".api_get_course_int_id()."' AND
                    session_id = '".api_get_session_id()."'";

        $query = Database::query($sql);
        if (Database::num_rows($query) > 0) {
            $attempt = Database::fetch_array($query, 'ASSOC');
            return $attempt['count'];
        } else {
            return 0;
        }
    }

    /**
     * @param int $user_id
     * @param int $lp_id
     * @param array $course
     * @param int $session_id
     */
    public static function delete_student_lp_events(
        $user_id,
        $lp_id,
        $course,
        $session_id
    ) {
        $lp_view_table = Database::get_course_table(TABLE_LP_VIEW);
        $lp_item_view_table = Database::get_course_table(TABLE_LP_ITEM_VIEW);
        $lpInteraction = Database::get_course_table(TABLE_LP_IV_INTERACTION);
        $lpObjective = Database::get_course_table(TABLE_LP_IV_OBJECTIVE);

        $course_id = $course['real_id'];

        if (empty($course_id)) {
            $course_id = api_get_course_int_id();
        }

        $track_e_exercises = Database::get_main_table(
            TABLE_STATISTIC_TRACK_E_EXERCISES
        );
        $track_attempts = Database::get_main_table(
            TABLE_STATISTIC_TRACK_E_ATTEMPT
        );
        $recording_table = Database::get_main_table(
            TABLE_STATISTIC_TRACK_E_ATTEMPT_RECORDING
        );

        $user_id = intval($user_id);
        $lp_id = intval($lp_id);
        $session_id = intval($session_id);

        //Make sure we have the exact lp_view_id
        $sql = "SELECT id FROM $lp_view_table
                WHERE
                    c_id = $course_id AND
                    user_id = $user_id AND
                    lp_id = $lp_id AND
                    session_id = $session_id ";
        $result = Database::query($sql);

        if (Database::num_rows($result)) {
            $view = Database::fetch_array($result, 'ASSOC');
            $lp_view_id = $view['id'];

            $sql = "DELETE FROM $lp_item_view_table
                    WHERE c_id = $course_id AND lp_view_id = $lp_view_id ";
            Database::query($sql);

            $sql = "DELETE FROM $lpInteraction
                    WHERE c_id = $course_id AND lp_iv_id = $lp_view_id ";
            Database::query($sql);

            $sql = "DELETE FROM $lpObjective
                    WHERE c_id = $course_id AND lp_iv_id = $lp_view_id ";
            Database::query($sql);
        }

        $sql = "DELETE FROM $lp_view_table
                WHERE
                    c_id = $course_id AND
                    user_id = $user_id AND
                    lp_id= $lp_id AND
                    session_id = $session_id
            ";
        Database::query($sql);

        $sql = "SELECT exe_id FROM $track_e_exercises
                WHERE   
                    exe_user_id = $user_id AND
                    session_id = $session_id AND
                    c_id = $course_id AND
                    orig_lp_id = $lp_id";
        $result = Database::query($sql);
        $exe_list = [];
        while ($row = Database::fetch_array($result, 'ASSOC')) {
            $exe_list[] = $row['exe_id'];
        }

        if (!empty($exe_list) && is_array($exe_list) && count($exe_list) > 0) {
            $sql = "DELETE FROM $track_e_exercises
                WHERE exe_id IN (".implode(',', $exe_list).")";
            Database::query($sql);

            $sql = "DELETE FROM $track_attempts
                WHERE exe_id IN (".implode(',', $exe_list).")";
            Database::query($sql);

            $sql = "DELETE FROM $recording_table
                WHERE exe_id IN (".implode(',', $exe_list).")";
            Database::query($sql);
        }

        self::addEvent(
            LOG_LP_ATTEMPT_DELETE,
            LOG_LP_ID,
            $lp_id,
            null,
            null,
            $course_id,
            $session_id
        );
    }

    /**
     * Delete all exercise attempts (included in LP or not)
     *
     * @param int user id
     * @param int exercise id
     * @param int $course_id
     * @param int session id
     */
    public static function delete_all_incomplete_attempts(
        $user_id,
        $exercise_id,
        $course_id,
        $session_id = 0
    ) {
        $track_e_exercises = Database::get_main_table(TABLE_STATISTIC_TRACK_E_EXERCISES);
        $user_id = intval($user_id);
        $exercise_id = intval($exercise_id);
        $course_id = intval($course_id);
        $session_id = intval($session_id);
        if (!empty($user_id) && !empty($exercise_id) && !empty($course_code)) {
            $sql = "DELETE FROM $track_e_exercises
                    WHERE
                        exe_user_id = $user_id AND
                        exe_exo_id = $exercise_id AND
                        c_id = '$course_id' AND
                        session_id = $session_id AND
                        status = 'incomplete' ";
            Database::query($sql);
            self::addEvent(
                LOG_EXERCISE_RESULT_DELETE,
                LOG_EXERCISE_AND_USER_ID,
                $exercise_id.'-'.$user_id,
                null,
                null,
                $course_id,
                $session_id
            );
        }
    }

    /**
     * Gets all exercise results (NO Exercises in LPs ) from a given exercise id, course, session
     * @param   int     exercise id
     * @param   int $courseId
     * @param   int     session id
     * @return  array   with the results
     *
     */
    public static function get_all_exercise_results(
        $exercise_id,
        $courseId,
        $session_id = 0,
        $load_question_list = true,
        $user_id = null
    ) {
        $TABLETRACK_EXERCICES = Database::get_main_table(TABLE_STATISTIC_TRACK_E_EXERCISES);
        $TBL_TRACK_ATTEMPT = Database::get_main_table(TABLE_STATISTIC_TRACK_E_ATTEMPT);
        $courseId = intval($courseId);
        $exercise_id = intval($exercise_id);
        $session_id = intval($session_id);

        $user_condition = null;
        if (!empty($user_id)) {
            $user_id = intval($user_id);
            $user_condition = "AND exe_user_id = $user_id ";
        }
        $sql = "SELECT * FROM $TABLETRACK_EXERCICES
                WHERE
                    status = ''  AND
                    c_id = '$courseId' AND
                    exe_exo_id = '$exercise_id' AND
                    session_id = $session_id  AND
                    orig_lp_id =0 AND
                    orig_lp_item_id = 0
                    $user_condition
                ORDER BY exe_id";
        $res = Database::query($sql);
        $list = [];
        while ($row = Database::fetch_array($res, 'ASSOC')) {
            $list[$row['exe_id']] = $row;
            if ($load_question_list) {
                $sql = "SELECT * FROM $TBL_TRACK_ATTEMPT
                        WHERE exe_id = {$row['exe_id']}";
                $res_question = Database::query($sql);
                while ($row_q = Database::fetch_array($res_question, 'ASSOC')) {
                    $list[$row['exe_id']]['question_list'][$row_q['question_id']] = $row_q;
                }
            }
        }
        return $list;
    }

    /**
     * Gets all exercise results (NO Exercises in LPs ) from a given exercise id, course, session
     * @param   int  $courseId
     * @param   int     session id
     * @param bool $get_count
     * @return  array   with the results
     *
     */
    public static function get_all_exercise_results_by_course(
        $courseId,
        $session_id = 0,
        $get_count = true
    ) {
        $table_track_exercises = Database::get_main_table(TABLE_STATISTIC_TRACK_E_EXERCISES);
        $courseId = intval($courseId);
        $session_id = intval($session_id);

        $select = '*';
        if ($get_count) {
            $select = 'count(*) as count';
        }
        $sql = "SELECT $select FROM $table_track_exercises
                WHERE   status = ''  AND
                        c_id = '$courseId' AND
                        session_id = $session_id  AND
                        orig_lp_id = 0 AND
                        orig_lp_item_id = 0
                ORDER BY exe_id";
        $res = Database::query($sql);
        if ($get_count) {
            $row = Database::fetch_array($res, 'ASSOC');
            return $row['count'];
        } else {
            $list = [];
            while ($row = Database::fetch_array($res, 'ASSOC')) {
                $list[$row['exe_id']] = $row;
            }
            return $list;
        }
    }

    /**
     * Gets all exercise results (NO Exercises in LPs) from a given exercise id, course, session
     * @param   int     exercise id
     * @param   int  $courseId
     * @param   int     session id
     * @return  array   with the results
     *
     */
    public static function get_all_exercise_results_by_user(
        $user_id,
        $courseId,
        $session_id = 0
    ) {
        $table_track_exercises = Database::get_main_table(TABLE_STATISTIC_TRACK_E_EXERCISES);
        $table_track_attempt = Database::get_main_table(TABLE_STATISTIC_TRACK_E_ATTEMPT);
        $courseId = intval($courseId);
        $session_id = intval($session_id);
        $user_id = intval($user_id);

        $sql = "SELECT * FROM $table_track_exercises
                WHERE
                    status = '' AND
                    exe_user_id = $user_id AND
                    c_id = '$courseId' AND
                    session_id = $session_id AND
                    orig_lp_id = 0 AND
                    orig_lp_item_id = 0
                ORDER by exe_id";

        $res = Database::query($sql);
        $list = [];
        while ($row = Database::fetch_array($res, 'ASSOC')) {
            $list[$row['exe_id']] = $row;
            $sql = "SELECT * FROM $table_track_attempt 
                    WHERE exe_id = {$row['exe_id']}";
            $res_question = Database::query($sql);
            while ($row_q = Database::fetch_array($res_question, 'ASSOC')) {
                $list[$row['exe_id']]['question_list'][$row_q['question_id']] = $row_q;
            }
        }

        return $list;
    }

    /**
     * Gets exercise results (NO Exercises in LPs) from a given exercise id, course, session
     * @param   int     $exe_id exercise id
     * @param string $status
     * @return  array   with the results
     *
     */
    public static function get_exercise_results_by_attempt($exe_id, $status = null)
    {
        $table_track_exercises = Database::get_main_table(TABLE_STATISTIC_TRACK_E_EXERCISES);
        $table_track_attempt = Database::get_main_table(TABLE_STATISTIC_TRACK_E_ATTEMPT);
        $table_track_attempt_recording = Database::get_main_table(TABLE_STATISTIC_TRACK_E_ATTEMPT_RECORDING);
        $exe_id = intval($exe_id);

        $status = Database::escape_string($status);

        $sql = "SELECT * FROM $table_track_exercises
                WHERE status = '".$status."' AND exe_id = $exe_id";

        $res = Database::query($sql);
        $list = [];
        if (Database::num_rows($res)) {
            $row = Database::fetch_array($res, 'ASSOC');

            //Checking if this attempt was revised by a teacher
            $sql_revised = 'SELECT exe_id FROM '.$table_track_attempt_recording.'
                            WHERE author != "" AND exe_id = '.$exe_id.' 
                            LIMIT 1';
            $res_revised = Database::query($sql_revised);
            $row['attempt_revised'] = 0;
            if (Database::num_rows($res_revised) > 0) {
                $row['attempt_revised'] = 1;
            }
            $list[$exe_id] = $row;
            $sql = "SELECT * FROM $table_track_attempt
                    WHERE exe_id = $exe_id 
                    ORDER BY tms ASC";
            $res_question = Database::query($sql);
            while ($row_q = Database::fetch_array($res_question, 'ASSOC')) {
                $list[$exe_id]['question_list'][$row_q['question_id']] = $row_q;
            }
        }

        return $list;
    }

    /**
     * Gets exercise results (NO Exercises in LPs) from a given user, exercise id, course, session, lp_id, lp_item_id
     * @param   int     user id
     * @param   int     exercise id
     * @param   string  course code
     * @param   int     session id
     * @param   int     lp id
     * @param   int     lp item id
     * @param   string order asc or desc
     * @return  array   with the results
     *
     */
    public static function getExerciseResultsByUser(
        $user_id,
        $exercise_id,
        $courseId,
        $session_id = 0,
        $lp_id = 0,
        $lp_item_id = 0,
        $order = null
    ) {
        $table_track_exercises = Database::get_main_table(TABLE_STATISTIC_TRACK_E_EXERCISES);
        $table_track_attempt = Database::get_main_table(TABLE_STATISTIC_TRACK_E_ATTEMPT);
        $table_track_attempt_recording = Database::get_main_table(TABLE_STATISTIC_TRACK_E_ATTEMPT_RECORDING);
        $courseId = intval($courseId);
        $exercise_id = intval($exercise_id);
        $session_id = intval($session_id);
        $user_id = intval($user_id);
        $lp_id = intval($lp_id);
        $lp_item_id = intval($lp_item_id);

        if (!in_array(strtolower($order), ['asc', 'desc'])) {
            $order = 'asc';
        }

        $sql = "SELECT * FROM $table_track_exercises
                WHERE
                    status 			= '' AND
                    exe_user_id 	= $user_id AND
                    c_id 	        = $courseId AND
                    exe_exo_id 		= $exercise_id AND
                    session_id 		= $session_id AND
                    orig_lp_id 		= $lp_id AND
                    orig_lp_item_id = $lp_item_id
                ORDER by exe_id $order ";

        $res = Database::query($sql);
        $list = [];
        while ($row = Database::fetch_array($res, 'ASSOC')) {
            // Checking if this attempt was revised by a teacher
            $sql = 'SELECT exe_id FROM '.$table_track_attempt_recording.'
                    WHERE author != "" AND exe_id = '.$row['exe_id'].'
                    LIMIT 1';
            $res_revised = Database::query($sql);
            $row['attempt_revised'] = 0;
            if (Database::num_rows($res_revised) > 0) {
                $row['attempt_revised'] = 1;
            }
            $list[$row['exe_id']] = $row;
            $sql = "SELECT * FROM $table_track_attempt
                    WHERE exe_id = {$row['exe_id']}";
            $res_question = Database::query($sql);
            while ($row_q = Database::fetch_array($res_question, 'ASSOC')) {
                $list[$row['exe_id']]['question_list'][$row_q['question_id']][] = $row_q;
            }
        }
        return $list;
    }

    /**
     * Count exercise attempts (NO Exercises in LPs ) from a given exercise id, course, session
     * @param int $user_id
     * @param   int     exercise id
     * @param   int     $courseId
     * @param   int     session id
     * @return  array   with the results
     *
     */
    public static function count_exercise_attempts_by_user(
        $user_id,
        $exercise_id,
        $courseId,
        $session_id = 0
    ) {
        $table = Database::get_main_table(TABLE_STATISTIC_TRACK_E_EXERCISES);
        $courseId = intval($courseId);
        $exercise_id = intval($exercise_id);
        $session_id = intval($session_id);
        $user_id = intval($user_id);

        $sql = "SELECT count(*) as count 
                FROM $table
                WHERE status = ''  AND
                    exe_user_id = '$user_id' AND
                    c_id = '$courseId' AND
                    exe_exo_id = '$exercise_id' AND
                    session_id = $session_id AND
                    orig_lp_id =0 AND
                    orig_lp_item_id = 0
                ORDER BY exe_id";
        $res = Database::query($sql);
        $result = 0;
        if (Database::num_rows($res) > 0) {
            $row = Database::fetch_array($res, 'ASSOC');
            $result = $row['count'];
        }

        return $result;
    }

    /**
     * Gets all exercise BEST results attempts (NO Exercises in LPs)
     * from a given exercise id, course, session per user
     * @param   int     $exercise_id
     * @param   int     $courseId
     * @param   int     $session_id
     * @param int $userId
     * @return  array   with the results
     * @todo rename this function
     */
    public static function get_best_exercise_results_by_user(
        $exercise_id,
        $courseId,
        $session_id = 0,
        $userId = 0
    ) {
        $table_track_exercises = Database::get_main_table(TABLE_STATISTIC_TRACK_E_EXERCISES);
        $table_track_attempt = Database::get_main_table(TABLE_STATISTIC_TRACK_E_ATTEMPT);
        $courseId = intval($courseId);
        $exercise_id = intval($exercise_id);
        $session_id = intval($session_id);

        $sql = "SELECT * FROM $table_track_exercises
                WHERE
                    status = '' AND
                    c_id = $courseId AND
                    exe_exo_id = '$exercise_id' AND
                    session_id = $session_id AND
                    orig_lp_id = 0 AND
                    orig_lp_item_id = 0";

        if (!empty($userId)) {
            $userId = (int) $userId;
            $sql .= " AND exe_user_id = $userId ";
        }
        $sql .= " ORDER BY exe_id";

        $res = Database::query($sql);
        $list = [];
        while ($row = Database::fetch_array($res, 'ASSOC')) {
            $list[$row['exe_id']] = $row;
            $sql = "SELECT * FROM $table_track_attempt 
                    WHERE exe_id = {$row['exe_id']}";
            $res_question = Database::query($sql);
            while ($row_q = Database::fetch_array($res_question, 'ASSOC')) {
                $list[$row['exe_id']]['question_list'][$row_q['question_id']] = $row_q;
            }
        }

        // Getting the best results of every student
        $best_score_return = [];
        foreach ($list as $student_result) {
            $user_id = $student_result['exe_user_id'];
            $current_best_score[$user_id] = $student_result['exe_result'];

            //echo $current_best_score[$user_id].' - '.$best_score_return[$user_id]['exe_result'].'<br />';
            if (!isset($best_score_return[$user_id]['exe_result'])) {
                $best_score_return[$user_id] = $student_result;
            }

            if ($current_best_score[$user_id] > $best_score_return[$user_id]['exe_result']) {
                $best_score_return[$user_id] = $student_result;
            }
        }

        return $best_score_return;
    }

    /**
     * @param int $user_id
     * @param int $exercise_id
     * @param int $courseId
     * @param int $session_id
     * @return array
     */
    public static function get_best_attempt_exercise_results_per_user(
        $user_id,
        $exercise_id,
        $courseId,
        $session_id = 0
    ) {
        $table_track_exercises = Database::get_main_table(TABLE_STATISTIC_TRACK_E_EXERCISES);
        $courseId = intval($courseId);
        $exercise_id = intval($exercise_id);
        $session_id = intval($session_id);
        $user_id = intval($user_id);

        $sql = "SELECT * FROM $table_track_exercises
                WHERE
                    status = ''  AND
                    c_id = '$courseId' AND
                    exe_exo_id = '$exercise_id' AND
                    session_id = $session_id  AND
                    exe_user_id = $user_id AND
                    orig_lp_id =0 AND
                    orig_lp_item_id = 0
                ORDER BY exe_id";

        $res = Database::query($sql);
        $list = [];
        while ($row = Database::fetch_array($res, 'ASSOC')) {
            $list[$row['exe_id']] = $row;
        }
        //Getting the best results of every student
        $best_score_return = [];
        $best_score_return['exe_result'] = 0;

        foreach ($list as $result) {
            $current_best_score = $result;
            if ($current_best_score['exe_result'] > $best_score_return['exe_result']) {
                $best_score_return = $result;
            }
        }
        if (!isset($best_score_return['exe_weighting'])) {
            $best_score_return = [];
        }
        return $best_score_return;
    }

    /**
     * @param int $exercise_id
     * @param int $courseId
     * @param int $session_id
     * @return mixed
     */
    public static function count_exercise_result_not_validated(
        $exercise_id,
        $courseId,
        $session_id = 0
    ) {
        $table_track_exercises = Database::get_main_table(TABLE_STATISTIC_TRACK_E_EXERCISES);
        $table_track_attempt = Database::get_main_table(TABLE_STATISTIC_TRACK_E_ATTEMPT_RECORDING);
        $courseId = intval($courseId);
        $session_id = intval($session_id);
        $exercise_id = intval($exercise_id);

        $sql = "SELECT count(e.exe_id) as count
                FROM $table_track_exercises e
                LEFT JOIN $table_track_attempt a
                ON e.exe_id = a.exe_id
                WHERE
                    exe_exo_id = $exercise_id AND
                    c_id = '$courseId' AND
                    e.session_id = $session_id  AND
                    orig_lp_id = 0 AND
                    marks IS NULL AND
                    status = '' AND
                    orig_lp_item_id = 0
                ORDER BY e.exe_id";
        $res = Database::query($sql);
        $row = Database::fetch_array($res, 'ASSOC');

        return $row['count'];
    }

    /**
     * Gets all exercise BEST results attempts (NO Exercises in LPs)
     * from a given exercise id, course, session per user
     * @param   int     exercise id
     * @param   int   course id
     * @param   int     session id
     * @return  array   with the results
     *
     */
    public static function get_count_exercises_attempted_by_course(
        $courseId,
        $session_id = 0
    ) {
        $table_track_exercises = Database::get_main_table(TABLE_STATISTIC_TRACK_E_EXERCISES);
        $courseId = intval($courseId);
        $session_id = intval($session_id);

        $sql = "SELECT DISTINCT exe_exo_id, exe_user_id
                FROM $table_track_exercises
                WHERE
                    status = '' AND
                    c_id = '$courseId' AND
                    session_id = $session_id AND
                    orig_lp_id =0 AND
                    orig_lp_item_id = 0
                ORDER BY exe_id";
        $res = Database::query($sql);
        $count = 0;
        if (Database::num_rows($res) > 0) {
            $count = Database::num_rows($res);
        }
        return $count;
    }

    /**
     * Gets all exercise events from a Learning Path within a Course    nd Session
     * @param int $exercise_id
     * @param int $courseId
     * @param int $session_id
     * @return array
     */
    public static function get_all_exercise_event_from_lp(
        $exercise_id,
        $courseId,
        $session_id = 0
    ) {
        $table_track_exercises = Database::get_main_table(TABLE_STATISTIC_TRACK_E_EXERCISES);
        $table_track_attempt = Database::get_main_table(TABLE_STATISTIC_TRACK_E_ATTEMPT);
        $courseId = intval($courseId);
        $exercise_id = intval($exercise_id);
        $session_id = intval($session_id);

        $sql = "SELECT * FROM $table_track_exercises
                WHERE
                    status = '' AND
                    c_id = $courseId AND
                    exe_exo_id = '$exercise_id' AND
                    session_id = $session_id AND
                    orig_lp_id !=0 AND
                    orig_lp_item_id != 0";

        $res = Database::query($sql);
        $list = [];
        while ($row = Database::fetch_array($res, 'ASSOC')) {
            $list[$row['exe_id']] = $row;
            $sql = "SELECT * FROM $table_track_attempt 
                    WHERE exe_id = {$row['exe_id']}";
            $res_question = Database::query($sql);
            while ($row_q = Database::fetch_array($res_question, 'ASSOC')) {
                $list[$row['exe_id']]['question_list'][$row_q['question_id']] = $row_q;
            }
        }
        return $list;
    }

    /**
     * @param int $lp_id
     * @param int $course_id
     *
     * @return array
     */
    public static function get_all_exercises_from_lp($lp_id, $course_id)
    {
        $lp_item_table = Database::get_course_table(TABLE_LP_ITEM);
        $course_id = intval($course_id);
        $lp_id = intval($lp_id);
        $sql = "SELECT * FROM $lp_item_table
                WHERE
                    c_id = $course_id AND
                    lp_id = '".$lp_id."' AND
                    item_type = 'quiz'
                ORDER BY parent_item_id, display_order";
        $res = Database::query($sql);

        $my_exercise_list = [];
        while ($row = Database::fetch_array($res, 'ASSOC')) {
            $my_exercise_list[] = $row;
        }

        return $my_exercise_list;
    }

    /**
     * This function gets the comments of an exercise
     *
     * @param int $exe_id
     * @param int $question_id
     *
     * @return string the comment
     */
    public static function get_comments($exe_id, $question_id)
    {
        $table = Database::get_main_table(TABLE_STATISTIC_TRACK_E_ATTEMPT);
        $sql = "SELECT teacher_comment 
                FROM $table
                WHERE
                    exe_id='".Database::escape_string($exe_id)."' AND
                    question_id = '".Database::escape_string($question_id)."'
                ORDER by question_id";
        $sqlres = Database::query($sql);
        $comm = Database::result($sqlres, 0, 'teacher_comment');
        $comm = trim($comm);

        return $comm;
    }

    /**
     * @param int $exeId
     *
     * @return array
     */
    public static function getAllExerciseEventByExeId($exeId)
    {
        $table = Database::get_main_table(TABLE_STATISTIC_TRACK_E_ATTEMPT);
        $exeId = intval($exeId);

        $sql = "SELECT * FROM $table
                WHERE exe_id = $exeId
                ORDER BY position";
        $res_question = Database::query($sql);
        $list = [];
        if (Database::num_rows($res_question)) {
            while ($row = Database::fetch_array($res_question, 'ASSOC')) {
                $list[$row['question_id']][] = $row;
            }
        }
        return $list;
    }

    /**
     *
     * @param int $exeId
     * @param int $user_id
     * @param int $courseId
     * @param int $session_id
     * @param int $question_id
     */
    public static function delete_attempt(
        $exeId,
        $user_id,
        $courseId,
        $session_id,
        $question_id
    ) {
        $table = Database::get_main_table(TABLE_STATISTIC_TRACK_E_ATTEMPT);

        $exeId = intval($exeId);
        $user_id = intval($user_id);
        $courseId = intval($courseId);
        $session_id = intval($session_id);
        $question_id = intval($question_id);

        $sql = "DELETE FROM $table
                WHERE
                    exe_id = $exeId AND
                    user_id = $user_id AND
                    c_id = $courseId AND
                    session_id = $session_id AND
                    question_id = $question_id ";
        Database::query($sql);

        self::addEvent(
            LOG_QUESTION_RESULT_DELETE,
            LOG_EXERCISE_ATTEMPT_QUESTION_ID,
            $exeId.'-'.$question_id,
            null,
            null,
            $courseId,
            $session_id
        );
    }

    /**
     * @param $exeId
     * @param $user_id
     * @param int $courseId
     * @param $question_id
     * @param int $sessionId
     */
    public static function delete_attempt_hotspot(
        $exeId,
        $user_id,
        $courseId,
        $question_id,
        $sessionId = null
    ) {
        $table = Database::get_main_table(TABLE_STATISTIC_TRACK_E_HOTSPOT);

        $exeId = intval($exeId);
        $user_id = intval($user_id);
        $courseId = intval($courseId);
        $question_id = intval($question_id);
        if (!isset($sessionId)) {
            $sessionId = api_get_session_id();
        }

        $sql = "DELETE FROM $table
                WHERE   
                    hotspot_exe_id = $exeId AND
                    hotspot_user_id = $user_id AND
                    c_id = $courseId AND
                    hotspot_question_id = $question_id ";
        Database::query($sql);
        self::addEvent(
            LOG_QUESTION_RESULT_DELETE,
            LOG_EXERCISE_ATTEMPT_QUESTION_ID,
            $exeId.'-'.$question_id,
            null,
            null,
            $courseId,
            $sessionId
        );
    }

    /**
     * Registers in track_e_course_access when user logs in for the first time to a course
     * @param int $courseId ID of the course
     * @param int $user_id ID of the user
     * @param int $sessionId ID of the session (if any)
     *
     * @return bool
     */
    public static function eventCourseLogin($courseId, $user_id, $sessionId)
    {
        if (Session::read('login_as')) {
            return false;
        }
        $table = Database::get_main_table(TABLE_STATISTIC_TRACK_E_COURSE_ACCESS);
        $loginDate = $logoutDate = api_get_utc_datetime();

        // $counter represents the number of time this record has been refreshed
        $counter = 1;
        $courseId = intval($courseId);
        $user_id = intval($user_id);
        $sessionId = intval($sessionId);
        $ip = api_get_real_ip();

        $sql = "INSERT INTO $table(c_id, user_ip, user_id, login_course_date, logout_course_date, counter, session_id)
                VALUES('".$courseId."', '".$ip."', '".$user_id."', '$loginDate', '$logoutDate', $counter, '".$sessionId."')";
        $courseAccessId = Database::query($sql);

        if ($courseAccessId) {
            // Course catalog stats modifications see #4191
            CourseManager::update_course_ranking(
                null,
                null,
                null,
                null,
                true,
                false
            );
            return true;
        }
    }

    /**
     * Updates the user - course - session every X minutes
     * In order to avoid
     * @param int $courseId
     * @param int $userId
     * @param int $sessionId
     * @param int $minutes
     *
     * @return bool
     */
    public static function eventCourseLoginUpdate(
        $courseId,
        $userId,
        $sessionId,
        $minutes = 5
    ) {
        if (Session::read('login_as')) {
            return false;
        }

        if (empty($courseId) || empty($userId)) {
            return false;
        }

        $courseId = intval($courseId);
        $userId = intval($userId);
        $sessionId = intval($sessionId);

        $table = Database::get_main_table(TABLE_STATISTIC_TRACK_E_COURSE_ACCESS);
        $sql = "SELECT course_access_id, logout_course_date 
                FROM $table 
                WHERE 
                    c_id = $courseId AND
                    session_id = $sessionId AND   
                    user_id = $userId                     
                ORDER BY login_course_date DESC
                LIMIT 1";

        $result = Database::query($sql);

        // Save every 5 minutes by default
        $seconds = $minutes * 60;
        $maxSeconds = 3600; // Only update if max diff is one hour
        if (Database::num_rows($result)) {
            $row = Database::fetch_array($result);
            $id = $row['course_access_id'];
            $logout = $row['logout_course_date'];
            $now = time();
            $logout = api_strtotime($logout, 'UTC');
            if ($now - $logout > $seconds &&
                $now - $logout < $maxSeconds
            ) {
                $now = api_get_utc_datetime();
                $sql = "UPDATE $table SET 
                            logout_course_date = '$now', 
                            counter = counter + 1
                        WHERE course_access_id = $id";
                Database::query($sql);
            }

            return true;
        }

        return false;
    }

    /**
     * Register the logout of the course (usually when logging out of the platform)
     * from the track_e_course_access table
     * @param array $logoutInfo Information stored by local.inc.php
     * before new context ['uid'=> x, 'cid'=>y, 'sid'=>z]
     * @return bool
     */
    public static function courseLogout($logoutInfo)
    {
        if (Session::read('login_as')) {
            return false;
        }

        if (empty($logoutInfo['uid']) || empty($logoutInfo['cid'])) {
            return false;
        }

        $sessionLifetime = api_get_configuration_value('session_lifetime');
        /*
         * When $_configuration['session_lifetime'] is larger than ~100 hours
         * (in order to let users take exercises with no problems)
         * the function Tracking::get_time_spent_on_the_course() returns larger values (200h) due the condition:
         * login_course_date > now() - INTERVAL $session_lifetime SECOND
         */
        if (empty($sessionLifetime) || $sessionLifetime > 86400) {
            $sessionLifetime = 3600; // 1 hour
        }
        if (!empty($logoutInfo) && !empty($logoutInfo['cid'])) {
            $tableCourseAccess = Database::get_main_table(TABLE_STATISTIC_TRACK_E_COURSE_ACCESS);
            $userId = intval($logoutInfo['uid']);
            $courseId = intval($logoutInfo['cid']);
            $sessionId = 0;
            if (!empty($logoutInfo['sid'])) {
                $sessionId = intval($logoutInfo['sid']);
            }
            $currentDate = api_get_utc_datetime();
            // UTC time
            $diff = time() - $sessionLifetime;
            $time = api_get_utc_datetime($diff);
            $sql = "SELECT course_access_id, logout_course_date
                    FROM $tableCourseAccess
                    WHERE 
                        user_id = $userId AND
                        c_id = $courseId  AND
                        session_id = $sessionId AND
                        login_course_date > '$time'
                    ORDER BY login_course_date DESC 
                    LIMIT 1";
            $result = Database::query($sql);
            $insert = false;
            if (Database::num_rows($result) > 0) {
                $row = Database::fetch_array($result, 'ASSOC');
                $courseAccessId = $row['course_access_id'];
                $sql = "UPDATE $tableCourseAccess SET 
                                logout_course_date = '$currentDate', 
                                counter = counter + 1
                            WHERE course_access_id = $courseAccessId";
                Database::query($sql);
            } else {
                $insert = true;
            }

            if ($insert) {
                $ip = api_get_real_ip();
                $sql = "INSERT INTO $tableCourseAccess (c_id, user_ip, user_id, login_course_date, logout_course_date, counter, session_id)
                        VALUES ($courseId, '$ip', $userId, '$currentDate', '$currentDate', 1, $sessionId)";
                Database::query($sql);
            }

            return true;
        }
    }

    /**
     * Register a "fake" time spent on the platform, for example to match the
     * estimated time he took to author an assignment/work, see configuration
     * setting considered_working_time.
     * This assumes there is already some connection of the student to the
     * course, otherwise he wouldn't be able to upload an assignment.
     * This works by creating a new record, copy of the current one, then
     * updating the current one to be just the considered_working_time and
     * end at the same second as the user connected to the course.
     * @param int $courseId The course in which to add the time
     * @param int $userId The user for whom to add the time
     * @param int $sessionId The session in which to add the time (if any)
     * @param string $virtualTime The amount of time to be added,
     * in a hh:mm:ss format. If int, we consider it is expressed in hours.
     * @param string $ip IP address to go on record for this time record
     *
     * @return True on successful insertion, false otherwise
     */
    public static function eventAddVirtualCourseTime(
        $courseId,
        $userId,
        $sessionId,
        $virtualTime = '',
        $ip = ''
    ) {
        $courseTrackingTable = Database::get_main_table(TABLE_STATISTIC_TRACK_E_COURSE_ACCESS);
        $time = $loginDate = $logoutDate = api_get_utc_datetime();

        $courseId = intval($courseId);
        $userId = intval($userId);
        $sessionId = intval($sessionId);
        $ip = Database::escape_string($ip);

        // Get the current latest course connection register. We need that
        // record to re-use the data and create a new record.
        $sql = "SELECT *
                FROM $courseTrackingTable
                WHERE
                    user_id = ".$userId." AND
                    c_id = ".$courseId."  AND
                    session_id  = ".$sessionId." AND
                    login_course_date > '$time' - INTERVAL 3600 SECOND
                ORDER BY login_course_date DESC 
                LIMIT 0,1";
        $result = Database::query($sql);

        // Ignore if we didn't find any course connection record in the last
        // hour. In this case it wouldn't be right to add a "fake" time record.
        if (Database::num_rows($result) > 0) {
            // Found the latest connection
            $row = Database::fetch_array($result);
            $courseAccessId = $row['course_access_id'];
            $courseAccessLoginDate = $row['login_course_date'];
            $counter = $row['counter'];
            $counter = $counter ? $counter : 0;
            // Insert a new record, copy of the current one (except the logout
            // date that we update to the current time)
            $sql = "INSERT INTO $courseTrackingTable(
                    c_id,
                    user_ip, 
                    user_id, 
                    login_course_date, 
                    logout_course_date, 
                    counter, 
                    session_id
                ) VALUES(
                    $courseId, 
                    '$ip', 
                    $userId, 
                    '$courseAccessLoginDate', 
                    '$logoutDate', 
                    $counter, 
                    $sessionId
                )";
            Database::query($sql);

            $loginDate = ChamiloApi::addOrSubTimeToDateTime(
                $virtualTime,
                $courseAccessLoginDate,
                false
            );
            // We update the course tracking table
            $sql = "UPDATE $courseTrackingTable  
                    SET 
                        login_course_date = '$loginDate',
                        logout_course_date = '$courseAccessLoginDate',
                        counter = 0
                    WHERE 
                        course_access_id = ".intval($courseAccessId)." AND 
                        session_id = ".$sessionId;
            Database::query($sql);

            return true;
        }

        return false;
    }
    /**
     * Removes a "fake" time spent on the platform, for example to match the
     * estimated time he took to author an assignment/work, see configuration
     * setting considered_working_time.
     * This method should be called when something that generated a fake
     * time record is removed. Given the database link is weak (no real
     * relationship kept between the deleted item and this record), this
     * method just looks for the latest record that has the same time as the
     * item's fake time, is in the past and in this course+session. If such a
     * record cannot be found, it doesn't do anything.
     * The IP address is not considered a useful filter here.
     * @param int $courseId The course in which to add the time
     * @param int $userId The user for whom to add the time
     * @param int $sessionId The session in which to add the time (if any)
     * @param string $virtualTime The amount of time to be added, in a hh:mm:ss format. If int, we consider it is expressed in hours.
     * @return True on successful removal, false otherwise
     */
    public static function eventRemoveVirtualCourseTime(
        $courseId,
        $userId,
        $sessionId = 0,
        $virtualTime = ''
    ) {
        if (empty($virtualTime)) {
            return false;
        }
        $courseTrackingTable = Database::get_main_table(TABLE_STATISTIC_TRACK_E_COURSE_ACCESS);
        $time = $loginDate = $logoutDate = api_get_utc_datetime();

        $courseId = intval($courseId);
        $userId = intval($userId);
        $sessionId = intval($sessionId);
        // Change $virtualTime format from hh:mm:ss to hhmmss which is the
        // format returned by SQL for a subtraction of two datetime values
        // @todo make sure this is portable between DBMSes
        if (preg_match('/:/', $virtualTime)) {
            list($h, $m, $s) = preg_split('/:/', $virtualTime);
            $virtualTime = $h * 3600 + $m * 60 + $s;
        } else {
            $virtualTime *= 3600;
        }

        // Get the current latest course connection register. We need that
        // record to re-use the data and create a new record.
        $sql = "SELECT course_access_id
                FROM $courseTrackingTable
                WHERE
                    user_id = $userId AND
                    c_id = $courseId  AND
                    session_id  = $sessionId AND
                    counter = 0 AND
                    (UNIX_TIMESTAMP(logout_course_date) - UNIX_TIMESTAMP(login_course_date)) = '$virtualTime'
                ORDER BY login_course_date DESC LIMIT 0,1";
        $result = Database::query($sql);

        // Ignore if we didn't find any course connection record in the last
        // hour. In this case it wouldn't be right to add a "fake" time record.
        if (Database::num_rows($result) > 0) {
            // Found the latest connection
            $row = Database::fetch_row($result);
            $courseAccessId = $row[0];
            $sql = "DELETE FROM $courseTrackingTable 
                    WHERE course_access_id = $courseAccessId";
            $result = Database::query($sql);

            return $result;
        }

        return false;
    }

    /**
     * For the sake of genericity, this function is a switch.
     * It's called by EventsDispatcher and fires the good function
     * with the good require_once.
     *
     * @deprecated
     * @param string $event_name
     * @param array $params
     */
    public static function event_send_mail($event_name, $params)
    {
        EventsMail::send_mail($event_name, $params);
    }

    /**
     * Internal function checking if the mail was already sent from that user to that user
     * @param string $event_name
     * @param int $user_from
     * @param int $user_to
     * @return boolean
     */
    public static function check_if_mail_already_sent(
        $event_name,
        $user_from,
        $user_to = null
    ) {
        if ($user_to == null) {
            $sql = 'SELECT COUNT(*) as total 
                    FROM '.Database::get_main_table(TABLE_EVENT_SENT).'
                    WHERE 
                        user_from = '.$user_from.' AND 
                        event_type_name = "'.$event_name.'"';
        } else {
            $sql = 'SELECT COUNT(*) as total 
                    FROM '.Database::get_main_table(TABLE_EVENT_SENT).'
                    WHERE 
                        user_from = '.$user_from.' AND 
                        user_to = '.$user_to.' AND 
                        event_type_name = "'.$event_name.'"';
        }
        $result = Database::store_result(Database::query($sql), 'ASSOC');

        return $result[0]["total"];
    }

    /**
     * Filter EventEmailTemplate Filters see the main/inc/conf/events.conf.dist.php
     */

    /**
     * Basic template event message filter (to be used by other filters as default)
     * @deprecated
     * @param array $values (passing by reference)     *
     * @return boolean True if everything is OK, false otherwise
     */
    public function event_send_mail_filter_func(&$values)
    {
        return true;
    }

    /**
     * user_registration - send_mail filter
     * @deprecated
     * @param array $values (passing by reference)
     * @return boolean True if everything is OK, false otherwise
     */
    public function user_registration_event_send_mail_filter_func(&$values)
    {
        $res = self::event_send_mail_filter_func($values);
        // proper logic for this filter
        return $res;
    }

    /**
     * portal_homepage_edited - send_mail filter
     * @deprecated
     * @param array $values (passing by reference)
     * @return boolean True if everything is OK, false otherwise
     */
    public function portal_homepage_edited_event_send_mail_filter_func(&$values)
    {
        $res = self::event_send_mail_filter_func($values);
        // proper logic for this filter
        return $res;
    }
}
