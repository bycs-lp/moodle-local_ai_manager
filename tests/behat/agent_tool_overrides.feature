@local @local_ai_manager @mbs_10761
Feature: Admin manages agent tool overrides (MBS-10761)
  In order to tailor the AI agent's tool catalogue for my site
  As an administrator
  I can list the available agent tools and override their descriptions or disable them

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email              |
      | admin2   | Admin     | Two      | admin2@example.com |

  @javascript
  Scenario: Administrator can open the agent tools management page
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > AI manager > Manage agent tools" in site administration
    Then I should see "Manage agent tools"
    And I should see "course_list"
    And I should see "course_get_info"
    And I should see "course_section_update_summary"

  @javascript
  Scenario: Administrator can override the description of a tool
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > AI manager > Manage agent tools" in site administration
    When I click on "Edit" "link" in the "course_list" "table_row"
    And I set the field "Description override" to "Custom site-level description."
    And I press "Save changes"
    Then I should see "Manage agent tools"

  @javascript
  Scenario: Administrator can disable a tool site-wide
    Given I log in as "admin"
    And the following "local_ai_manager > tool overrides" exist:
      | toolname    | enabled |
      | course_list | 0       |
    When I navigate to "Plugins > Local plugins > AI manager > Manage agent tools" in site administration
    Then I should see "No" in the "course_list" "table_row"

  @javascript
  Scenario: Non-admin user cannot access the management page
    Given I log in as "admin2"
    When I visit "/local/ai_manager/agent_tools.php"
    Then I should see "You do not have the permission"
