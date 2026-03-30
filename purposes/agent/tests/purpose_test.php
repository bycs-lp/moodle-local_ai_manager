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

namespace aipurpose_agent;

use aitool_chatgpt\instance;
use context_system;
use GuzzleHttp\Psr7\Stream;
use local_ai_manager\ai_manager_utils;
use local_ai_manager\local\config_manager;
use local_ai_manager\local\connector_factory;
use local_ai_manager\local\prompt_response;
use local_ai_manager\local\request_response;
use local_ai_manager\local\tenant;
use local_ai_manager\local\usage;
use local_ai_manager\local\userinfo;
use local_ai_manager\local\userusage;
use local_ai_manager\manager;
use local_ai_manager\plugininfo\aitool;
use stdClass;

/**
 * Unit tests for the agent purpose.
 *
 * @package   aipurpose_agent
 * @copyright 2025 ISB Bayern
 * @author    Andreas Wagner
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class purpose_test extends \advanced_testcase {
    /**
     * Tests the moving of conversations from the user context ai_chat block instances to the system instance.
     *
     * @covers \aipurpose_agent\purpose
     */
    public function test_purpose_perfom_request(): void {
        global $DB, $CFG;

        $this->resetAfterTest();
        $correctaichatsystemblock = new stdClass();
        $correctaichatsystemblock->blockname = 'ai_chat';
        $correctaichatsystemblock->parentcontextid = SYSCONTEXTID;
        $correctaichatsystemblock->showinsubcontexts = 0;
        $correctaichatsystemblock->requiredbytheme = 0;
        $correctaichatsystemblock->pagetypepattern = '';
        $correctaichatsystemblock->subpagepattern = '';
        $correctaichatsystemblock->defaultregion = '';
        $correctaichatsystemblock->defaultweight = '';
        $correctaichatsystemblock->configdata = '';
        $correctaichatsystemblock->timecreated = time();
        $correctaichatsystemblock->timemodified = $correctaichatsystemblock->timecreated;
        $correctaichatsystemblockid = $DB->insert_record('block_instances', $correctaichatsystemblock);
        $correctaichatsystemblockcontext = \context_block::instance($correctaichatsystemblockid);

        // Now also create user contexts.
        $user1 = $this->getDataGenerator()->create_user();

        // Setup the AI Manager.
        $this->setup_ai_manager($user1);
        $this->setUser($user1);
        $manager = new manager('chat');

        // Is conversationid still needed?
        $conversationid = ai_manager_utils::get_next_free_itemid('block_ai_chat', $correctaichatsystemblockcontext->id);

        $options = file_get_contents($CFG->dirroot . '/local/ai_manager/purposes/agent/tests/fixtures/options.json');
        $agentoptions = [
            'agentoptions' => json_decode($options, true),
        ];

        $result = $manager->perform_request(
            'teacherinput',
            'block_ai_chat',
            $correctaichatsystemblockcontext->id,
            $agentoptions
        );
        // TODO Complete test with proper assertions.
    }

    /**
     * Helper function to set up all the necessary things to be able to perform a mock chat request with the local_ai_manager.
     *
     * @param stdClass $user The user, which should be set up for performing the mock chat request
     */
    private function setup_ai_manager(\stdClass $user): void {
        global $DB, $CFG;

        // Faking some chat conversations is going to be a bit of work, but let's do it.
        $tenant = new tenant('1234');
        // Set the capability based on the $configuration.
        $systemcontext = context_system::instance();
        $aiuserrole = $DB->get_record('role', ['shortname' => 'aiuser']);
        if (empty($aiuserrole)) {
            $this->getDataGenerator()->create_role(['shortname' => 'aiuser']);
            $aiuserrole = $DB->get_record('role', ['shortname' => 'aiuser']);
        }
        role_assign($aiuserrole->id, $user->id, $systemcontext->id);
        assign_capability('local/ai_manager:use', CAP_ALLOW, $aiuserrole->id, $systemcontext->id);

        $configmanager = new config_manager($tenant);
        $configmanager->set_config('tenantenabled', 1);

        $userinfo = new userinfo($user->id);
        $userinfo->set_locked(false);
        $userinfo->set_confirmed(true);
        $userinfo->set_scope(userinfo::SCOPE_EVERYWHERE);
        $userinfo->set_role(userinfo::ROLE_BASIC);
        $userinfo->store();

        $configmanager->set_config('chat_max_requests_basic', 1000);

        $userusage = new userusage(\core\di::get(connector_factory::class)->get_purpose_by_purpose_string('chat'), $user->id);
        $userusage->set_currentusage(0);
        $userusage->store();

        $chatgptinstance = new instance();
        $chatgptinstance->set_model('gpt-4o');
        $chatgptinstance->set_connector('chatgpt');

        // Fake a stream object, because we will mock the method that access it anyway.
        $streamresponse = new Stream(fopen('php://temp', 'r+'));
        $requestresponse = request_response::create_from_result($streamresponse);

        // Fake usage object.
        $usage = new usage(50.0, 30.0, 20.0);
        // Fake prompt_response object.

        $responsetext = file_get_contents($CFG->dirroot . '/local/ai_manager/purposes/agent/tests/fixtures/response.txt');

        $promptresponse = prompt_response::create_from_result('gpt-4o', $usage, $responsetext);

        $chatgptconnector =
            $this->getMockBuilder('\aitool_chatgpt\connector')->setConstructorArgs([$chatgptinstance])->getMock();
        $chatgptconnector->expects($this->any())->method('make_request')->willReturn($requestresponse);
        $chatgptconnector->expects($this->any())->method('execute_prompt_completion')->willReturn($promptresponse);
        $connectorfactory =
            $this->getMockBuilder(connector_factory::class)->setConstructorArgs([$configmanager])->getMock();
        $connectorfactory->expects($this->any())->method('get_connector_by_purpose')->willReturn($chatgptconnector);
        $connectorfactory->expects($this->any())->method('get_connector_instance_by_purpose')->willReturn($chatgptinstance);

        $chatpurpose = new purpose();
        $connectorfactory->expects($this->any())->method('get_purpose_by_purpose_string')->willReturn($chatpurpose);
        \core\di::set(config_manager::class, $configmanager);
        \core\di::set(connector_factory::class, $connectorfactory);

        aitool::enable_plugin('agent', true);

        // We disable the hook here, so no other plugin is interfering.
        $this->redirectHook(\local_ai_manager\hook\additional_user_restriction::class, fn() => null);
    }

    /**
     * Build a minimal valid LLM JSON response string.
     *
     * @param string $introtext The intro text for the chatoutput.
     * @param string $outrotext The outro text for the chatoutput.
     * @param array $formelements Optional form elements array.
     * @return string JSON-encoded string as it would come from the LLM.
     */
    private function build_llm_response(string $introtext, string $outrotext = '', array $formelements = []): string {
        return json_encode([
            'formelements' => $formelements,
            'chatoutput' => [
                ['type' => 'intro', 'text' => $introtext],
                ['type' => 'outro', 'text' => $outrotext],
            ],
        ]);
    }

    /**
     * Data provider for test_format_output.
     *
     * Each case provides intro text, outro text, expected substrings that MUST be present
     * in the intro HTML, and expected substrings that MUST NOT be present.
     *
     * @return array array containing the different test cases
     */
    public static function format_output_provider(): array {
        return [
            'plain_text_without_newlines' => [
                'intro' => 'Simple response without any newlines.',
                'outro' => '',
                'introcontains' => ['<p>Simple response without any newlines.</p>'],
                'intronotcontains' => [],
            ],
            'single_newline_produces_separate_paragraphs' => [
                'intro' => "a\nb",
                'outro' => '',
                'introcontains' => ['<p>a</p>', '<p>b</p>'],
                'intronotcontains' => [],
            ],
            'double_newline_stays_as_paragraph_break' => [
                'intro' => "a\n\nb",
                'outro' => '',
                'introcontains' => ['<p>a</p>', '<p>b</p>'],
                'intronotcontains' => ['<p></p>'],
            ],
            'triple_newline_is_not_inflated_further' => [
                'intro' => "a\n\n\nb",
                'outro' => '',
                'introcontains' => ['<p>a</p>', '<p>b</p>'],
                'intronotcontains' => [],
            ],
            'unordered_list_with_single_newlines_renders_as_ul' => [
                'intro' => "Suggestions:\n- Item one.\n- Item two.\n- Item three.",
                'outro' => '',
                'introcontains' => ['<ul>', '<li>', 'Item one.', 'Item two.', 'Item three.'],
                'intronotcontains' => [],
            ],
            'unordered_list_with_double_newlines_renders_as_ul' => [
                'intro' => "Suggestions:\n\n- Alpha.\n\n- Beta.\n\n- Gamma.",
                'outro' => '',
                'introcontains' => ['<ul>', '<li>', 'Alpha.', 'Beta.', 'Gamma.'],
                'intronotcontains' => [],
            ],
            'numbered_items_with_paren_become_separate_paragraphs' => [
                'intro' => "Changes:\n1) First.\n2) Second.\n3) Third.",
                'outro' => '',
                // PHP Markdown Extra does not support "1)" as list syntax, so each item becomes its own <p>.
                'introcontains' => ['<p>1) First.</p>', '<p>2) Second.</p>', '<p>3) Third.</p>'],
                'intronotcontains' => [],
            ],
            'ordered_list_with_dot_syntax_renders_as_ol' => [
                'intro' => "Steps:\n1. First step.\n2. Second step.\n3. Third step.",
                'outro' => '',
                'introcontains' => ['<ol>', '<li>', 'First step.', 'Second step.'],
                'intronotcontains' => [],
            ],
            'mixed_paragraphs_numbered_items_and_unordered_list' => [
                'intro' => "Ich schlage vor, folgende Schritte durchzuführen:\n\n"
                    . "1) Kurzname bereinigen.\n"
                    . "2) Kurszusammenfassung hinzufügen.\n\n"
                    . "Einstellungen, die bereits sinnvoll gesetzt sind:\n"
                    . "- Course visibility: Show.\n"
                    . "- AI Chat: aktiviert.\n\n"
                    . "Sag mir bitte, welche Änderungen ich vornehmen soll.",
                'outro' => '',
                'introcontains' => [
                    '<ul>',
                    '<li>',
                    'Course visibility: Show.',
                    '<p>1) Kurzname bereinigen.</p>',
                    '<p>2) Kurszusammenfassung hinzufügen.</p>',
                ],
                'intronotcontains' => [],
            ],
            'outro_list_is_also_normalized' => [
                'intro' => 'Intro.',
                'outro' => "Fragen:\n- Kursstart anpassen?\n- Gruppen verwenden?",
                'introcontains' => ['<p>Intro.</p>'],
                'intronotcontains' => [],
            ],
        ];
    }

    /**
     * Test chatoutput rendering through format_output with various inputs.
     *
     * @dataProvider format_output_provider
     * @covers \aipurpose_agent\purpose::format_output
     * @param string $intro The intro text for the LLM response.
     * @param string $outro The outro text for the LLM response.
     * @param array $introcontains Substrings that must be present in the rendered intro HTML.
     * @param array $intronotcontains Substrings that must not be present in the rendered intro HTML.
     */
    public function test_format_output(
        string $intro,
        string $outro,
        array $introcontains,
        array $intronotcontains,
    ): void {
        $purpose = new purpose();
        $result = $purpose->format_output($this->build_llm_response($intro, $outro));

        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded, 'format_output must return valid JSON');
        $this->assertArrayHasKey('chatoutput', $decoded);

        $texts = [];
        foreach ($decoded['chatoutput'] as $entry) {
            $texts[$entry['type']] = $entry['text'];
        }

        foreach ($introcontains as $expected) {
            $this->assertStringContainsString($expected, $texts['intro']);
        }
        foreach ($intronotcontains as $notexpected) {
            $this->assertStringNotContainsString($notexpected, $texts['intro']);
        }

        // For cases with an outro containing list markers, verify the outro HTML too.
        if (!empty($outro) && str_contains($outro, "\n-")) {
            $this->assertStringContainsString('<ul>', $texts['outro']);
            $this->assertStringContainsString('<li>', $texts['outro']);
        }
    }

    /**
     * Test that formelements are passed through correctly alongside the chatoutput.
     *
     * @covers \aipurpose_agent\purpose::format_output
     */
    public function test_format_output_preserves_formelements(): void {
        $this->resetAfterTest();

        $formelements = [
            [
                'id' => 'id_name',
                'name' => 'name',
                'newValue' => 'My Course',
                'explanation' => 'A descriptive name.',
            ],
        ];

        $purpose = new purpose();
        $result = $purpose->format_output(
            $this->build_llm_response("Vorschläge:\n- Kursname anpassen.", '', $formelements)
        );
        $decoded = json_decode($result, true);

        $this->assertNotEmpty($decoded['formelements']);
        $this->assertEquals('id_name', $decoded['formelements'][0]['id']);
        $this->assertStringContainsString('<ul>', $decoded['chatoutput'][0]['text']);
    }

    /**
     * Data provider for error and edge-case handling in format_output.
     *
     * Each case provides the raw input string and the expected decoded output array.
     *
     * @return array
     */
    public static function error_output_provider(): array {
        // The error output returned when JSON is valid does not have the correct structure.
        $erroroutput = [
            'formelements' => [],
            'chatoutput' => [
                ['type' => 'intro', 'text' => get_string('error_unusuableresponse', 'aipurpose_agent')],
                ['type' => 'outro', 'text' => ''],
            ],
        ];

        return [
            'no_json_found_in_plain_text' => [
                'input' => 'This is plain text without any JSON.',
                'expected' => [
                    'formelements' => [],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => "<p>This is plain text without any JSON.</p>\n"],
                        ['type' => 'outro', 'text' => ''],
                    ],
                ],
            ],
            'invalid_json_syntax' => [
                'input' => '{invalid json: content here}',
                'expected' => [
                    'formelements' => [],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => "<p>{invalid json: content here}</p>\n"],
                        ['type' => 'outro', 'text' => ''],
                    ],
                ],
            ],
            'empty_json_object' => [
                'input' => '{}',
                'expected' => [
                    'formelements' => [],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => "<p>{}</p>\n"],
                        ['type' => 'outro', 'text' => ''],
                    ],
                ],
            ],
            'json_array_instead_of_object' => [
                'input' => '[1, 2, 3]',
                'expected' => [
                    'formelements' => [],
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => "<p>[1, 2, 3]</p>\n"],
                        ['type' => 'outro', 'text' => ''],
                    ],
                ],
            ],
            'missing_formelements_key' => [
                'input' => json_encode([
                    'chatoutput' => [
                        ['type' => 'intro', 'text' => 'Hello.'],
                        ['type' => 'outro', 'text' => ''],
                    ],
                ]),
                'expected' => $erroroutput,
            ],
            'missing_chatoutput_key' => [
                'input' => json_encode([
                    'formelements' => [],
                ]),
                'expected' => $erroroutput,
            ],
            'missing_both_required_keys' => [
                'input' => '{"somekey": "somevalue"}',
                'expected' => $erroroutput,
            ],
        ];
    }

    /**
     * Test error and edge-case handling in format_output.
     *
     * @dataProvider error_output_provider
     * @covers \aipurpose_agent\purpose::format_output
     * @param string $input The raw input string passed to format_output.
     * @param array $expected The full expected output array.
     */
    public function test_format_output_error_and_edge_cases(string $input, array $expected): void {
        $purpose = new purpose();
        $result = $purpose->format_output($input);

        $this->assertJsonStringEqualsJsonString(json_encode($expected), $result);
    }
}
