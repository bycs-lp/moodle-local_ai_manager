@local @local_ai_manager @mbs_10761
Feature: Agent run retention and privacy (MBS-10761)
  In order to comply with data-retention policies
  As an administrator
  Old agent runs must be removed automatically while recent ones are kept

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | student1 | Student   | One      | student1@example.com |

  Scenario: Retention scheduled task deletes runs older than the configured threshold
    Given the following config values are set as admin:
      | agent_run_retention_days | 30 | local_ai_manager |
    And an agent run by "student1" was finished "90" days ago
    And an agent run by "student1" was finished "5" days ago
    When I run the scheduled task "local_ai_manager\task\agent_run_cleanup"
    Then there should be "1" agent runs in the database

  Scenario: Retention value of zero keeps all runs
    Given the following config values are set as admin:
      | agent_run_retention_days | 0 | local_ai_manager |
    And an agent run by "student1" was finished "500" days ago
    When I run the scheduled task "local_ai_manager\task\agent_run_cleanup"
    Then there should be "1" agent runs in the database

  Scenario: Expired trust preferences are purged regardless of retention setting
    Given the following "local_ai_manager > trust prefs" exist:
      | user     | toolname    | scope   | expires |
      | student1 | course_list | session | 1       |
    When I run the scheduled task "local_ai_manager\task\agent_run_cleanup"
    Then there should be "0" trust prefs in the database
