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
 * file
 *
 * @package   local_helpdesk
 * @copyright 2025 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . "/../../config.php");
require_once(__DIR__ . "/lib.php");

use local_helpdesk\form\ticket_controller;
use local_helpdesk\model\category;
use local_helpdesk\model\category_users;
use local_helpdesk\model\ticket;

global $DB, $OUTPUT, $PAGE, $USER;

/* =========================
   PARAMS
========================= */

$action = optional_param("action", "", PARAM_ALPHA);
$ticketid = optional_param("id", false, PARAM_INT);

$findpriority = optional_param("find_priority", false, PARAM_TEXT);
$findstatus = optional_param("find_status", false, PARAM_TEXT);
$findcategory = optional_param("find_category", false, PARAM_INT);
$courseid = optional_param("courseid", false, PARAM_INT);
$finduser = optional_param("find_user", false, PARAM_INT);
$search = optional_param("search", "", PARAM_TEXT);

/* =========================
   CONTEXT
========================= */

if ($courseid) {
    $context = context_course::instance($courseid);
    require_login($courseid, false);
} else {
    $context = context_system::instance();
    require_login(null, false);
}

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url("/local/helpdesk/index.php"));
$PAGE->set_title(get_string("tickets", "local_helpdesk"));
$PAGE->set_heading(get_string("tickets", "local_helpdesk"));

/* =========================
   CAPABILITIES
========================= */

$hasticketmanage = has_capability("local/helpdesk:ticketmanage", $context);
$hasticketview = $hasticketmanage || has_capability("local/helpdesk:view", $context);
$hascategorymanage = has_capability("local/helpdesk:categorymanage", $context);

require_capability("local/helpdesk:view", $context);

if ($hasticketmanage) {
    local_helpdesk_set_secondarynav();
} else {
    $PAGE->set_secondary_navigation(false);
}

$PAGE->navbar->add(get_string("tickets", "local_helpdesk"),
    new moodle_url("/local/helpdesk/"));

/* =========================
   CATEGORIES
========================= */

$categoryoptions = [];
$categorys = category::get_all(null, null, "name ASC");

foreach ($categorys as $category) {

    $option = [
        "key" => $category->get_id(),
        "label" => $category->get_name(),
        "selected" => $findcategory == $category->get_id() ? "selected" : "",
    ];

    if ($hasticketmanage) {
        if ($hascategorymanage ||
            category_users::get_all(null, [
                "categoryid" => $category->get_id(),
                "userid" => $USER->id
            ])) {
            $categoryoptions[] = $option;
        }
    } else {
        $categoryoptions[] = $option;
    }
}

if (count($categoryoptions) == 0 && $hascategorymanage) {
    redirect(new moodle_url("/local/helpdesk/categories.php?actionform=add"),
        get_string("createcategoryfirst", "local_helpdesk"), null, "warning");
}

/* =========================
   FILTER LABELS
========================= */

$coursefullname = get_string("findcourse", "local_helpdesk");
if ($courseid) {
    if ($course = $DB->get_record("course", ["id" => $courseid], "id, fullname")) {
        $coursefullname = $course->fullname;
    }
}

$userfullname = get_string("finduser", "local_helpdesk");
if ($finduser) {
    if ($user = $DB->get_record("user", ["id" => $finduser])) {
        $userfullname = fullname($user);
    }
}

/* =========================
   TEMPLATE CONTEXT BASE
========================= */

$templatecontext = [
    "status_options" => ticket::get_status_options($findstatus, true),
    "priority_options" => ticket::get_priority_options($findpriority),
    "category_options" => $categoryoptions,
    "find_course" => \local_helpdesk\util\filter::create_filter_course($coursefullname, $courseid),
    "find_user" => $hasticketmanage ? \local_helpdesk\util\filter::create_filter_user($userfullname, $finduser) : "",
    "tickets" => [],
    "courseid" => $courseid,
    "search" => $search,
    "has_manage" => $hasticketmanage,
    "has_categorymanage" => $hascategorymanage,
    "has_siteconfig" => has_capability("moodle/site:config", $context),
];

/* =========================
   ACTIONS
========================= */

