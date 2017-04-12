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
 * A scheduled task for tool_deprovisionuser cron.
 *
 * The Class archive_user_task is supposed to show the admin a page of users which will be archived and expectes a submit or
 * cancel reaction.
 * @package    tool_deprovisionuser
 * @copyright  2016 N Herrmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


namespace tool_deprovisionuser\task;

defined('MOODLE_INTERNAL') || die();

use tool_deprovisionuser\deprovisionuser_exception;
// Needed for the default plugin.
use userstatus_userstatuswwu\userstatuswwu;
use tool_deprovisionuser\archiveduser;
use tool_deprovisionuser\event\deprovisionusercronjob_completed;
use core\task\scheduled_task;

class archive_user_task extends scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('archive_user_task', 'tool_deprovisionuser');
    }

    /**
     * Runs the cron job - Calls for the currently activated subplugin to return arrays of users.
     * Distinguishes between users to reacticate, suspend and delete.
     * Subsequently sends an e-mail to the admin containing information about the amount of successfully changed users
     * and the amount of failures.
     * Last but not least triggers an event with the same information.
     *
     * @return true
     */
    public function execute() {
        // In case the admin did not submit a subplugin, the default is used.
        // This is very unlikely to happen since when installing the plugin a default is defined.
        // It could happen when subplugin is deleted manually (Uninstalling subplugins that are active is not allowed).

        if (!empty(get_config('tool_deprovisionuser', 'deprovisionuser_subplugin'))) {
            $subplugin = get_config('tool_deprovisionuser', 'deprovisionuser_subplugin');
            $mysubpluginname = "\\userstatus_" . $subplugin . "\\" . $subplugin;
            $userstatuschecker = new $mysubpluginname();
        } else {
            $userstatuschecker = new userstatuswwu();
        }

        // Private function is executed to suspend, delete and activate users.
        $archivearray = $userstatuschecker->get_to_suspend();
        $suspendresult = $this->change_user_deprovisionstatus($archivearray, 'suspend');
        $unabletoarchive = $suspendresult['failures'];
        $userarchived = $suspendresult['countersuccess'];

        $reactivatearray = $userstatuschecker->get_to_reactivate();
        $result = $this->change_user_deprovisionstatus($reactivatearray, 'reactivate');
        $unabletoactivate = $result['failures'];

        $arraytodelete = $userstatuschecker->get_to_delete();
        $deleteresult = $this->change_user_deprovisionstatus($arraytodelete, 'delete');
        $unabletodelete = $deleteresult['failures'];
        $userdeleted = $deleteresult['countersuccess'];

        // Admin is informed about the Cronjob and the amount of users that are affected.

        $admin = get_admin();
        // Number of users suspended or deleted.
        $messagetext = get_string('e-mail-archived', 'tool_deprovisionuser', $userarchived) .
            "\r\n" .get_string('e-mail-deleted', 'tool_deprovisionuser', $userdeleted);

        // No Problems occured during the cronjob.
        if (empty($unabletoactivate) and empty($unabletoarchive) and empty($unabletodelete)) {
            $messagetext .= "\r\n\r\n" . get_string('e-mail-noproblem', 'tool_deprovisionuser');
        } else {
            // Extra information for problematic users.
            $messagetext .= "\r\n\r\n" . get_string('e-mail-problematic_delete', 'tool_deprovisionuser',
                    count($unabletodelete)) . "\r\n\r\n" . get_string('e-mail-problematic_suspend', 'tool_deprovisionuser',
                    count($unabletoarchive)) . "\r\n\r\n" . get_string('e-mail-problematic_reactivate', 'tool_deprovisionuser',
                    count($unabletoactivate));
        }

        // Email is send from the do not reply user.
        $user = new \core_user();
        $sender = $user->get_user(-10);
        email_to_user($admin, $sender, 'Update Infos Cron Job tool_deprovisionuser', $messagetext);

        // Triggers cronjob_completed event.
        $context = \context_system::instance();
        $event = deprovisionusercronjob_completed::create_simple($context, $userarchived, $userdeleted);
        $event->trigger();

        return true;
    }

    /**
     * Deletes, suspends or reactivates an array of users.
     *
     * @param  array $userarray of users
     * @param  string $intention of suspend, delete, reactivate
     * @return array ['numbersuccess'] successfully changed users ['failures'] userids, who could not be changed.
     * @throws \coding_exception
     */
    private function change_user_deprovisionstatus($userarray, $intention) {
        // Checks whether the intention is valid.
        if (!in_array($intention, array('suspend', 'reactivate', 'delete'))) {
            throw new \coding_exception('Invalid parameters in tool_deprovisionuser.');
        }

        // Number of successfully changed users.
        $countersuccess = 0;

        // Array of users who could not be changed.
        $failures = array();

        // Alternatively one could have wrote different function for each intention.
        // However this would have produced duplicated code.
        // Therefore checking the intention parameter repeatedly was preferred.
        foreach ($userarray as $key => $user) {
            if ($user->deleted == 0 && !is_siteadmin($user)) {
                $changinguser = new archiveduser($user->id, $user->suspended, $user->lastaccess,
                    $user->username, $user->deleted);
                try {
                    switch ($intention) {
                        case 'suspend':
                            $changinguser->archive_me();
                            break;
                        case 'reactivate':
                            $changinguser->activate_me();
                            break;
                        case 'delete':
                            $changinguser->delete_me();
                            break;
                        // No default since if-clause checks the intention parameter.
                    }
                    $countersuccess++;
                } catch (deprovisionuser_exception $e) {
                    $failures[$key] = $user->id;
                }
            }
        }
        $result = array();
        $result['countersuccess'] = $countersuccess;
        $result['failures'] = $failures;
        return $result;
    }
}