if ($action == "add") {
    (new ticket_controller())->insert_ticket();

} else if ($action == "edit" && $ticketid && $hasticketmanage) {
    (new ticket_controller())->update_ticket($ticketid);

} else {

    /* =========================
       FILTER BUILD
    ========================= */

    $params = [];

    if ($findpriority) {
        $params["priority"] = $findpriority;
    }

    if ($findstatus) {
        if ($findstatus != "all") {
            $params["status"] = $findstatus;
        }
    } else {
        $params[] = "status NOT IN('resolved','closed')";
    }

    if ($findcategory) {
        $params["categoryid"] = $findcategory;
    }

    if ($courseid) {
        $params["courseid"] = $courseid;
    }

    if (!$hasticketmanage) {
        $params["userid"] = $USER->id;
    } else if ($finduser) {
        $params["userid"] = $finduser;
    }

    /* =========================
       ORDER
    ========================= */

    $order = "
        CASE
            WHEN priority = 'urgent' THEN 1
            WHEN priority = 'high'   THEN 2
            WHEN priority = 'medium' THEN 3
            WHEN priority = 'low'    THEN 4
            ELSE 5
        END,
        CASE
            WHEN status = 'open'     THEN 1
            WHEN status = 'progress' THEN 2
            WHEN status = 'resolved' THEN 3
            WHEN status = 'closed'   THEN 4
            ELSE 5
        END,
        createdat ASC";

    /* =========================
       SEARCH
    ========================= */

    if (isset($search[2])) {

        $wheres = [];

        foreach ($params as $key => $value) {
            if (is_int($key)) {
                $wheres[] = $value;
            } else {
                $wheres[] = "{$key} = :{$key}";
            }
        }

        $where = isset($wheres[0])
            ? "WHERE " . implode(" AND ", $wheres) . " AND (subject LIKE :search1 OR description LIKE :search2)"
            : "WHERE subject LIKE :search1 OR description LIKE :search2";

        $params["search1"] = "%{$search}%";
        $params["search2"] = "%{$search}%";

        $tickets = ticket::get_all($where, $params, $order);

    } else {
        $tickets = ticket::get_all(null, $params, $order);
    }

    /* =========================
       🔥 OPTIMIZED DATA LOAD
    ========================= */

    $users = $DB->get_records("user", null, "", "id, firstname, lastname, picture, imagealt");
    $courses = $DB->get_records("course", null, "", "id, fullname");

    foreach ($tickets as $ticket) {

        $userid = $ticket->get_userid();
        $courseid = $ticket->get_courseid();

        $user = $users[$userid] ?? null;

        $coursefullname = "-";
        if ($courseid && isset($courses[$courseid])) {
            $coursefullname = $courses[$courseid]->fullname;
        }

        $templatecontext["tickets"][] = [
            "user_picture" => $user ? (new user_picture($user))->get_url($PAGE) : "",
            "user_fullname" => $user ? fullname($user) : "-",
            "course_fullname" => $coursefullname,
            "id" => $ticket->get_id(),
            "idkey" => $ticket->get_idkey(),
            "subject" => $ticket->get_subject(),
            "status" => $ticket->get_status(),
            "status_translated" => $ticket->get_status_translated(),
            "priority" => $ticket->get_priority(),
            "priority_translated" => $ticket->get_priority_translated(),
            "category" => $ticket->get_categoryid(),
            "category_translate" => $ticket->get_category()->get_name(),
            "createdat" => userdate(
                $ticket->get_createdat(),
                get_string("strftimedatetimeshort", "langconfig")
            ),
        ];
    }
}

/* =========================
   OUTPUT
========================= */

echo $OUTPUT->header();

if ($hasticketmanage) {
    echo $OUTPUT->render_from_template("local_helpdesk/index-top", [
        "all_open_tickets" => $DB->get_field_select("local_helpdesk_ticket", "COUNT(*)", "status NOT IN('closed','resolved')"),
        "unanswered_tickets" => $DB->get_field_select("local_helpdesk_ticket", "COUNT(*)", "status IN('open')"),
        "completed_tickets" => $DB->get_field_select("local_helpdesk_ticket", "COUNT(*)", "status IN('closed','resolved')"),
        "urgent_tickets" => $DB->get_field_select("local_helpdesk_ticket", "COUNT(*)", "status NOT IN('closed','resolved') AND priority IN('urgent','high')"),
    ]);
}

echo $OUTPUT->render_from_template("local_helpdesk/index", $templatecontext);

$PAGE->requires->js_call_amd("local_helpdesk/index", "init");
$PAGE->requires->js_call_amd("local_helpdesk/search", "init");

echo $OUTPUT->footer();